<?php
/**
 * Data Shifter Importer Class
 *
 * Handles parsing and importing data from JSON, CSV, XML, and ZIP formats into WordPress
 * with full support for client-side batching, local-to-live domain rewrites,
 * deep custom field media extraction, and hierarchical relationship resolution.
 *
 * @package Codixr_Data_Shifter
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Import and export files can be very large, so they are streamed in blocks
 * with native PHP file functions on files inside the plugin temp folder.
 * WP_Filesystem has no streaming or append API, so these sniffs are disabled
 * for this file.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
 */

/*
 * Exact-match lookups by post title or slug, and direct updates of imported
 * post fields that wp_update_post() would otherwise rewrite, use $wpdb with
 * prepared statements. Results are used once per item, so no caching is used.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 */

/**
 * Class Codixr_Data_Shifter_Importer
 */
class Codixr_Data_Shifter_Importer {

	/**
	 * Path to the file being imported.
	 *
	 * @var string
	 */
	protected $file_path;

	/**
	 * File format: 'json', 'csv', 'xml', or 'zip'.
	 *
	 * @var string
	 */
	protected $format;

	/**
	 * Parsed items ready for import.
	 *
	 * @var array
	 */
	protected $items = array();

	/**
	 * Detected post type.
	 *
	 * @var string
	 */
	protected $post_type = 'post';

	/**
	 * Source site URL from export metadata (e.g. http://sitename.local).
	 *
	 * @var string
	 */
	protected $source_site_url = '';

	/**
	 * Source upload URL from export metadata (e.g. http://sitename.local/wp-content/uploads).
	 *
	 * @var string
	 */
	protected $source_upload_url = '';

	/**
	 * Detected field names.
	 *
	 * @var array
	 */
	protected $field_names = array();

	/**
	 * Total items in the import file.
	 *
	 * @var int
	 */
	protected $total_items = 0;

	/**
	 * Map of old post ID to newly created post ID.
	 *
	 * @var array
	 */
	protected $id_map = array();

	/**
	 * Map of post slug to new post ID.
	 *
	 * @var array
	 */
	protected $slug_map = array();

	/**
	 * Map of post title to new post ID.
	 *
	 * @var array
	 */
	protected $title_map = array();

	/**
	 * Queue of items needing parent post resolution.
	 *
	 * @var array
	 */
	protected $parent_queue = array();

	/**
	 * Extracted directory path if ZIP package was parsed.
	 *
	 * @var string
	 */
	protected $extracted_dir = '';

	/**
	 * Constructor.
	 *
	 * @param string $file_path Absolute path to the import file.
	 * @param string $format    File format ('json', 'csv', 'xml', or 'zip').
	 */
	public function __construct( $file_path, $format = 'json' ) {
		$this->file_path = $file_path;
		$this->format    = strtolower( trim( $format ) );
	}

	/**
	 * Parse the uploaded file according to its format.
	 *
	 * @return true|WP_Error
	 */
	public function parse() {
		if ( ! file_exists( $this->file_path ) ) {
			return new WP_Error( 'file_not_found', __( 'Import file not found.', 'data-shifter' ) );
		}

		switch ( $this->format ) {
			case 'zip':
				return $this->parse_zip();

			case 'json':
				return $this->parse_json();

			case 'csv':
				return $this->parse_csv();

			case 'xml':
				return $this->parse_xml();

			default:
				return new WP_Error( 'invalid_format', __( 'Unsupported file format.', 'data-shifter' ) );
		}
	}

