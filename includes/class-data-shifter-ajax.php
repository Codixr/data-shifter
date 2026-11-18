<?php
/**
 * Data Shifter AJAX Handler Class.
 *
 * Registers and handles every AJAX action for the plugin, including
 * multi-step export, drag-and-drop chunked uploads, and resilient client-side batch imports.
 *
 * @package Data_Shifter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Every public handler in this class calls verify_request() (nonce plus
 * manage_options check) or the same checks inline before reading request data.
 * PHPCS cannot follow that helper, so the nonce sniff is disabled for this file.
 *
 * phpcs:disable WordPress.Security.NonceVerification.Missing
 * phpcs:disable WordPress.Security.NonceVerification.Recommended
 */

/**
 * Class Data_Shifter_Ajax
 */
class Data_Shifter_Ajax {

	/**
	 * Constructor — register all wp_ajax_ hooks.
	 */
	public function __construct() {
		$actions = array(
			'data_shifter_get_post_types',
			'data_shifter_get_taxonomies',
			'data_shifter_get_fields',
			'data_shifter_preview_export',
			'data_shifter_run_export',
			'data_shifter_download_export',
			'data_shifter_upload_import',
			'data_shifter_upload_chunk',
			'data_shifter_init_import',
			'data_shifter_import_batch',
			'data_shifter_finalize_import',
			'data_shifter_run_import',
			'data_shifter_import_progress',
		);

		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_' . $action, array( $this, $action ) );
		}
	}

	/*
	 * Security & Path Helpers
	 */

	/**
	 * Verify the AJAX nonce and user capability.
	 *
	 * Sends a JSON error and dies on failure.
	 *
	 * @return void
	 */
	private function verify_request() {
		if ( ! check_ajax_referer( 'data_shifter_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'data-shifter' ) ), 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'data-shifter' ) ), 403 );
		}
	}

	/**
	 * Return the path to the plugin temp directory, creating it if needed.
	 *
	 * @return string Absolute path with trailing slash.
	 */
	private function get_temp_dir() {
		$upload_dir = wp_upload_dir();
		$temp       = trailingslashit( $upload_dir['basedir'] ) . 'data-shifter/temp/';
		if ( ! is_dir( $temp ) ) {
			wp_mkdir_p( $temp );
		}
		return $temp;
	}

	/**
	 * Validate that a file is inside the temp directory (prevents path traversal).
	 *
	 * @param string $filename Base filename.
	 * @return string|false Normalized absolute file path or false on failure.
	 */
	private function validate_temp_file( $filename ) {
		if ( empty( $filename ) ) {
			return false;
		}

		$temp_dir = $this->get_temp_dir();
		$filepath = $temp_dir . sanitize_file_name( $filename );

		if ( ! file_exists( $filepath ) ) {
			return false;
		}

		$norm_temp = wp_normalize_path( trailingslashit( (string) realpath( $temp_dir ) ) );
		$norm_file = wp_normalize_path( (string) realpath( $filepath ) );

		if ( empty( $norm_file ) || empty( $norm_temp ) || strpos( $norm_file, $norm_temp ) !== 0 ) {
			return false;
		}

		return $norm_file;
	}

	/**
	 * Move a standard (non-chunked) upload into the plugin temp directory.
	 *
	 * Uses wp_handle_upload() with the upload directory pointed at the plugin
	 * temp folder. The file extension is validated by the caller.
	 *
	 * @param array  $file      Entry from $_FILES.
	 * @param string $filename  Target file name.
	 * @param string $extension Validated file extension.
	 * @return bool True when the file is in place.
	 */
	private function move_upload_to_temp( $file, $filename, $extension ) {
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$temp_dir = untrailingslashit( $this->get_temp_dir() );
		$set_dir  = function ( $dirs ) use ( $temp_dir ) {
			$dirs['path']   = $temp_dir;
			$dirs['subdir'] = '';
			$dirs['url']    = trailingslashit( $dirs['baseurl'] ) . 'data-shifter/temp';
			return $dirs;
		};

		add_filter( 'upload_dir', $set_dir );
		$result = wp_handle_upload(
			$file,
			array(
				'test_form'                => false,
				'test_type'                => false,
				'mimes'                    => array( $extension => 'application/octet-stream' ),
				'unique_filename_callback' => function () use ( $filename ) {
					return $filename;
				},
			)
		);
		remove_filter( 'upload_dir', $set_dir );

		return is_array( $result ) && empty( $result['error'] ) && ! empty( $result['file'] );
	}

	/**
	 * Build a sanitized export config array from $_POST data.
	 *
	 * @return array
	 */
	private function build_export_config() {
		$config = array(
			'post_type'          => isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : 'post',
			'filters'            => array(
				'status'       => 'any',
				'date_from'    => '',
				'date_to'      => '',
				'taxonomy'     => '',
				'terms'        => array(),
				'search'       => '',
				'specific_ids' => array(),
				'author'       => 0,
			),
			'fields'             => array(),
			'include_meta'       => true,
			'include_taxonomies' => true,
			'include_media'      => true,
			'include_gallery'    => true, // Default to true to capture all CPT images.
			'include_comments'   => false,
			'format'             => 'json',
		);

		// Filters.
		if ( ! empty( $_POST['filters'] ) ) {
			$raw_filters = wp_unslash( $_POST['filters'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each filter is sanitized below.
			if ( is_string( $raw_filters ) ) {
				$decoded = json_decode( $raw_filters, true );
				if ( is_array( $decoded ) ) {
					$raw_filters = $decoded;
				}
			}

			if ( is_array( $raw_filters ) ) {
				$f = $raw_filters;

				if ( isset( $f['status'] ) && '' !== $f['status'] ) {
					$config['filters']['status'] = sanitize_text_field( $f['status'] );
				}
				if ( isset( $f['date_from'] ) ) {
					$config['filters']['date_from'] = sanitize_text_field( $f['date_from'] );
				}
				if ( isset( $f['date_to'] ) ) {
					$config['filters']['date_to'] = sanitize_text_field( $f['date_to'] );
				}
				if ( isset( $f['taxonomy'] ) ) {
					$config['filters']['taxonomy'] = sanitize_text_field( $f['taxonomy'] );
				}
				if ( isset( $f['terms'] ) ) {
					$terms                      = is_array( $f['terms'] ) ? $f['terms'] : explode( ',', $f['terms'] );
					$config['filters']['terms'] = array_filter( array_map( 'absint', $terms ) );
				}
				if ( isset( $f['search'] ) ) {
					$config['filters']['search'] = sanitize_text_field( $f['search'] );
				}
				if ( ! empty( $f['specific_ids'] ) ) {
					$ids                               = is_array( $f['specific_ids'] ) ? $f['specific_ids'] : explode( ',', $f['specific_ids'] );
					$config['filters']['specific_ids'] = array_filter( array_map( 'absint', $ids ) );
				}
				if ( isset( $f['author'] ) ) {
					$config['filters']['author'] = absint( $f['author'] );
				}
			}
		}

		// Fields.
		if ( ! empty( $_POST['fields'] ) ) {
			$raw_fields = wp_unslash( $_POST['fields'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized with sanitize_text_field below.
			if ( is_string( $raw_fields ) ) {
				$decoded = json_decode( $raw_fields, true );
				if ( is_array( $decoded ) ) {
					$raw_fields = $decoded;
				}
			}
			if ( is_array( $raw_fields ) ) {
				$config['fields'] = array_map( 'sanitize_text_field', $raw_fields );
			}
		}

		// Boolean toggles.
		$config['include_meta']       = isset( $_POST['include_meta'] ) ? filter_var( wp_unslash( $_POST['include_meta'] ), FILTER_VALIDATE_BOOLEAN ) : true;
		$config['include_taxonomies'] = isset( $_POST['include_taxonomies'] ) ? filter_var( wp_unslash( $_POST['include_taxonomies'] ), FILTER_VALIDATE_BOOLEAN ) : true;
		$config['include_media']      = isset( $_POST['include_media'] ) ? filter_var( wp_unslash( $_POST['include_media'] ), FILTER_VALIDATE_BOOLEAN ) : true;
		$config['include_gallery']    = isset( $_POST['include_gallery'] ) ? filter_var( wp_unslash( $_POST['include_gallery'] ), FILTER_VALIDATE_BOOLEAN ) : true;
		$config['include_comments']   = isset( $_POST['include_comments'] ) ? filter_var( wp_unslash( $_POST['include_comments'] ), FILTER_VALIDATE_BOOLEAN ) : false;

		// Format (json, zip, csv, xml).
		if ( ! empty( $_POST['format'] ) ) {
			$format = sanitize_text_field( wp_unslash( $_POST['format'] ) );
			if ( in_array( $format, array( 'json', 'zip', 'csv', 'xml' ), true ) ) {
				$config['format'] = $format;
			}
		}

		return $config;
	}

	/*
	 * AJAX handlers
	 */

	/**
	 * Return all registered public post types with counts.
	 *
	 * @return void
	 */
	public function data_shifter_get_post_types() {
		$this->verify_request();

		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		if ( ! isset( $post_types['attachment'] ) ) {
			$attachment = get_post_type_object( 'attachment' );
			if ( $attachment ) {
				$post_types['attachment'] = $attachment;
			}
		}

		$data = array();
		foreach ( $post_types as $pt ) {
			$counts = wp_count_posts( $pt->name );
			$total  = 0;
			if ( is_object( $counts ) ) {
				foreach ( $counts as $status_count ) {
					$total += (int) $status_count;
				}
			}
			$data[] = array(
				'name'  => $pt->name,
				'label' => $pt->labels->name,
				'count' => $total,
			);
		}

		wp_send_json_success( $data );
	}

	/**
	 * Return taxonomies (with terms) for a given post type.
	 *
	 * @return void
	 */
	public function data_shifter_get_taxonomies() {
		$this->verify_request();

		$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : '';

		if ( empty( $post_type ) || ! post_type_exists( $post_type ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post type.', 'data-shifter' ) ) );
		}

		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		$data       = array();

		foreach ( $taxonomies as $tax ) {
			$terms     = get_terms(
				array(
					'taxonomy'   => $tax->name,
					'hide_empty' => false,
				)
			);
			$term_list = array();

			if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$term_list[] = array(
						'id'    => $term->term_id,
						'name'  => $term->name,
						'slug'  => $term->slug,
						'count' => $term->count,
					);
				}
			}

			$data[] = array(
				'name'  => $tax->name,
				'label' => $tax->labels->name,
				'terms' => $term_list,
			);
		}

		wp_send_json_success( $data );
	}

	/**
	 * Return distinct meta keys for a given post type.
	 *
	 * @return void
	 */
	public function data_shifter_get_fields() {
		$this->verify_request();

		global $wpdb;

		$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : '';

		if ( empty( $post_type ) || ! post_type_exists( $post_type ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post type.', 'data-shifter' ) ) );
		}

		$allowed_internal = array(
			'_thumbnail_id',
			'_wp_page_template',
			'_wp_attached_file',
			'_edit_last',
			'_edit_lock',
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$all_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_key
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE p.post_type = %s
				 ORDER BY pm.meta_key ASC",
				$post_type
			)
		);

		$fields = array();
		if ( is_array( $all_keys ) ) {
			foreach ( $all_keys as $key ) {
				if ( strpos( $key, '_' ) !== 0 || in_array( $key, $allowed_internal, true ) ) {
					$fields[] = $key;
				}
			}
		}

		sort( $fields, SORT_STRING | SORT_FLAG_CASE );

		wp_send_json_success( $fields );
	}

	/**
	 * Preview export data (first 5 rows).
	 *
	 * @return void
	 */
	public function data_shifter_preview_export() {
		$this->verify_request();

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 60 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Long imports and exports need more time.
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@ini_set( 'memory_limit', '512M' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.memory_limit_Disallowed, Squiz.PHP.DiscouragedFunctions.Discouraged -- Large files need more memory; failures are harmless.

		try {
			$config   = $this->build_export_config();
			$exporter = new Data_Shifter_Exporter( $config );
			$preview  = $exporter->preview( 5 );

			wp_send_json_success(
				array(
					'items' => $preview,
					'total' => count( $preview ),
				)
			);
		} catch ( Throwable $t ) {
			wp_send_json_error( array( 'message' => $t->getMessage() ) );
		}
	}

	/**
	 * Run the full export, save to a temp file, and return the download URL.
	 *
	 * @return void
	 */
	public function data_shifter_run_export() {
		$this->verify_request();

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Long imports and exports need more time.
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@ini_set( 'memory_limit', '1024M' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.memory_limit_Disallowed, Squiz.PHP.DiscouragedFunctions.Discouraged -- Large files need more memory; failures are harmless.

		try {
			$config    = $this->build_export_config();
			$exporter  = new Data_Shifter_Exporter( $config );
			$format    = $config['format'];
			$extension = $format;
			$filename  = 'data-shifter-export-' . $config['post_type'] . '-' . gmdate( 'Y-m-d-His' ) . '.' . $extension;
			$temp_dir  = $this->get_temp_dir();
			$filepath  = $temp_dir . $filename;

			$res = $exporter->export_to_file( $filepath );

			if ( is_wp_error( $res ) ) {
				wp_send_json_error( array( 'message' => $res->get_error_message() ) );
			}

			if ( ! file_exists( $filepath ) || filesize( $filepath ) === 0 ) {
				wp_send_json_error( array( 'message' => __( 'Failed to write export file.', 'data-shifter' ) ) );
			}

			$download_url = add_query_arg(
				array(
					'action' => 'data_shifter_download_export',
					'file'   => rawurlencode( $filename ),
					'nonce'  => wp_create_nonce( 'data_shifter_nonce' ),
				),
				admin_url( 'admin-ajax.php' )
			);

			wp_send_json_success(
				array(
					'filename'     => $filename,
					'download_url' => $download_url,
					'total'        => $exporter->get_total_exported(),
					'format'       => $format,
				)
			);
		} catch ( Throwable $t ) {
			wp_send_json_error( array( 'message' => $t->getMessage() ) );
		}
	}

	/**
	 * Serve an export file for download, then delete it.
	 *
	 * @return void
	 */
	public function data_shifter_download_export() {
		if ( ! check_ajax_referer( 'data_shifter_nonce', 'nonce', false ) ) {
			wp_die( esc_html__( 'Security check failed.', 'data-shifter' ), 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'data-shifter' ), 403 );
		}

		$filename = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		if ( empty( $filename ) ) {
			wp_die( esc_html__( 'No file specified.', 'data-shifter' ), 400 );
		}

		$norm_file = $this->validate_temp_file( $filename );
		if ( ! $norm_file ) {
			wp_die( esc_html__( 'Invalid file path or file not found.', 'data-shifter' ), 404 );
		}

		$extension    = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$content_type = 'application/octet-stream';
		switch ( $extension ) {
			case 'zip':
				$content_type = 'application/zip';
				break;
			case 'json':
				$content_type = 'application/json; charset=utf-8';
				break;
			case 'csv':
				$content_type = 'text/csv; charset=utf-8';
				break;
			case 'xml':
				$content_type = 'application/xml; charset=utf-8';
				break;
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $norm_file ) );
		header( 'Content-Transfer-Encoding: binary' );

		readfile( $norm_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_delete_file( $norm_file );
		exit;
	}

	/**
	 * Handle file upload for import, parse it, and return a preview.
	 *
	 * @return void
	 */
	public function data_shifter_upload_import() {
		$this->verify_request();

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Long imports and exports need more time.
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@ini_set( 'memory_limit', '1024M' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.memory_limit_Disallowed, Squiz.PHP.DiscouragedFunctions.Discouraged -- Large files need more memory; failures are harmless.

		if ( empty( $_FILES['import_file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file was uploaded.', 'data-shifter' ) ) );
		}

		$file = $_FILES['import_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File array is validated below and handled by wp_handle_upload().

		if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== $file['error'] ) {
			$error_messages = array(
				UPLOAD_ERR_INI_SIZE   => __( 'File exceeds server upload size limit.', 'data-shifter' ),
				UPLOAD_ERR_FORM_SIZE  => __( 'File exceeds form upload size limit.', 'data-shifter' ),
				UPLOAD_ERR_PARTIAL    => __( 'File was only partially uploaded.', 'data-shifter' ),
				UPLOAD_ERR_NO_FILE    => __( 'No file was uploaded.', 'data-shifter' ),
				UPLOAD_ERR_NO_TMP_DIR => __( 'Server missing temporary folder.', 'data-shifter' ),
				UPLOAD_ERR_CANT_WRITE => __( 'Failed to write file to disk.', 'data-shifter' ),
				UPLOAD_ERR_EXTENSION  => __( 'A PHP extension stopped the upload.', 'data-shifter' ),
			);
			$msg            = isset( $error_messages[ $file['error'] ] ) ? $error_messages[ $file['error'] ] : __( 'Unknown upload error.', 'data-shifter' );
			wp_send_json_error( array( 'message' => $msg ) );
		}

		$original_name = sanitize_file_name( isset( $file['name'] ) ? $file['name'] : '' );
		$extension     = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
		$allowed       = array( 'json', 'zip', 'csv', 'xml' );

		if ( ! in_array( $extension, $allowed, true ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: comma-separated list of file extensions */
						__( 'Invalid file type. Allowed types: %s', 'data-shifter' ),
						implode( ', ', $allowed )
					),
				)
			);
		}

		$temp_dir  = $this->get_temp_dir();
		$filename  = 'data-shifter-import-' . gmdate( 'Y-m-d-His' ) . '-' . wp_generate_password( 6, false ) . '.' . $extension;
		$dest_path = $temp_dir . $filename;

		$moved = $this->move_upload_to_temp( $file, $filename, $extension );
		if ( ! $moved ) {
			wp_send_json_error( array( 'message' => __( 'Failed to move uploaded file.', 'data-shifter' ) ) );
		}

		try {
			$importer = new Data_Shifter_Importer( $dest_path, $extension );
			$parsed   = $importer->parse();

			if ( is_wp_error( $parsed ) ) {
				wp_delete_file( $dest_path );
				wp_send_json_error( array( 'message' => $parsed->get_error_message() ) );
			}

			$preview = $importer->get_preview( 10 );

			wp_send_json_success(
				array(
					'file'        => $filename,
					'format'      => $extension,
					'post_type'   => $importer->get_post_type(),
					'total_items' => $importer->get_total_items(),
					'fields'      => $importer->get_field_names(),
					'preview'     => $preview,
				)
			);
		} catch ( Throwable $t ) {
			wp_delete_file( $dest_path );
			wp_send_json_error( array( 'message' => 'Upload parse error: ' . $t->getMessage() ) );
		}
	}

	/**
	 * Handle chunked file uploads to support unlimited file sizes on any host.
	 *
	 * @return void
	 */
	public function data_shifter_upload_chunk() {
		$this->verify_request();

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Long imports and exports need more time.
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@ini_set( 'memory_limit', '1024M' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.memory_limit_Disallowed, Squiz.PHP.DiscouragedFunctions.Discouraged -- Large files need more memory; failures are harmless.

		$upload_id     = isset( $_POST['upload_id'] ) ? sanitize_file_name( wp_unslash( $_POST['upload_id'] ) ) : '';
		$chunk_index   = isset( $_POST['chunk_index'] ) ? absint( $_POST['chunk_index'] ) : 0;
		$total_chunks  = isset( $_POST['total_chunks'] ) ? absint( $_POST['total_chunks'] ) : 1;
		$original_name = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';

		if ( empty( $upload_id ) || empty( $original_name ) || $total_chunks < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid chunk upload parameters.', 'data-shifter' ) ) );
		}

		$extension = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
		$allowed   = array( 'json', 'zip', 'csv', 'xml' );
		if ( ! in_array( $extension, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid file extension.', 'data-shifter' ) ) );
		}

		if ( empty( $_FILES['chunk_data'] ) || ! isset( $_FILES['chunk_data']['error'], $_FILES['chunk_data']['tmp_name'] ) || UPLOAD_ERR_OK !== $_FILES['chunk_data']['error'] ) {
			wp_send_json_error( array( 'message' => __( 'Chunk upload failed on server.', 'data-shifter' ) ) );
		}

		$temp_dir   = $this->get_temp_dir();
		$chunk_file = $temp_dir . 'chunk_' . preg_replace( '/[^a-zA-Z0-9_-]/', '', $upload_id ) . '.tmp';

		// Chunks are streamed with native file functions because WP_Filesystem
		// cannot append to a file or read in buffered blocks.
		// phpcs:disable WordPress.WP.AlternativeFunctions
		$in = fopen( $_FILES['chunk_data']['tmp_name'], 'rb' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Temporary upload path from PHP.
		if ( ! $in ) {
			wp_send_json_error( array( 'message' => __( 'Failed to read uploaded chunk.', 'data-shifter' ) ) );
		}

		$out = fopen( $chunk_file, ( 0 === $chunk_index ) ? 'wb' : 'ab' );
		if ( ! $out ) {
			fclose( $in );
			wp_send_json_error( array( 'message' => __( 'Failed to write chunk to temporary storage.', 'data-shifter' ) ) );
		}

		while ( ! feof( $in ) ) {
			$buffer = fread( $in, 65536 );
			if ( false !== $buffer ) {
				fwrite( $out, $buffer );
			}
		}

		fclose( $in );
		fclose( $out );

		if ( $chunk_index < ( $total_chunks - 1 ) ) {
			wp_send_json_success(
				array(
					'chunk'    => $chunk_index,
					'received' => true,
				)
			);
		}

		// Final chunk reached: Assemble into final import file.
		$final_filename = 'data-shifter-import-' . gmdate( 'Y-m-d-His' ) . '-' . wp_generate_password( 6, false ) . '.' . $extension;
		$dest_path      = $temp_dir . $final_filename;

		// Same-directory move of a plugin temp file.
		if ( ! rename( $chunk_file, $dest_path ) ) {
			wp_send_json_error( array( 'message' => __( 'Failed to assemble uploaded chunks.', 'data-shifter' ) ) );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions

		try {
			$importer = new Data_Shifter_Importer( $dest_path, $extension );
			$parsed   = $importer->parse();

			if ( is_wp_error( $parsed ) ) {
				wp_delete_file( $dest_path );
				wp_send_json_error( array( 'message' => $parsed->get_error_message() ) );
			}

			$preview = $importer->get_preview( 10 );

			wp_send_json_success(
				array(
					'file'        => $final_filename,
					'format'      => $extension,
					'post_type'   => $importer->get_post_type(),
					'total_items' => $importer->get_total_items(),
					'fields'      => $importer->get_field_names(),
					'preview'     => $preview,
				)
			);
		} catch ( Throwable $t ) {
			wp_delete_file( $dest_path );
			wp_send_json_error( array( 'message' => 'Upload parse error: ' . $t->getMessage() ) );
		}
	}

	/*
	 * CLIENT-DRIVEN CHUNKED/BATCH IMPORT ENDPOINTS
	 */

	/**
	 * Initialize a batch import session.
	 *
	 * @return void
	 */
	public function data_shifter_init_import() {
		$this->verify_request();

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Long imports and exports need more time.
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@ini_set( 'memory_limit', '1024M' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.memory_limit_Disallowed, Squiz.PHP.DiscouragedFunctions.Discouraged -- Large files need more memory; failures are harmless.

		$filename = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '';
		$format   = isset( $_POST['format'] ) ? sanitize_text_field( wp_unslash( $_POST['format'] ) ) : '';

		if ( empty( $filename ) || empty( $format ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing file or format.', 'data-shifter' ) ) );
		}

		$norm_file = $this->validate_temp_file( $filename );
		if ( ! $norm_file ) {
			wp_send_json_error( array( 'message' => __( 'Import file not found or path invalid.', 'data-shifter' ) ) );
		}

		$import_config = array(
			'post_type'        => isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : 'post',
			'update_existing'  => ! empty( $_POST['update_existing'] ),
			'duplicate_action' => $this->get_duplicate_action(),
			'match_by'         => isset( $_POST['match_by'] ) ? sanitize_text_field( wp_unslash( $_POST['match_by'] ) ) : 'title',
			'field_mapping'    => array(),
			'batch_size'       => isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 3,
			'import_media'     => ! empty( $_POST['import_media'] ),
			'import_taxonomy'  => ! empty( $_POST['import_taxonomy'] ),
		);

		if ( ! empty( $_POST['field_mapping'] ) && is_array( $_POST['field_mapping'] ) ) {
			$raw_mapping = wp_unslash( $_POST['field_mapping'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Keys and values are sanitized below.
			foreach ( $raw_mapping as $source => $target ) {
				$clean_source = sanitize_text_field( $source );
				$clean_target = sanitize_text_field( $target );
				if ( ! empty( $clean_source ) && ! empty( $clean_target ) ) {
					$import_config['field_mapping'][ $clean_source ] = $clean_target;
				}
			}
		}

		try {
			$importer = new Data_Shifter_Importer( $norm_file, $format );
			$parsed   = $importer->parse();

			if ( is_wp_error( $parsed ) ) {
				wp_send_json_error( array( 'message' => $parsed->get_error_message() ) );
			}

			$session = $importer->create_session( $import_config );

			wp_send_json_success(
				array(
					'session_id'  => $session['session_id'],
					'total_items' => $session['total_items'],
					'post_type'   => $session['post_type'],
				)
			);
		} catch ( Throwable $t ) {
			wp_send_json_error( array( 'message' => 'Init import error: ' . $t->getMessage() ) );
		}
	}

	/**
	 * Process a single batch of items for an active import session.
	 *
	 * @return void
	 */
	public function data_shifter_import_batch() {
		$this->verify_request();

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 180 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Long imports and exports need more time.
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@ini_set( 'memory_limit', '1024M' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.memory_limit_Disallowed, Squiz.PHP.DiscouragedFunctions.Discouraged -- Large files need more memory; failures are harmless.

		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		$offset     = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$batch_size = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 3;

		if ( empty( $session_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing session ID.', 'data-shifter' ) ) );
		}

		$post_config = array(
			'post_type'        => isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : '',
			'update_existing'  => ! empty( $_POST['update_existing'] ),
			'duplicate_action' => $this->get_duplicate_action(),
			'match_by'         => isset( $_POST['match_by'] ) ? sanitize_text_field( wp_unslash( $_POST['match_by'] ) ) : 'title',
			'import_media'     => ! empty( $_POST['import_media'] ),
			'import_taxonomy'  => ! empty( $_POST['import_taxonomy'] ),
		);

		try {
			$res = Data_Shifter_Importer::process_batch( $session_id, $offset, $batch_size, $post_config );
			if ( is_wp_error( $res ) ) {
				wp_send_json_error( array( 'message' => $res->get_error_message() ) );
			}
			wp_send_json_success( $res );
		} catch ( Throwable $t ) {
			wp_send_json_error( array( 'message' => 'Batch processing error: ' . $t->getMessage() ) );
		}
	}

	/**
	 * Finalize an import session (resolve hierarchies, cleanup temp).
	 *
	 * @return void
	 */
	public function data_shifter_finalize_import() {
		$this->verify_request();

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 180 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Long imports and exports need more time.
		}

		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		if ( empty( $session_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing session ID.', 'data-shifter' ) ) );
		}

		try {
			$res = Data_Shifter_Importer::finalize_session( $session_id );
			if ( is_wp_error( $res ) ) {
				wp_send_json_error( array( 'message' => $res->get_error_message() ) );
			}
			wp_send_json_success( $res );
		} catch ( Throwable $t ) {
			wp_send_json_error( array( 'message' => 'Finalize error: ' . $t->getMessage() ) );
		}
	}

	/**
	 * Run the full import in a single request (backward compatibility / fallback).
	 *
	 * @return void
	 */
	public function data_shifter_run_import() {
		$this->verify_request();

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 600 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Long imports and exports need more time.
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@ini_set( 'memory_limit', '1024M' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.memory_limit_Disallowed, Squiz.PHP.DiscouragedFunctions.Discouraged -- Large files need more memory; failures are harmless.

		$filename = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '';
		$format   = isset( $_POST['format'] ) ? sanitize_text_field( wp_unslash( $_POST['format'] ) ) : '';

		if ( empty( $filename ) || empty( $format ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing file or format.', 'data-shifter' ) ) );
		}

		$norm_file = $this->validate_temp_file( $filename );
		if ( ! $norm_file ) {
			wp_send_json_error( array( 'message' => __( 'Import file not found.', 'data-shifter' ) ) );
		}

		$import_config = array(
			'post_type'        => isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : 'post',
			'update_existing'  => ! empty( $_POST['update_existing'] ),
			'duplicate_action' => $this->get_duplicate_action(),
			'match_by'         => isset( $_POST['match_by'] ) ? sanitize_text_field( wp_unslash( $_POST['match_by'] ) ) : 'title',
			'field_mapping'    => array(),
			'batch_size'       => isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 50,
			'import_media'     => ! empty( $_POST['import_media'] ),
			'import_taxonomy'  => ! empty( $_POST['import_taxonomy'] ),
		);

		try {
			$importer = new Data_Shifter_Importer( $norm_file, $format );
			$parsed   = $importer->parse();

			if ( is_wp_error( $parsed ) ) {
				wp_send_json_error( array( 'message' => $parsed->get_error_message() ) );
			}

			$results = $importer->import( $import_config );

			if ( is_wp_error( $results ) ) {
				wp_send_json_error( array( 'message' => $results->get_error_message() ) );
			}

			wp_delete_file( $norm_file );

			wp_send_json_success(
				array(
					'created'  => $results['created'],
					'updated'  => $results['updated'],
					'skipped'  => $results['skipped'],
					'errors'   => $results['errors'],
					'messages' => $results['messages'],
				)
			);
		} catch ( Throwable $t ) {
			wp_send_json_error( array( 'message' => 'Import execution error: ' . $t->getMessage() ) );
		}
	}

	/**
	 * Return import progress for polling fallback.
	 *
	 * @return void
	 */
	public function data_shifter_import_progress() {
		$this->verify_request();

		$progress = get_transient( 'data_shifter_import_progress' );

		if ( false === $progress ) {
			$progress = array(
				'total'     => 0,
				'processed' => 0,
				'created'   => 0,
				'updated'   => 0,
				'skipped'   => 0,
				'errors'    => 0,
				'status'    => 'idle',
			);
		}

		wp_send_json_success( $progress );
	}

	/**
	 * Read the duplicate handling choice from the request.
	 *
	 * The nonce is checked in verify_request() before this runs.
	 *
	 * @return string One of 'skip', 'update' or 'create', or an empty string when not sent.
	 */
	private function get_duplicate_action() {
		$action = isset( $_POST['duplicate_action'] ) ? sanitize_key( wp_unslash( $_POST['duplicate_action'] ) ) : '';
		return in_array( $action, array( 'skip', 'update', 'create' ), true ) ? $action : '';
	}
}