	/**
	 * Parse a ZIP package export file.
	 *
	 * @return true|WP_Error
	 */
	protected function parse_zip() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_unsupported', __( 'ZipArchive is not enabled on this server.', 'data-shifter' ) );
		}

		$extract_to = Codixr_Data_Shifter_Files::get_temp_dir() . 'zip_' . md5( $this->file_path ) . '/';

		$zip = new ZipArchive();
		if ( true !== $zip->open( $this->file_path ) ) {
			return new WP_Error( 'zip_open_failed', __( 'Could not open ZIP package.', 'data-shifter' ) );
		}

		// Check every entry before anything is written: only data.json and media
		// files inside media/ are allowed. Executable types and path traversal
		// reject the whole package.
		$entries = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property.
			$name  = (string) $zip->getNameIndex( $i );
			$valid = Codixr_Data_Shifter_Files::validate_zip_entry( $name );
			if ( is_wp_error( $valid ) ) {
				$zip->close();
				return $valid;
			}
			if ( '/' !== substr( $name, -1 ) ) {
				$entries[] = $name;
			}
		}

		if ( ! is_dir( $extract_to . 'media' ) ) {
			wp_mkdir_p( $extract_to . 'media' );
		}

		// Extract only the validated entries, one by one.
		foreach ( $entries as $name ) {
			$in = $zip->getStream( $name );
			if ( ! $in ) {
				continue;
			}
			$out = fopen( $extract_to . $name, 'wb' );
			if ( $out ) {
				stream_copy_to_stream( $in, $out );
				fclose( $out );
			}
			fclose( $in );
		}
		$zip->close();

		$this->extracted_dir = $extract_to;

		$json_file = $extract_to . 'data.json';
		if ( ! file_exists( $json_file ) ) {
			return new WP_Error( 'invalid_zip_package', __( 'The ZIP package does not contain data.json.', 'data-shifter' ) );
		}

		$content = file_get_contents( $json_file );
		$data    = json_decode( $content, true );
		unset( $content );

		if ( ! is_array( $data ) || ! isset( $data['items'] ) ) {
			return new WP_Error( 'invalid_zip_json', __( 'The data.json inside the ZIP package is invalid.', 'data-shifter' ) );
		}

		$this->source_site_url   = ! empty( $data['site_url'] ) ? esc_url_raw( $data['site_url'] ) : '';
		$this->source_upload_url = ! empty( $data['upload_url'] ) ? esc_url_raw( $data['upload_url'] ) : '';
		$this->post_type         = ! empty( $data['post_type'] ) ? sanitize_key( $data['post_type'] ) : 'post';
		$this->items             = $data['items'];
		$this->total_items       = count( $this->items );

		// Media files are read from the server-side extraction folder only (see get_zip_media_path()).

		$this->collect_field_names_from_items();

		return true;
	}

	/**
	 * Parse a JSON export file.
	 *
	 * @return true|WP_Error
	 */
	protected function parse_json() {
		$content = file_get_contents( $this->file_path );
		if ( empty( $content ) ) {
			return new WP_Error( 'empty_file', __( 'The JSON file is empty.', 'data-shifter' ) );
		}

		// Clean potential UTF-8 issues.
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$content = mb_convert_encoding( $content, 'UTF-8', 'UTF-8' );
		}

		$data = json_decode( $content, true );
		unset( $content );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			return new WP_Error(
				'invalid_json',
				sprintf(
					/* translators: %s: JSON parser error message */
					__( 'Invalid JSON format: %s', 'data-shifter' ),
					json_last_error_msg()
				)
			);
		}

		// Handle Data Shifter format.
		if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
			$this->items             = $data['items'];
			$this->post_type         = ! empty( $data['post_type'] ) ? sanitize_key( $data['post_type'] ) : 'post';
			$this->source_site_url   = ! empty( $data['site_url'] ) ? esc_url_raw( $data['site_url'] ) : '';
			$this->source_upload_url = ! empty( $data['upload_url'] ) ? esc_url_raw( $data['upload_url'] ) : '';
			$this->total_items       = count( $this->items );

			if ( ! empty( $data['fields_included'] ) && is_array( $data['fields_included'] ) ) {
				$this->field_names = $data['fields_included'];
			} else {
				$this->collect_field_names_from_items();
			}
		} else {
			// Direct array of items.
			$this->items       = $data;
			$this->total_items = count( $this->items );
			$this->collect_field_names_from_items();
		}
		unset( $data );

		return true;
	}

	/**
	 * Parse a CSV export file.
	 *
	 * @return true|WP_Error
	 */
	protected function parse_csv() {
		$handle = fopen( $this->file_path, 'r' );
		if ( false === $handle ) {
			return new WP_Error( 'cannot_open_file', __( 'Cannot open CSV file.', 'data-shifter' ) );
		}

		// Remove UTF-8 BOM if present.
		$bom = fread( $handle, 3 );
		if ( "\xEF\xBB\xBF" !== $bom ) {
			rewind( $handle );
		}

		$headers = fgetcsv( $handle );
		if ( empty( $headers ) || ! is_array( $headers ) ) {
			fclose( $handle );
			return new WP_Error( 'invalid_csv', __( 'CSV header row is missing or invalid.', 'data-shifter' ) );
		}

		// Clean and trim headers.
		$headers           = array_map( 'trim', array_map( array( 'Codixr_Data_Shifter_Files', 'csv_unescape_cell' ), $headers ) );
		$this->field_names = $headers;

		$this->items = array();
		while ( ( $row = fgetcsv( $handle ) ) !== false ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard CSV read loop.
			if ( count( $row ) === 1 && null === $row[0] ) {
				continue;
			}

			$row_count  = count( $row );
			$head_count = count( $headers );
			if ( $row_count < $head_count ) {
				$row = array_pad( $row, $head_count, '' );
			} elseif ( $row_count > $head_count ) {
				$row = array_slice( $row, 0, $head_count );
			}

			$row           = array_map( array( 'Codixr_Data_Shifter_Files', 'csv_unescape_cell' ), $row );
			$row_data      = array_combine( $headers, $row );
			$this->items[] = $this->transform_csv_row_to_item( $row_data );
		}

		fclose( $handle );
		$this->total_items = count( $this->items );

		return true;
	}

	/**
	 * Transform a flat CSV row associative array into structured Data Shifter item format.
	 *
	 * @param array $row Associative array of column => value.
	 * @return array
	 */
	protected function transform_csv_row_to_item( array $row ) {
		$item = array(
			'post_data'      => array(),
			'post_author'    => array(),
			'meta'           => array(),
			'taxonomies'     => array(),
			'featured_image' => array(),
		);

		$field_map = array(
			'original id'       => 'ID',
			'original_id'       => 'ID',
			'id'                => 'ID',
			'title'             => 'post_title',
			'post_title'        => 'post_title',
			'content'           => 'post_content',
			'post_content'      => 'post_content',
			'excerpt'           => 'post_excerpt',
			'post_excerpt'      => 'post_excerpt',
			'status'            => 'post_status',
			'post_status'       => 'post_status',
			'date'              => 'post_date',
			'post_date'         => 'post_date',
			'slug'              => 'post_name',
			'post_name'         => 'post_name',
			'parent slug'       => 'post_parent_slug',
			'post_parent_slug'  => 'post_parent_slug',
			'parent title'      => 'post_parent_title',
			'post_parent_title' => 'post_parent_title',
			'parent id'         => 'post_parent',
			'post_parent'       => 'post_parent',
			'menu order'        => 'menu_order',
			'menu_order'        => 'menu_order',
			'post type'         => 'post_type',
			'post_type'         => 'post_type',
		);

		foreach ( $row as $key => $val ) {
			$norm_key = strtolower( trim( $key ) );

			if ( isset( $field_map[ $norm_key ] ) ) {
				$item['post_data'][ $field_map[ $norm_key ] ] = $val;
			} elseif ( in_array( $norm_key, array( 'author', 'post_author', 'author_login' ), true ) ) {
				$item['post_author'] = array( 'user_login' => $val );
			} elseif ( strpos( $norm_key, 'tax_' ) === 0 || strpos( $norm_key, 'taxonomy_' ) === 0 ) {
				$tax                        = preg_replace( '/^(tax_|taxonomy_)/', '', $norm_key );
				$terms                      = array_map( 'trim', explode( ',', $val ) );
				$item['taxonomies'][ $tax ] = array_filter( $terms );
			} elseif ( in_array( $norm_key, array( 'image', 'featured_image', 'featured image', 'thumbnail' ), true ) ) {
				if ( ! empty( $val ) ) {
					$item['featured_image'] = array( 'url' => $val );
				}
			} elseif ( strpos( $norm_key, 'meta_' ) === 0 ) {
				$meta_k                  = preg_replace( '/^meta_/', '', $key );
				$item['meta'][ $meta_k ] = self::safe_unserialize( $val );
			} else {
				$item['meta'][ $key ] = self::safe_unserialize( $val );
			}
		}

		return $item;
	}

	/**
	 * Parse an XML export file.
	 *
	 * @return true|WP_Error
	 */
	protected function parse_xml() {
		if ( ! function_exists( 'simplexml_load_file' ) ) {
			return new WP_Error( 'xml_unsupported', __( 'SimpleXML PHP extension is required to import XML files.', 'data-shifter' ) );
		}

		$xml = simplexml_load_file( $this->file_path, 'SimpleXMLElement', LIBXML_NOCDATA );
		if ( false === $xml ) {
			return new WP_Error( 'invalid_xml', __( 'Failed to parse XML file.', 'data-shifter' ) );
		}

		$this->items = array();

		if ( isset( $xml->items->item ) ) {
			foreach ( $xml->items->item as $xml_item ) {
				$this->items[] = $this->transform_xml_node_to_item( $xml_item );
			}
		} elseif ( isset( $xml->item ) ) {
			foreach ( $xml->item as $xml_item ) {
				$this->items[] = $this->transform_xml_node_to_item( $xml_item );
			}
		}

		$this->total_items = count( $this->items );
		$this->collect_field_names_from_items();

		return true;
	}

	/**
	 * Transform a SimpleXMLElement node to item array.
	 *
	 * @param SimpleXMLElement $node XML node.
	 * @return array
	 */
	protected function transform_xml_node_to_item( SimpleXMLElement $node ) {
		$json = wp_json_encode( $node );
		$arr  = json_decode( $json, true );

		$item = array(
			'post_data'      => array(),
			'post_author'    => array(),
			'meta'           => array(),
			'taxonomies'     => array(),
			'featured_image' => array(),
		);

		if ( isset( $arr['post_data'] ) && is_array( $arr['post_data'] ) ) {
			$item['post_data'] = $arr['post_data'];
		} elseif ( isset( $arr['title'] ) || isset( $arr['post_title'] ) ) {
			$item['post_data']['post_title']   = $arr['title'] ?? ( $arr['post_title'] ?? '' );
			$item['post_data']['post_content'] = $arr['content'] ?? ( $arr['post_content'] ?? '' );
			$item['post_data']['post_excerpt'] = $arr['excerpt'] ?? ( $arr['post_excerpt'] ?? '' );
			$item['post_data']['post_status']  = $arr['status'] ?? ( $arr['post_status'] ?? 'publish' );
			$item['post_data']['post_name']    = $arr['slug'] ?? ( $arr['post_name'] ?? '' );
		}

		if ( isset( $arr['post_author'] ) ) {
			$item['post_author'] = is_array( $arr['post_author'] ) ? $arr['post_author'] : array( 'user_login' => (string) $arr['post_author'] );
		}

		if ( isset( $arr['meta'] ) && is_array( $arr['meta'] ) ) {
			$item['meta'] = $arr['meta'];
		}

		if ( isset( $arr['taxonomies'] ) && is_array( $arr['taxonomies'] ) ) {
			$item['taxonomies'] = $arr['taxonomies'];
		}

		if ( isset( $arr['featured_image'] ) && is_array( $arr['featured_image'] ) ) {
			$item['featured_image'] = $arr['featured_image'];
		} elseif ( ! empty( $arr['featured_image'] ) && is_string( $arr['featured_image'] ) ) {
			$item['featured_image'] = array( 'url' => $arr['featured_image'] );
		}

		return $item;
	}

	/**
	 * Collect distinct field names across parsed items.
	 */
	protected function collect_field_names_from_items() {
		$fields = array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_date', 'post_name' );

		foreach ( array_slice( $this->items, 0, 50 ) as $item ) {
			if ( ! empty( $item['meta'] ) && is_array( $item['meta'] ) ) {
				foreach ( array_keys( $item['meta'] ) as $meta_key ) {
					if ( ! in_array( 'meta_' . $meta_key, $fields, true ) ) {
						$fields[] = 'meta_' . $meta_key;
					}
				}
			}
			if ( ! empty( $item['taxonomies'] ) && is_array( $item['taxonomies'] ) ) {
				foreach ( array_keys( $item['taxonomies'] ) as $tax_name ) {
					if ( ! in_array( 'tax_' . $tax_name, $fields, true ) ) {
						$fields[] = 'tax_' . $tax_name;
					}
				}
			}
		}

		$this->field_names = $fields;
	}

	/**
	 * Get preview list for UI.
	 *
	 * @param int $limit Number of preview rows.
	 * @return array
	 */
	public function get_preview( $limit = 10 ) {
		$preview_slice = array_slice( $this->items, 0, $limit );
		$formatted     = array();

		foreach ( $preview_slice as $item ) {
			$title   = '';
			$status  = 'publish';
			$date    = '';
			$excerpt = '';

			if ( ! empty( $item['post_data'] ) ) {
				$title   = $item['post_data']['post_title'] ?? '';
				$status  = $item['post_data']['post_status'] ?? 'publish';
				$date    = $item['post_data']['post_date'] ?? '';
				$excerpt = wp_trim_words( $item['post_data']['post_excerpt'] ?? ( $item['post_data']['post_content'] ?? '' ), 15 );
			} elseif ( ! empty( $item['Title'] ) ) {
				$title   = $item['Title'];
				$status  = $item['Status'] ?? 'publish';
				$date    = $item['Date'] ?? '';
				$excerpt = wp_trim_words( $item['Excerpt'] ?? ( $item['Content'] ?? '' ), 15 );
			}

			$image_url = '';
			if ( ! empty( $item['featured_image']['url'] ) ) {
				$image_url = $item['featured_image']['url'];
			}

			$formatted[] = array(
				'title'          => $title,
				'status'         => $status,
				'date'           => $date,
				'excerpt'        => $excerpt,
				'featured_image' => $image_url,
				'meta_count'     => ! empty( $item['meta'] ) ? count( $item['meta'] ) : 0,
			);
		}

		return $formatted;
	}

	/**
	 * Detected post type.
	 *
	 * @return string
	 */
	public function get_post_type() {
		return $this->post_type;
	}

	/**
	 * Number of parsed items.
	 *
	 * @return int
	 */
	public function get_total_items() {
		return $this->total_items;
	}

	/**
	 * Field names found in the file.
	 *
	 * @return array
	 */
	public function get_field_names() {
		return $this->field_names;
	}

	/**
	 * Parsed items.
	 *
	 * @return array
	 */
	public function get_items() {
		return $this->items;
	}

	/**
	 * Source site URL from the export file.
	 *
	 * @return string
	 */
	public function get_source_site_url() {
		return $this->source_site_url;
	}

	/**
	 * Source uploads URL from the export file.
	 *
	 * @return string
	 */
	public function get_source_upload_url() {
		return $this->source_upload_url;
	}

	/*
	 * SESSION-BASED CHUNK/BATCH IMPORT ENGINE
	 */

	/**
	 * Initialize an import session and save parsed data into a temporary session file.
	 *
	 * @param array $config Configuration settings.
	 * @return array Session info.
	 */
	public function create_session( array $config = array() ) {
		$temp_dir     = Codixr_Data_Shifter_Files::get_temp_dir();
		$session_id   = Codixr_Data_Shifter_Files::token();
		$session_file = $temp_dir . 'session_' . $session_id . '.json';

		$session_data = array(
			'session_id'        => $session_id,
			'user_id'           => get_current_user_id(),
			'file_path'         => $this->file_path,
			'format'            => $this->format,
			'post_type'         => $this->post_type,
			'source_site_url'   => $this->source_site_url,
			'source_upload_url' => $this->source_upload_url,
			'extracted_dir'     => $this->extracted_dir,
			'total_items'       => $this->total_items,
			'processed_items'   => 0,
			'created'           => 0,
			'updated'           => 0,
			'skipped'           => 0,
			'errors'            => 0,
			'messages'          => array(),
			'id_map'            => array(),
			'slug_map'          => array(),
			'title_map'         => array(),
			'parent_queue'      => array(),
			'items'             => $this->items,
			'config'            => $config,
		);

		file_put_contents( $session_file, wp_json_encode( $session_data ) );

		return array(
			'session_id'  => $session_id,
			'total_items' => $this->total_items,
			'post_type'   => $this->post_type,
		);
	}

	/**
	 * Run a single batch of items for a session.
	 *
	 * @param string $session_id  Session ID.
	 * @param int    $offset      Starting offset.
	 * @param int    $batch_size  Number of items to process in this request.
	 * @param array  $post_config Extra options from POST.
	 * @return array|WP_Error Batch progress results.
	 */
	public static function process_batch( $session_id, $offset = 0, $batch_size = 5, $post_config = array() ) {
		$session_file = self::get_session_file( $session_id );

		if ( ! $session_file || ! file_exists( $session_file ) ) {
			return new WP_Error( 'session_not_found', __( 'Import session expired or not found. Please upload again.', 'data-shifter' ) );
		}

		$content = file_get_contents( $session_file );
		$session = json_decode( $content, true );
		unset( $content );

		if ( ! is_array( $session ) || ! isset( $session['items'] ) ) {
			return new WP_Error( 'invalid_session_data', __( 'Corrupted session data.', 'data-shifter' ) );
		}
		if ( ! isset( $session['user_id'] ) || get_current_user_id() !== (int) $session['user_id'] ) {
			return new WP_Error( 'session_forbidden', __( 'This import session belongs to another user.', 'data-shifter' ) );
		}

		$config = wp_parse_args( $post_config, $session['config'] );
		$total  = (int) $session['total_items'];
		$items  = $session['items'];

		$batch_items = array_slice( $items, $offset, $batch_size, true );

		$importer_instance                    = new self( $session['file_path'], $session['format'] );
		$importer_instance->id_map            = $session['id_map'];
		$importer_instance->slug_map          = $session['slug_map'];
		$importer_instance->title_map         = $session['title_map'];
		$importer_instance->parent_queue      = $session['parent_queue'];
		$importer_instance->source_site_url   = $session['source_site_url'];
		$importer_instance->source_upload_url = $session['source_upload_url'];
		$importer_instance->extracted_dir     = $session['extracted_dir'];

		$created_this_batch = 0;
		$updated_this_batch = 0;
		$skipped_this_batch = 0;
		$errors_this_batch  = 0;
		$current_item_title = '';

		foreach ( $batch_items as $index => $item ) {
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Long imports need more time.
			}

			if ( ! empty( $item['post_data']['post_title'] ) ) {
				$current_item_title = $item['post_data']['post_title'];
			}

			$res = $importer_instance->import_single_item( $item, $config );

			if ( is_wp_error( $res ) ) {
				++$errors_this_batch;
				++$session['errors'];
				$session['messages'][] = sprintf(
					/* translators: 1: item number, 2: item title, 3: error message */
					__( 'Item #%1$d (%2$s): %3$s', 'data-shifter' ),
					$index + 1,
					esc_html( $current_item_title ? $current_item_title : 'Untitled' ),
					$res->get_error_message()
				);
			} elseif ( 'created' === $res ) {
				++$created_this_batch;
				++$session['created'];
			} elseif ( 'updated' === $res ) {
				++$updated_this_batch;
				++$session['updated'];
			} elseif ( 'skipped' === $res ) {
				++$skipped_this_batch;
				++$session['skipped'];
			}

			++$session['processed_items'];
		}

		// Save updated maps and queue back into session.
		$session['id_map']       = $importer_instance->id_map;
		$session['slug_map']     = $importer_instance->slug_map;
		$session['title_map']    = $importer_instance->title_map;
		$session['parent_queue'] = $importer_instance->parent_queue;

		file_put_contents( $session_file, wp_json_encode( $session ) );

		$new_offset  = $offset + count( $batch_items );
		$is_finished = ( $new_offset >= $total );

		return array(
			'session_id'    => $session_id,
			'offset'        => $new_offset,
			'total'         => $total,
			'processed'     => $session['processed_items'],
			'created'       => $session['created'],
			'updated'       => $session['updated'],
			'skipped'       => $session['skipped'],
			'errors'        => $session['errors'],
			'messages'      => $session['messages'],
			'current_title' => $current_item_title,
			'is_finished'   => $is_finished,
		);
	}

	/**
	 * Return the absolute path of a session file, or an empty string for an invalid ID.
	 *
	 * @param string $session_id Session ID from the browser.
	 * @return string
	 */
	protected static function get_session_file( $session_id ) {
		$session_id = Codixr_Data_Shifter_Files::sanitize_token( $session_id );
		if ( '' === $session_id ) {
			return '';
		}
		return Codixr_Data_Shifter_Files::get_temp_dir() . 'session_' . $session_id . '.json';
	}

	/**
	 * Resolve a media path from a ZIP package to a file inside this session's
	 * extraction folder.
	 *
	 * The folder comes from the server-side session, never from the import
	 * data, and the path must pass the same checks as ZIP entries.
	 *
	 * @param string $zip_path Relative path stored in data.json, for example media/photo.jpg.
	 * @return string Absolute path, or an empty string when invalid or missing.
	 */
	protected function get_zip_media_path( $zip_path ) {
		if ( empty( $this->extracted_dir ) || ! is_string( $zip_path ) || true !== Codixr_Data_Shifter_Files::validate_zip_entry( $zip_path ) || 0 !== strpos( $zip_path, 'media/' ) ) {
			return '';
		}
		$base = realpath( $this->extracted_dir );
		$file = realpath( path_join( $this->extracted_dir, $zip_path ) );
		if ( ! $base || ! $file || 0 !== strpos( wp_normalize_path( $file ), trailingslashit( wp_normalize_path( $base ) ) ) || ! is_file( $file ) ) {
			return '';
		}
		return $file;
	}

	/**
	 * Finalize an import session: resolve all hierarchical parent-child relationships,
	 * clean up temporary files, and return final summary.
	 *
	 * @param string $session_id Session ID.
	 * @return array|WP_Error Final metrics.
	 */
	public static function finalize_session( $session_id ) {
		$session_file = self::get_session_file( $session_id );

		if ( ! $session_file || ! file_exists( $session_file ) ) {
			return new WP_Error( 'session_not_found', __( 'Session file not found.', 'data-shifter' ) );
		}

		$content = file_get_contents( $session_file );
		$session = json_decode( $content, true );
		unset( $content );

		if ( is_array( $session ) && ( ! isset( $session['user_id'] ) || get_current_user_id() !== (int) $session['user_id'] ) ) {
			return new WP_Error( 'session_forbidden', __( 'This import session belongs to another user.', 'data-shifter' ) );
		}

		if ( is_array( $session ) ) {
			$importer_instance               = new self( $session['file_path'], $session['format'] );
			$importer_instance->id_map       = $session['id_map'];
			$importer_instance->slug_map     = $session['slug_map'];
			$importer_instance->title_map    = $session['title_map'];
			$importer_instance->parent_queue = $session['parent_queue'];

			// Resolve parent-child relationships across the complete dataset.
			$importer_instance->resolve_parent_relationships();

			// Delete extracted zip files if present.
			if ( ! empty( $session['extracted_dir'] ) && is_dir( $session['extracted_dir'] ) ) {
				self::delete_dir_recursive( $session['extracted_dir'] );
			}

			// Delete uploaded import file and session file.
			if ( ! empty( $session['file_path'] ) && file_exists( $session['file_path'] ) ) {
				wp_delete_file( $session['file_path'] );
			}
			wp_delete_file( $session_file );

			return array(
				'created'  => $session['created'],
				'updated'  => $session['updated'],
				'skipped'  => $session['skipped'],
				'errors'   => $session['errors'],
				'messages' => $session['messages'],
				'total'    => $session['total_items'],
			);
		}

		return new WP_Error( 'finalize_failed', __( 'Could not finalize session.', 'data-shifter' ) );
	}

	/**
	 * Unserialize a value from an import file without creating objects.
	 *
	 * Same result as maybe_unserialize() for arrays and scalars. Serialized
	 * objects are not instantiated, which blocks PHP object injection from
	 * untrusted import files.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	public static function safe_unserialize( $value ) {
		if ( is_string( $value ) && is_serialized( $value ) ) {
			return @unserialize( trim( $value ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Objects are disabled.
		}
		return $value;
	}

	/**
	 * Remove the folder that a ZIP package was extracted to, if any.
	 *
	 * @return void
	 */
	public function cleanup_extracted() {
		if ( '' !== $this->extracted_dir && is_dir( $this->extracted_dir ) ) {
			self::delete_dir_recursive( $this->extracted_dir );
		}
	}

	/**
	 * Recursively delete a directory and its contents.
	 *
	 * @param string $dir Path to directory.
	 * @return void
	 */
	public static function delete_dir_recursive( $dir ) {

		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			( is_dir( "$dir/$file" ) ) ? self::delete_dir_recursive( "$dir/$file" ) : wp_delete_file( "$dir/$file" );
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing an empty plugin temp folder.
	}

	/**
	 * Run full import in one pass (backward compatibility / CLI).
	 *
	 * @param array $config Import settings.
	 * @return array|WP_Error
	 */
	public function import( array $config ) {
		$sess = $this->create_session( $config );
		if ( is_wp_error( $sess ) ) {
			return $sess;
		}

		$session_id = $sess['session_id'];
		$offset     = 0;
		$batch_size = 20;

		while ( true ) {
			$res = self::process_batch( $session_id, $offset, $batch_size, $config );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			if ( $res['is_finished'] ) {
				break;
			}
			$offset = $res['offset'];
		}

		return self::finalize_session( $session_id );
	}

	/*
	 * SINGLE ITEM IMPORT LOGIC
	 */

	/**
	 * Import a single item.
	 *
	 * @param array $item   Single item data.
	 * @param array $config Configuration settings.
	 * @return string|WP_Error 'created'|'updated'|'skipped' or WP_Error.
	 */
	public function import_single_item( array $item, array $config ) {
		$post_data = isset( $item['post_data'] ) ? $item['post_data'] : array();

		// Determine target post type (preserve CPT from file if specified).
		$target_post_type = ! empty( $config['post_type'] ) ? $config['post_type'] : 'post';
		if ( ! empty( $post_data['post_type'] ) ) {
			$target_post_type = $post_data['post_type'];
		}

		$title = isset( $post_data['post_title'] ) ? sanitize_text_field( $post_data['post_title'] ) : '';
		if ( empty( $title ) && empty( $post_data['post_content'] ) ) {
			return new WP_Error( 'empty_content', __( 'Item has neither title nor content.', 'data-shifter' ) );
		}

		// Check for duplicate / existing post. "Always create new" skips the lookup.
		$duplicate_action = ! empty( $config['duplicate_action'] ) ? $config['duplicate_action'] : ( ! empty( $config['update_existing'] ) ? 'update' : 'skip' );
		$match_by         = ! empty( $config['match_by'] ) ? $config['match_by'] : 'title';
		$existing_id      = 'create' === $duplicate_action ? false : $this->find_existing_post( $item, $match_by, $target_post_type );

		if ( $existing_id && 'update' !== $duplicate_action ) {
			return 'skipped';
		}

		$old_id       = ! empty( $post_data['ID'] ) ? (int) $post_data['ID'] : 0;
		$parent_id    = ! empty( $post_data['post_parent'] ) ? (int) $post_data['post_parent'] : 0;
		$parent_slug  = ! empty( $post_data['post_parent_slug'] ) ? sanitize_title( $post_data['post_parent_slug'] ) : '';
		$parent_title = ! empty( $post_data['post_parent_title'] ) ? sanitize_text_field( $post_data['post_parent_title'] ) : '';

		// Prepare post array for wp_insert_post / wp_update_post.
		$postarr = array(
			'post_type'    => $target_post_type,
			'post_title'   => $title,
			'post_content' => isset( $post_data['post_content'] ) ? $post_data['post_content'] : '',
			'post_excerpt' => isset( $post_data['post_excerpt'] ) ? $post_data['post_excerpt'] : '',
			'post_status'  => isset( $post_data['post_status'] ) ? sanitize_key( $post_data['post_status'] ) : 'publish',
			'post_parent'  => 0, // Temporarily set to 0; resolved after all posts exist.
		);

		if ( ! empty( $post_data['post_name'] ) ) {
			$postarr['post_name'] = sanitize_title( $post_data['post_name'] );
		}
		if ( ! empty( $post_data['post_date'] ) ) {
			$postarr['post_date'] = $post_data['post_date'];
		}
		if ( isset( $post_data['menu_order'] ) ) {
			$postarr['menu_order'] = (int) $post_data['menu_order'];
		}
		if ( ! empty( $post_data['comment_status'] ) ) {
			$postarr['comment_status'] = $post_data['comment_status'];
		}
		if ( ! empty( $post_data['ping_status'] ) ) {
			$postarr['ping_status'] = $post_data['ping_status'];
		}

		// Resolve Author.
		$postarr['post_author'] = $this->resolve_author_id( $item );

		// Execute insert or update.
		if ( $existing_id ) {
			$postarr['ID'] = $existing_id;
			$post_id       = wp_update_post( $postarr, true );
			$action_type   = 'updated';
		} else {
			$post_id     = wp_insert_post( $postarr, true );
			$action_type = 'created';
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Record ID and slug mappings for hierarchy resolution.
		if ( $old_id > 0 ) {
			$this->id_map[ $old_id ] = $post_id;
		}
		if ( ! empty( $postarr['post_name'] ) ) {
			$this->slug_map[ $postarr['post_name'] ] = $post_id;
		}
		if ( ! empty( $title ) ) {
			$this->title_map[ $title ] = $post_id;
		}

		// Queue parent resolution if this item had a parent post.
		if ( $parent_id > 0 || ! empty( $parent_slug ) || ! empty( $parent_title ) ) {
			$this->parent_queue[] = array(
				'post_id'           => $post_id,
				'old_parent_id'     => $parent_id,
				'post_parent_slug'  => $parent_slug,
				'post_parent_title' => $parent_title,
			);
		}

		// Media maps for this specific item (old_url => new_live_url, old_id => new_live_id).
		$media_url_map = array();
		$media_id_map  = array();

		// 1. Process Featured Image.
		$import_media = ! empty( $config['import_media'] );
		if ( $import_media && ! empty( $item['featured_image']['url'] ) ) {
			$f_url   = esc_url_raw( $item['featured_image']['url'] );
			$f_base  = ! empty( $item['featured_image']['data'] ) ? $item['featured_image']['data'] : '';
			$f_oldid = ! empty( $item['featured_image']['id'] ) ? (int) $item['featured_image']['id'] : 0;

			// Handle ZIP extracted media file if available.
			if ( empty( $f_base ) && ! empty( $item['featured_image']['zip_path'] ) ) {
				$zip_fpath = $this->get_zip_media_path( $item['featured_image']['zip_path'] );
				if ( $zip_fpath ) {
					$bin = @file_get_contents( $zip_fpath ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing files are handled by the caller.
					if ( false !== $bin ) {
						$f_base = base64_encode( $bin ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Bundles image bytes inside export packages.
					}
				}
			}

			$extra = array(
				'title'       => $item['featured_image']['title'] ?? '',
				'alt'         => $item['featured_image']['alt'] ?? '',
				'caption'     => $item['featured_image']['caption'] ?? '',
				'description' => $item['featured_image']['description'] ?? '',
			);

			$attach_id = Codixr_Data_Shifter_Media::import_from_url( $f_url, $post_id, $f_base, $extra );
			if ( ! is_wp_error( $attach_id ) && $attach_id > 0 ) {
				set_post_thumbnail( $post_id, $attach_id );
				$new_feat_url = wp_get_attachment_url( $attach_id );
				if ( $new_feat_url ) {
					$media_url_map[ $f_url ] = $new_feat_url;
				}
				if ( $f_oldid > 0 ) {
					$media_id_map[ $f_oldid ] = $attach_id;
				}
			}
		}

		// 2. Process Media Gallery & Custom Field Media Pool.
		if ( $import_media && ! empty( $item['media_gallery'] ) && is_array( $item['media_gallery'] ) ) {
			foreach ( $item['media_gallery'] as $gallery_item ) {
				$g_url   = is_array( $gallery_item ) ? ( $gallery_item['url'] ?? '' ) : (string) $gallery_item;
				$g_base  = is_array( $gallery_item ) ? ( $gallery_item['data'] ?? '' ) : '';
				$g_oldid = is_array( $gallery_item ) && ! empty( $gallery_item['id'] ) ? (int) $gallery_item['id'] : 0;

				if ( empty( $g_url ) || ! filter_var( $g_url, FILTER_VALIDATE_URL ) ) {
					continue;
				}

				if ( empty( $g_base ) && ! empty( $gallery_item['zip_path'] ) ) {
					$zip_gpath = $this->get_zip_media_path( $gallery_item['zip_path'] );
					if ( $zip_gpath ) {
						$bin = @file_get_contents( $zip_gpath ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing files are handled by the caller.
						if ( false !== $bin ) {
							$g_base = base64_encode( $bin ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Bundles image bytes inside export packages.
						}
					}
				}

				$extra = array(
					'title'       => $gallery_item['title'] ?? '',
					'alt'         => $gallery_item['alt'] ?? '',
					'caption'     => $gallery_item['caption'] ?? '',
					'description' => $gallery_item['description'] ?? '',
				);

				$g_attach_id = Codixr_Data_Shifter_Media::import_from_url( $g_url, $post_id, $g_base, $extra );
				if ( ! is_wp_error( $g_attach_id ) && $g_attach_id > 0 ) {
					$new_g_url = wp_get_attachment_url( $g_attach_id );
					if ( $new_g_url ) {
						$media_url_map[ $g_url ] = $new_g_url;
					}
					if ( $g_oldid > 0 ) {
						$media_id_map[ $g_oldid ] = $g_attach_id;
					}
				}
			}
		}

		// 3. Replace all inline image URLs and IDs in post_content with Live URLs and Live IDs.
		if ( $import_media && ! empty( $postarr['post_content'] ) ) {
			$updated_content = $this->rewrite_content_media( $postarr['post_content'], $media_url_map, $media_id_map );
			if ( $updated_content !== $postarr['post_content'] ) {
				global $wpdb;
				$wpdb->update(
					$wpdb->posts,
					array( 'post_content' => $updated_content ),
					array( 'ID' => $post_id ),
					array( '%s' ),
					array( '%d' )
				);
				clean_post_cache( $post_id );
			}
		}

		// 4. Import & Transform Meta fields (including ACF images/galleries with Live URLs & IDs).
		if ( ! empty( $item['meta'] ) && is_array( $item['meta'] ) ) {
			$mapping = ! empty( $config['field_mapping'] ) ? (array) $config['field_mapping'] : array();
			$this->import_post_meta_with_rewrites( $post_id, $item['meta'], $mapping, $media_url_map, $media_id_map );
		}

		// 5. Import Taxonomies.
		if ( ! empty( $config['import_taxonomy'] ) && ! empty( $item['taxonomies'] ) && is_array( $item['taxonomies'] ) ) {
			$this->import_post_taxonomies( $post_id, $item['taxonomies'] );
		}

		// 6. Import Comments.
		if ( ! empty( $config['import_comments'] ) && ! empty( $item['comments'] ) && is_array( $item['comments'] ) ) {
			$this->import_post_comments( $post_id, $item['comments'] );
		}

		return $action_type;
	}

	/**
	 * Rewrite inline image URLs and wp-image-XXXX IDs in post content.
	 *
	 * @param string $content       HTML post content.
	 * @param array  $media_url_map Map of old URL => new live URL.
	 * @param array  $media_id_map  Map of old attachment ID => new live attachment ID.
	 * @return string
	 */
	protected function rewrite_content_media( $content, $media_url_map, $media_id_map ) {
		if ( empty( $content ) ) {
			return $content;
		}

		// 1. Replace explicit media URLs.
		foreach ( $media_url_map as $old_url => $new_url ) {
			if ( ! empty( $old_url ) && ! empty( $new_url ) ) {
				$content = str_replace( $old_url, $new_url, $content );
			}
		}

		// 2. Replace old attachment classes: wp-image-OLDID => wp-image-NEWID.
		foreach ( $media_id_map as $old_id => $new_id ) {
			if ( $old_id > 0 && $new_id > 0 ) {
				$content = preg_replace( '/\bwp-image-' . $old_id . '\b/', 'wp-image-' . $new_id, $content );
				// Also update the Gutenberg image block "id" attribute.
				$content = preg_replace( '/("id":\s*)' . $old_id . '(\b)/', '$1' . $new_id . '$2', $content );
			}
		}

		// 3. Strip obsolete responsive srcset and sizes so WordPress natively generates correct responsive srcset for the new site domain.
		$content = preg_replace( '/\s+srcset=["\'][^"\']*["\']/i', '', $content );
		$content = preg_replace( '/\s+sizes=["\'][^"\']*["\']/i', '', $content );

		// 4. If source site URL was specified, replace any leftover source site domain with current live home_url().
		if ( ! empty( $this->source_site_url ) ) {
			$target_site = home_url();
			if ( $this->source_site_url !== $target_site ) {
				$content = str_replace( $this->source_site_url, $target_site, $content );
			}
		}

		return $content;
	}

	/**
	 * Import custom fields/meta for a post, rewriting any embedded image URLs or attachment IDs.
	 *
	 * @param int   $post_id       Post ID.
	 * @param array $meta          Meta key-value pairs.
	 * @param array $mapping       Field mapping adjustments.
	 * @param array $media_url_map Map of old URL => new live URL.
	 * @param array $media_id_map  Map of old attachment ID => new live attachment ID.
	 */
	protected function import_post_meta_with_rewrites( $post_id, array $meta, array $mapping, $media_url_map, $media_id_map ) {
		$source_site = $this->source_site_url;
		$target_site = home_url();

		foreach ( $meta as $key => $value ) {
			$target_key = isset( $mapping[ $key ] ) ? $mapping[ $key ] : $key;

			// Don't overwrite internal thumbnail directly here; handled via import_media.
			if ( '_thumbnail_id' === $target_key ) {
				continue;
			}

			// Recursively transform any image URLs, attachment IDs, or serialized ACF structures.
			$transformed_value = Codixr_Data_Shifter_Media::replace_meta_urls_and_ids(
				$value,
				$media_url_map,
				$media_id_map,
				$source_site,
				$target_site
			);

			update_post_meta( $post_id, $target_key, $transformed_value );
		}
	}

	/**
	 * Import taxonomies and terms for a post, preserving hierarchy.
	 *
	 * @param int   $post_id    Post ID.
	 * @param array $taxonomies Taxonomies and terms.
	 */
	protected function import_post_taxonomies( $post_id, array $taxonomies ) {
		foreach ( $taxonomies as $taxonomy => $terms ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				// Automatically register taxonomy if missing so CPT terms are not lost.
				register_taxonomy(
					$taxonomy,
					array( get_post_type( $post_id ) ),
					array(
						'hierarchical' => true,
						'show_ui'      => true,
						'query_var'    => true,
					)
				);
			}

			$term_ids = array();

			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$term_name   = is_array( $term ) ? ( $term['name'] ?? '' ) : (string) $term;
					$slug        = is_array( $term ) && ! empty( $term['slug'] ) ? $term['slug'] : sanitize_title( $term_name );
					$parent_slug = is_array( $term ) && ! empty( $term['parent'] ) ? $term['parent'] : '';

					if ( empty( $term_name ) ) {
						continue;
					}

					// Find or create parent term if specified.
					$parent_term_id = 0;
					if ( ! empty( $parent_slug ) ) {
						$parent_existing = term_exists( $parent_slug, $taxonomy );
						if ( $parent_existing ) {
							$parent_term_id = (int) ( is_array( $parent_existing ) ? $parent_existing['term_id'] : $parent_existing );
						}
					}

					// Find or create term.
					$existing = term_exists( $slug, $taxonomy );
					if ( ! $existing ) {
						$existing = term_exists( $term_name, $taxonomy );
					}

					if ( $existing ) {
						$term_ids[] = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
					} else {
						$args = array( 'slug' => $slug );
						if ( $parent_term_id > 0 ) {
							$args['parent'] = $parent_term_id;
						}
						$new_term = wp_insert_term( $term_name, $taxonomy, $args );
						if ( ! is_wp_error( $new_term ) && isset( $new_term['term_id'] ) ) {
							$term_ids[] = (int) $new_term['term_id'];
						}
					}
				}
			}

			if ( ! empty( $term_ids ) ) {
				wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
			}
		}
	}

	/**
	 * Import comments for a post.
	 *
	 * @param int   $post_id  Post ID.
	 * @param array $comments Array of comment data.
	 */
	protected function import_post_comments( $post_id, array $comments ) {
		foreach ( $comments as $comment ) {
			if ( empty( $comment['content'] ) ) {
				continue;
			}

			$commentdata = array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => isset( $comment['author'] ) ? sanitize_text_field( $comment['author'] ) : '',
				'comment_author_email' => isset( $comment['author_email'] ) ? sanitize_email( $comment['author_email'] ) : '',
				'comment_author_url'   => isset( $comment['author_url'] ) ? esc_url_raw( $comment['author_url'] ) : '',
				'comment_content'      => wp_kses_post( $comment['content'] ),
				'comment_date'         => isset( $comment['date'] ) ? $comment['date'] : current_time( 'mysql' ),
				'comment_approved'     => isset( $comment['approved'] ) ? $comment['approved'] : 1,
			);

			wp_insert_comment( $commentdata );
		}
	}

	/**
	 * Find existing post ID according to match criteria.
	 *
	 * @param array  $item      Item data.
	 * @param string $match_by  Match criteria ('title', 'slug', or 'id').
	 * @param string $post_type Post type.
	 * @return int|false
	 */
	protected function find_existing_post( array $item, $match_by, $post_type ) {
		global $wpdb;

		$post_data = isset( $item['post_data'] ) ? $item['post_data'] : array();

		if ( 'slug' === $match_by && ! empty( $post_data['post_name'] ) ) {
			$slug = sanitize_title( $post_data['post_name'] );
			$id   = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s LIMIT 1",
					$slug,
					$post_type
				)
			);
			return $id ? (int) $id : false;
		}

		if ( ! empty( $post_data['post_title'] ) ) {
			$title = $post_data['post_title'];
			$id    = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = %s LIMIT 1",
					$title,
					$post_type
				)
			);
			return $id ? (int) $id : false;
		}

		return false;
	}

	/**
	 * Resolve author ID from author object or fallback to current user.
	 *
	 * @param array $item Item data.
	 * @return int
	 */
	protected function resolve_author_id( array $item ) {
		if ( ! empty( $item['post_author'] ) ) {
			$author_data = $item['post_author'];

			if ( ! empty( $author_data['user_email'] ) ) {
				$user = get_user_by( 'email', $author_data['user_email'] );
				if ( $user ) {
					return $user->ID;
				}
			}

			if ( ! empty( $author_data['user_login'] ) ) {
				$user = get_user_by( 'login', $author_data['user_login'] );
				if ( $user ) {
					return $user->ID;
				}
			}
		}

		return get_current_user_id() ? get_current_user_id() : 1;
	}

	/**
	 * Resolve and set parent-child relationships after all posts have been imported.
	 *
	 * @return void
	 */
	public function resolve_parent_relationships() {
		global $wpdb;

		if ( empty( $this->parent_queue ) ) {
			return;
		}

		foreach ( $this->parent_queue as $item ) {
			$post_id          = $item['post_id'];
			$old_parent_id    = $item['old_parent_id'];
			$parent_slug      = $item['post_parent_slug'];
			$parent_title     = $item['post_parent_title'];
			$target_parent_id = 0;

			// 1. Check ID map (imported in this session).
			if ( $old_parent_id > 0 && isset( $this->id_map[ $old_parent_id ] ) ) {
				$target_parent_id = $this->id_map[ $old_parent_id ];
			} elseif ( ! empty( $parent_slug ) && isset( $this->slug_map[ $parent_slug ] ) ) {
				$target_parent_id = $this->slug_map[ $parent_slug ];
			} elseif ( ! empty( $parent_slug ) ) {
				$found = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s LIMIT 1",
						$parent_slug
					)
				);
				if ( $found ) {
					$target_parent_id = (int) $found;
				}
			} elseif ( ! empty( $parent_title ) && isset( $this->title_map[ $parent_title ] ) ) {
				$target_parent_id = $this->title_map[ $parent_title ];
			} elseif ( ! empty( $parent_title ) ) {
				$found = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE post_title = %s LIMIT 1",
						$parent_title
					)
				);
				if ( $found ) {
					$target_parent_id = (int) $found;
				}
			}

			if ( $target_parent_id > 0 && $target_parent_id !== $post_id ) {
				$wpdb->update(
					$wpdb->posts,
					array( 'post_parent' => $target_parent_id ),
					array( 'ID' => $post_id ),
					array( '%d' ),
					array( '%d' )
				);
				clean_post_cache( $post_id );
			}
		}
	}
}
