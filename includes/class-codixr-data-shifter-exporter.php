<?php
/**
 * Data Shifter Exporter
 *
 * Handles exporting WordPress posts to JSON, CSV, and XML formats
 * with support for meta, taxonomies, media, and comments.
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
 * Class Codixr_Data_Shifter_Exporter
 *
 * Builds queries from export configuration and formats results for export.
 *
 * @since 1.0.0
 */
class Codixr_Data_Shifter_Exporter {

	/**
	 * Export configuration array.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $config;

	/**
	 * Cached meta keys for the current post type.
	 *
	 * @since 1.0.0
	 * @var array|null
	 */
	private $meta_keys_cache = null;

	/**
	 * Total items exported in the last run.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $total_exported = 0;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param array $config Export configuration array.
	 *
	 * @throws InvalidArgumentException If the post type does not exist.
	 */
	public function __construct( $config ) {
		$defaults = array(
			'post_type'          => 'post',
			'filters'            => array(),
			'fields'             => array(),
			'include_meta'       => true,
			'include_taxonomies' => true,
			'include_media'      => true,
			'include_gallery'    => false,
			'include_comments'   => false,
			'format'             => 'json',
		);

		$this->config = wp_parse_args( $config, $defaults );

		$filter_defaults = array(
			'status'       => 'any',
			'date_from'    => '',
			'date_to'      => '',
			'taxonomy'     => '',
			'terms'        => array(),
			'search'       => '',
			'specific_ids' => array(),
			'author'       => 0,
		);

		$this->config['filters'] = wp_parse_args( $this->config['filters'], $filter_defaults );

		// Validate post type exists.
		if ( ! post_type_exists( $this->config['post_type'] ) ) {
			throw new InvalidArgumentException(
				esc_html(
					sprintf(
						/* translators: %s: post type name */
						__( 'Post type "%s" does not exist.', 'data-shifter' ),
						$this->config['post_type']
					)
				)
			);
		}
	}

	/**
	 * Build WP_Query arguments from the export configuration.
	 *
	 * @since 1.0.0
	 *
	 * @param int $limit Optional. Number of posts to return. -1 for all. Default -1.
	 *
	 * @return array WP_Query arguments.
	 */
	public function get_query_args( $limit = -1 ) {
		$filters = $this->config['filters'];

		$args = array(
			'post_type'              => $this->config['post_type'],
			'post_status'            => ! empty( $filters['status'] ) ? $filters['status'] : 'any',
			'posts_per_page'         => intval( $limit ),
			'no_found_rows'          => ( -1 === intval( $limit ) ),
			'update_post_meta_cache' => true,
			'update_post_term_cache' => true,
		);

		// Date query.
		if ( ! empty( $filters['date_from'] ) || ! empty( $filters['date_to'] ) ) {
			$date_query = array();

			if ( ! empty( $filters['date_from'] ) ) {
				$date_query['after'] = sanitize_text_field( $filters['date_from'] );
			}

			if ( ! empty( $filters['date_to'] ) ) {
				$date_query['before'] = sanitize_text_field( $filters['date_to'] );
			}

			$date_query['inclusive'] = true;
			$args['date_query']      = array( $date_query );
		}

		// Taxonomy query.
		if ( ! empty( $filters['taxonomy'] ) && ! empty( $filters['terms'] ) ) {
			$terms = array_map( 'sanitize_text_field', (array) $filters['terms'] );

			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => sanitize_text_field( $filters['taxonomy'] ),
					'field'    => 'slug',
					'terms'    => $terms,
				),
			);
		}

		// Search.
		if ( ! empty( $filters['search'] ) ) {
			$args['s'] = sanitize_text_field( $filters['search'] );
		}

		// Specific IDs.
		if ( ! empty( $filters['specific_ids'] ) ) {
			$args['post__in'] = array_map( 'absint', (array) $filters['specific_ids'] );
		}

		// Author.
		if ( ! empty( $filters['author'] ) ) {
			$args['author'] = absint( $filters['author'] );
		}

		return $args;
	}

	/**
	 * Run a preview query with a limited number of results.
	 *
	 * @since 1.0.0
	 *
	 * @param int $limit Number of items to preview. Default 5.
	 *
	 * @return array Array of formatted items.
	 */
	public function preview( $limit = 5 ) {
		$args  = $this->get_query_args( $limit );
		$query = new WP_Query( $args );
		$items = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$items[] = $this->format_item( $query->post );
			}
			wp_reset_postdata();
		}

		return $items;
	}

	/**
	 * Run the full export and return data in the specified format.
	 *
	 * @since 1.0.0
	 *
	 * @return string Formatted export data (JSON, CSV, or XML string).
	 */
	public function export() {
		$temp_file = wp_tempnam( 'codixr_data_shifter_exp_' );
		$res       = $this->export_to_file( $temp_file );

		if ( is_wp_error( $res ) ) {
			if ( file_exists( $temp_file ) ) {
				wp_delete_file( $temp_file );
			}
			return $res;
		}

		$content = file_get_contents( $temp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_delete_file( $temp_file );

		return false !== $content ? $content : '';
	}

	/**
	 * Stream the export directly to a target file path to avoid memory exhaustion.
	 *
	 * @since 1.0.0
	 *
	 * @param string $filepath Destination file path.
	 * @return true|WP_Error True on success or WP_Error on failure.
	 */
	public function export_to_file( $filepath ) {
		$args  = $this->get_query_args( -1 );
		$query = new WP_Query( $args );

		$count = 0;
		if ( $query->have_posts() ) {
			$count = count( $query->posts );
		}
		$this->total_exported = $count;

		$format = strtolower( $this->config['format'] );

		switch ( $format ) {
			case 'zip':
				return $this->stream_to_zip( $filepath, $query );
			case 'csv':
				return $this->stream_to_csv( $filepath, $query );
			case 'xml':
				return $this->stream_to_xml( $filepath, $query );
			case 'json':
			default:
				return $this->stream_to_json( $filepath, $query );
		}
	}

	/**
	 * Stream a complete ZIP package with data.json and raw image files in media/ folder.
	 *
	 * @param string   $filepath Destination zip file path.
	 * @param WP_Query $query    Executed WP_Query instance.
	 * @return true|WP_Error
	 */
	private function stream_to_zip( $filepath, $query ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_unsupported', __( 'ZipArchive is not enabled on this server PHP environment.', 'data-shifter' ) );
		}

		$zip    = new ZipArchive();
		$status = $zip->open( $filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		if ( true !== $status ) {
			return new WP_Error( 'zip_create_failed', __( 'Could not create ZIP package file on disk.', 'data-shifter' ) );
		}

		$upload_dir = wp_upload_dir();
		$items      = array();
		$media_idx  = 0;

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$item = $this->format_item( $query->post );

				// Store featured image binary into media/ folder in zip.
				if ( ! empty( $item['featured_image']['data'] ) ) {
					$bin   = base64_decode( $item['featured_image']['data'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Bundles image bytes inside export packages.
					$fname = sanitize_file_name( $item['featured_image']['filename'] ?? ( 'feat_' . $item['post_data']['ID'] . '.png' ) );
					// Only media types go into the package; other files are imported from their URL instead.
					if ( Codixr_Data_Shifter_Files::is_media_filename( $fname ) ) {
						$zip->addFromString( 'media/' . $fname, $bin );
						$item['featured_image']['zip_path'] = 'media/' . $fname;
					}
					unset( $item['featured_image']['data'] );
				}

				// Store gallery and meta image binaries into media/ folder in zip.
				if ( ! empty( $item['media_gallery'] ) && is_array( $item['media_gallery'] ) ) {
					foreach ( $item['media_gallery'] as $k => $m_item ) {
						if ( ! empty( $m_item['data'] ) ) {
							$bin   = base64_decode( $m_item['data'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Bundles image bytes inside export packages.
							$fname = sanitize_file_name( $m_item['filename'] ?? ( 'img_' . $media_idx . '.png' ) );
							if ( Codixr_Data_Shifter_Files::is_media_filename( $fname ) ) {
								$zip->addFromString( 'media/' . $fname, $bin );
								$item['media_gallery'][ $k ]['zip_path'] = 'media/' . $fname;
							}
							unset( $item['media_gallery'][ $k ]['data'] );
							++$media_idx;
						}
					}
				}

				$items[] = $item;
				unset( $item );
			}
			wp_reset_postdata();
		}

		$package_data = array(
			'version'      => '1.1',
			'plugin'       => 'Data Shifter',
			'package_type' => 'zip',
			'site_url'     => home_url(),
			'upload_url'   => untrailingslashit( $upload_dir['baseurl'] ),
			'exported_at'  => current_time( 'c' ),
			'post_type'    => $this->config['post_type'],
			'total_items'  => (int) $this->total_exported,
			'items'        => $items,
		);

		$zip->addFromString( 'data.json', wp_json_encode( $package_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		$zip->close();

		return true;
	}

	/**
	 * Stream JSON data item-by-item to disk.
	 *
	 * @param string   $filepath Destination file path.
	 * @param WP_Query $query    Executed WP_Query instance.
	 * @return true|WP_Error
	 */
	private function stream_to_json( $filepath, $query ) {
		$handle = fopen( $filepath, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			return new WP_Error( 'file_error', __( 'Cannot open file for writing.', 'data-shifter' ) );
		}

		$fields_included = array( 'title', 'content', 'excerpt', 'status', 'date', 'modified', 'slug', 'author' );
		if ( ! empty( $this->config['include_meta'] ) ) {
			$fields_included[] = 'meta';
		}
		if ( ! empty( $this->config['include_taxonomies'] ) ) {
			$fields_included[] = 'taxonomies';
		}
		if ( ! empty( $this->config['include_media'] ) ) {
			$fields_included[] = 'featured_image';
			$fields_included[] = 'media_gallery';
		}
		if ( ! empty( $this->config['include_comments'] ) ) {
			$fields_included[] = 'comments';
		}

		$upload_dir = wp_upload_dir();

		fwrite( $handle, "{\n" );
		fwrite( $handle, '"version":"1.1",' . "\n" );
		fwrite( $handle, '"plugin":"Data Shifter",' . "\n" );
		fwrite( $handle, '"site_url":' . wp_json_encode( home_url() ) . ",\n" );
		fwrite( $handle, '"upload_url":' . wp_json_encode( untrailingslashit( $upload_dir['baseurl'] ) ) . ",\n" );
		fwrite( $handle, '"exported_at":' . wp_json_encode( current_time( 'c' ) ) . ",\n" );
		fwrite( $handle, '"post_type":' . wp_json_encode( $this->config['post_type'] ) . ",\n" );
		fwrite( $handle, '"total_items":' . (int) $this->total_exported . ",\n" );
		fwrite( $handle, '"fields_included":' . wp_json_encode( $fields_included ) . ",\n" );
		fwrite( $handle, '"items":[' . "\n" );

		$first = true;

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$item = $this->format_item( $query->post );

				if ( ! $first ) {
					fwrite( $handle, ",\n" );
				}
				fwrite( $handle, wp_json_encode( $item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
				$first = false;

				unset( $item );
			}
			wp_reset_postdata();
		}

		fwrite( $handle, "\n]\n}\n" );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return true;
	}

	/**
	 * Stream CSV data to disk.
	 *
	 * @param string   $filepath Destination file path.
	 * @param WP_Query $query    Executed WP_Query instance.
	 * @return true|WP_Error
	 */
	private function stream_to_csv( $filepath, $query ) {
		$items = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$items[] = $this->format_item( $query->post );
			}
			wp_reset_postdata();
		}
		$csv = $this->to_csv( $items );
		file_put_contents( $filepath, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return true;
	}

	/**
	 * Stream XML data to disk.
	 *
	 * @param string   $filepath Destination file path.
	 * @param WP_Query $query    Executed WP_Query instance.
	 * @return true|WP_Error
	 */
	private function stream_to_xml( $filepath, $query ) {
		$items = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$items[] = $this->format_item( $query->post );
			}
			wp_reset_postdata();
		}
		$xml = $this->to_xml( $items );
		file_put_contents( $filepath, $xml ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return true;
	}

	/**
	 * Return total items exported.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function get_total_exported() {
		return $this->total_exported;
	}

	/**
	 * Format a single post into the export array structure.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post $post The post object.
	 *
	 * @return array Formatted item data.
	 */
	public function format_item( $post ) {
		$item = array();

		// Resolve parent information.
		$parent_slug  = '';
		$parent_title = '';
		if ( ! empty( $post->post_parent ) ) {
			$parent = get_post( $post->post_parent );
			if ( $parent ) {
				$parent_slug  = $parent->post_name;
				$parent_title = $parent->post_title;
			}
		}

		// Post data.
		$item['post_data'] = array(
			'ID'                => (int) $post->ID,
			'post_title'        => $post->post_title,
			'post_content'      => $post->post_content,
			'post_excerpt'      => $post->post_excerpt,
			'post_status'       => $post->post_status,
			'post_date'         => $post->post_date,
			'post_modified'     => $post->post_modified,
			'post_name'         => $post->post_name,
			'post_type'         => $post->post_type,
			'post_parent'       => (int) $post->post_parent,
			'post_parent_slug'  => $parent_slug,
			'post_parent_title' => $parent_title,
			'menu_order'        => (int) $post->menu_order,
			'post_password'     => $post->post_password,
			'comment_status'    => $post->comment_status,
			'ping_status'       => $post->ping_status,
		);

		// Post author.
		$author = get_userdata( $post->post_author );
		if ( $author ) {
			$item['post_author'] = array(
				'user_login'   => $author->user_login,
				'user_email'   => $author->user_email,
				'display_name' => $author->display_name,
			);
		} else {
			$item['post_author'] = array(
				'user_login'   => '',
				'user_email'   => '',
				'display_name' => '',
			);
		}

		// Meta.
		if ( $this->config['include_meta'] ) {
			$item['meta'] = $this->get_post_meta_data( $post->ID );
		}

		// Taxonomies.
		if ( $this->config['include_taxonomies'] ) {
			$item['taxonomies'] = $this->get_post_taxonomy_data( $post );
		}

		// Media.
		if ( $this->config['include_media'] ) {
			$item['featured_image'] = Codixr_Data_Shifter_Media::get_featured_image_data( $post->ID );
			$include_gallery        = ! empty( $this->config['include_gallery'] );
			$post_meta_for_media    = isset( $item['meta'] ) ? $item['meta'] : array();
			$item['media_gallery']  = Codixr_Data_Shifter_Media::get_post_media( $post, $include_gallery, $post_meta_for_media );
		}

		// Comments.
		if ( $this->config['include_comments'] ) {
			$item['comments'] = $this->get_post_comments( $post->ID );
		}

		return $item;
	}

	/**
	 * Get and format post meta data.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return array Associative array of meta key => value pairs.
	 */
	private function get_post_meta_data( $post_id ) {
		$all_meta = get_post_meta( $post_id );
		$meta     = array();

		// Internal WP meta keys to skip unless explicitly requested.
		$internal_keys = array(
			'_edit_lock',
			'_edit_last',
			'_wp_old_slug',
			'_wp_old_date',
			'_wp_trash_meta_status',
			'_wp_trash_meta_time',
			'_wp_desired_post_slug',
			'_encloseme',
			'_pingme',
		);

		// If specific fields are configured, only include those.
		$allowed_fields = ! empty( $this->config['fields'] ) ? (array) $this->config['fields'] : array();

		foreach ( $all_meta as $key => $values ) {
			// If specific fields are set, only include those.
			if ( ! empty( $allowed_fields ) && ! in_array( $key, $allowed_fields, true ) ) {
				continue;
			}

			// Skip internal WP meta unless explicitly included in fields.
			if ( empty( $allowed_fields ) && in_array( $key, $internal_keys, true ) ) {
				continue;
			}

			// Skip the thumbnail ID (handled separately in media).
			if ( '_thumbnail_id' === $key && empty( $allowed_fields ) ) {
				continue;
			}

			if ( count( $values ) === 1 ) {
				$meta[ $key ] = maybe_unserialize( $values[0] );
			} else {
				$meta[ $key ] = array_map( 'maybe_unserialize', $values );
			}
		}

		return $meta;
	}

	/**
	 * Get and format taxonomy data for a post.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post $post The post object.
	 *
	 * @return array Associative array of taxonomy => terms data.
	 */
	private function get_post_taxonomy_data( $post ) {
		$taxonomies    = get_object_taxonomies( $post->post_type, 'names' );
		$taxonomy_data = array();

		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_the_terms( $post->ID, $taxonomy );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			$taxonomy_data[ $taxonomy ] = array();

			foreach ( $terms as $term ) {
				$parent_slug = '';
				if ( $term->parent > 0 ) {
					$parent_term = get_term( $term->parent, $taxonomy );
					if ( ! is_wp_error( $parent_term ) && $parent_term ) {
						$parent_slug = $parent_term->slug;
					}
				}

				$taxonomy_data[ $taxonomy ][] = array(
					'name'        => $term->name,
					'slug'        => $term->slug,
					'description' => $term->description,
					'parent'      => $parent_slug,
				);
			}
		}

		return $taxonomy_data;
	}

	/**
	 * Get and format comments for a post.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return array Array of formatted comment data.
	 */
	private function get_post_comments( $post_id ) {
		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'status'  => 'all',
				'orderby' => 'comment_date',
				'order'   => 'ASC',
			)
		);

		$formatted = array();

		foreach ( $comments as $comment ) {
			$formatted[] = array(
				'author'       => $comment->comment_author,
				'author_email' => $comment->comment_author_email,
				'author_url'   => $comment->comment_author_url,
				'content'      => $comment->comment_content,
				'date'         => $comment->comment_date,
				'approved'     => $comment->comment_approved,
				'parent'       => (int) $comment->comment_parent,
			);
		}

		return $formatted;
	}

	/**
	 * Get all distinct meta keys for a post type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type The post type.
	 *
	 * @return array Array of meta key strings.
	 */
	public function get_all_meta_keys( $post_type ) {
		if ( null !== $this->meta_keys_cache ) {
			return $this->meta_keys_cache;
		}

		global $wpdb;

		$cache_key = 'codixr_data_shifter_meta_keys_' . sanitize_key( $post_type );
		$cached    = wp_cache_get( $cache_key, 'data-shifter' );

		if ( false !== $cached ) {
			$this->meta_keys_cache = $cached;
			return $cached;
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_key
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE p.post_type = %s
                AND pm.meta_key NOT LIKE %s
                ORDER BY pm.meta_key",
				$post_type,
				$wpdb->esc_like( '_' ) . '%'
			)
		);

		if ( ! is_array( $keys ) ) {
			$keys = array();
		}

		wp_cache_set( $cache_key, $keys, 'data-shifter', HOUR_IN_SECONDS );
		$this->meta_keys_cache = $keys;

		return $keys;
	}

	/**
	 * Convert items array to JSON export format.
	 *
	 * @since 1.0.0
	 *
	 * @param array $items Array of formatted items.
	 *
	 * @return string JSON string.
	 */
	private function to_json( $items ) {
		$fields_included = array( 'title', 'content', 'excerpt', 'status', 'date', 'modified', 'slug', 'author' );

		if ( $this->config['include_meta'] ) {
			$fields_included[] = 'meta';
		}
		if ( $this->config['include_taxonomies'] ) {
			$fields_included[] = 'taxonomies';
		}
		if ( $this->config['include_media'] ) {
			$fields_included[] = 'featured_image';
			$fields_included[] = 'media_gallery';
		}
		if ( $this->config['include_comments'] ) {
			$fields_included[] = 'comments';
		}

		$data = array(
			'version'         => '1.0',
			'plugin'          => 'Data Shifter',
			'site_url'        => home_url(),
			'exported_at'     => current_time( 'c' ),
			'post_type'       => $this->config['post_type'],
			'total_items'     => count( $items ),
			'fields_included' => $fields_included,
			'items'           => $items,
		);

		return wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Convert items array to CSV format.
	 *
	 * @since 1.0.0
	 *
	 * @param array $items Array of formatted items.
	 *
	 * @return string CSV string.
	 */
	private function to_csv( $items ) {
		$flat_rows = $this->flatten_for_csv( $items );

		if ( empty( $flat_rows ) ) {
			return "Title,Content,Excerpt,Status,Date\n";
		}

		// Collect all column headers from all rows.
		$headers = array();
		foreach ( $flat_rows as $row ) {
			foreach ( array_keys( $row ) as $key ) {
				if ( ! in_array( $key, $headers, true ) ) {
					$headers[] = $key;
				}
			}
		}

		// Build CSV using a memory stream.
		$handle = fopen( 'php://temp', 'r+b' );

		if ( false === $handle ) {
			return '';
		}

		// Write BOM for Excel UTF-8 compatibility.
		fwrite( $handle, "\xEF\xBB\xBF" );

		// Write header row.
		fputcsv( $handle, array_map( array( 'Codixr_Data_Shifter_Files', 'csv_safe_cell' ), $headers ) );

		// Write data rows.
		foreach ( $flat_rows as $row ) {
			$csv_row = array();
			foreach ( $headers as $header ) {
				$value = isset( $row[ $header ] ) ? $row[ $header ] : '';

				// JSON-encode arrays and objects for CSV cells.
				if ( is_array( $value ) || is_object( $value ) ) {
					$value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				}

				// Neutralize spreadsheet formulas (=, +, -, @, tab, CR).
				$csv_row[] = Codixr_Data_Shifter_Files::csv_safe_cell( $value );
			}
			fputcsv( $handle, $csv_row );
		}

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );

		return $csv;
	}

	/**
	 * Flatten nested items into flat rows for CSV export.
	 *
	 * @since 1.0.0
	 *
	 * @param array $items Array of formatted items.
	 *
	 * @return array Array of flat associative arrays (rows).
	 */
	public function flatten_for_csv( $items ) {
		$rows = array();

		foreach ( $items as $item ) {
			$row = array();

			// Standard post fields.
			$post_data = isset( $item['post_data'] ) ? $item['post_data'] : array();

			$row['Original ID']  = isset( $post_data['ID'] ) ? $post_data['ID'] : '';
			$row['Title']        = isset( $post_data['post_title'] ) ? $post_data['post_title'] : '';
			$row['Content']      = isset( $post_data['post_content'] ) ? $post_data['post_content'] : '';
			$row['Excerpt']      = isset( $post_data['post_excerpt'] ) ? $post_data['post_excerpt'] : '';
			$row['Status']       = isset( $post_data['post_status'] ) ? $post_data['post_status'] : '';
			$row['Date']         = isset( $post_data['post_date'] ) ? $post_data['post_date'] : '';
			$row['Modified']     = isset( $post_data['post_modified'] ) ? $post_data['post_modified'] : '';
			$row['Slug']         = isset( $post_data['post_name'] ) ? $post_data['post_name'] : '';
			$row['Parent Slug']  = isset( $post_data['post_parent_slug'] ) ? $post_data['post_parent_slug'] : '';
			$row['Parent Title'] = isset( $post_data['post_parent_title'] ) ? $post_data['post_parent_title'] : '';

			// Author.
			$author        = isset( $item['post_author'] ) ? $item['post_author'] : array();
			$row['Author'] = isset( $author['user_login'] ) ? $author['user_login'] : '';

			// Meta fields — each key becomes its own column.
			if ( isset( $item['meta'] ) && is_array( $item['meta'] ) ) {
				foreach ( $item['meta'] as $meta_key => $meta_value ) {
					$column_name = 'meta_' . $meta_key;

					if ( is_array( $meta_value ) || is_object( $meta_value ) ) {
						$row[ $column_name ] = wp_json_encode( $meta_value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
					} else {
						$row[ $column_name ] = $meta_value;
					}
				}
			}

			// Taxonomies — each taxonomy becomes a comma-separated column.
			if ( isset( $item['taxonomies'] ) && is_array( $item['taxonomies'] ) ) {
				foreach ( $item['taxonomies'] as $taxonomy => $terms ) {
					$term_names                = wp_list_pluck( $terms, 'name' );
					$row[ 'tax_' . $taxonomy ] = implode( ', ', $term_names );
				}
			}

			// Featured image URL.
			$row['Featured Image URL'] = '';
			if ( isset( $item['featured_image'] ) && ! empty( $item['featured_image']['url'] ) ) {
				$row['Featured Image URL'] = $item['featured_image']['url'];
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Convert items array to XML format.
	 *
	 * @since 1.0.0
	 *
	 * @param array $items Array of formatted items.
	 *
	 * @return string XML string.
	 */
	private function to_xml( $items ) {
		$dom               = new DOMDocument( '1.0', 'UTF-8' );
		$dom->formatOutput = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument property.

		// Root element.
		$root = $dom->createElement( 'data-shifter' );
		$root->setAttribute( 'version', '1.0' );
		$root->setAttribute( 'site', home_url() );
		$root->setAttribute( 'exported_at', current_time( 'c' ) );
		$dom->appendChild( $root );

		// Items wrapper.
		$items_el = $dom->createElement( 'items' );
		$items_el->setAttribute( 'post_type', $this->config['post_type'] );
		$items_el->setAttribute( 'count', count( $items ) );
		$root->appendChild( $items_el );

		foreach ( $items as $item ) {
			$item_el = $dom->createElement( 'item' );
			$items_el->appendChild( $item_el );

			// Post data.
			if ( isset( $item['post_data'] ) ) {
				foreach ( $item['post_data'] as $key => $value ) {
					$el = $dom->createElement( $key );
					if ( is_int( $value ) ) {
						$el->appendChild( $dom->createTextNode( (string) $value ) );
					} else {
						$el->appendChild( $dom->createCDATASection( (string) $value ) );
					}
					$item_el->appendChild( $el );
				}
			}

			// Author.
			if ( isset( $item['post_author'] ) ) {
				$author_el = $dom->createElement( 'post_author' );
				foreach ( $item['post_author'] as $key => $value ) {
					$child = $dom->createElement( $key );
					$child->appendChild( $dom->createTextNode( (string) $value ) );
					$author_el->appendChild( $child );
				}
				$item_el->appendChild( $author_el );
			}

			// Meta.
			if ( isset( $item['meta'] ) && ! empty( $item['meta'] ) ) {
				$meta_el = $dom->createElement( 'meta' );
				foreach ( $item['meta'] as $key => $value ) {
					$meta_item = $dom->createElement( 'meta_item' );
					$meta_item->setAttribute( 'key', $key );
					if ( is_array( $value ) || is_object( $value ) ) {
						$meta_item->appendChild( $dom->createCDATASection( wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) ) );
					} else {
						$meta_item->appendChild( $dom->createCDATASection( (string) $value ) );
					}
					$meta_el->appendChild( $meta_item );
				}
				$item_el->appendChild( $meta_el );
			}

			// Taxonomies.
			if ( isset( $item['taxonomies'] ) && ! empty( $item['taxonomies'] ) ) {
				$tax_el = $dom->createElement( 'taxonomies' );
				foreach ( $item['taxonomies'] as $taxonomy => $terms ) {
					$taxonomy_el = $dom->createElement( 'taxonomy' );
					$taxonomy_el->setAttribute( 'name', $taxonomy );
					foreach ( $terms as $term ) {
						$term_el = $dom->createElement( 'term' );
						foreach ( $term as $key => $value ) {
							$term_el->setAttribute( $key, (string) $value );
						}
						$taxonomy_el->appendChild( $term_el );
					}
					$tax_el->appendChild( $taxonomy_el );
				}
				$item_el->appendChild( $tax_el );
			}

			// Featured image.
			if ( isset( $item['featured_image'] ) && ! empty( $item['featured_image'] ) ) {
				$fi_el = $dom->createElement( 'featured_image' );
				foreach ( $item['featured_image'] as $key => $value ) {
					$child = $dom->createElement( $key );
					$child->appendChild( $dom->createCDATASection( (string) $value ) );
					$fi_el->appendChild( $child );
				}
				$item_el->appendChild( $fi_el );
			}

			// Media gallery.
			if ( isset( $item['media_gallery'] ) && ! empty( $item['media_gallery'] ) ) {
				$gallery_el = $dom->createElement( 'media_gallery' );
				foreach ( $item['media_gallery'] as $media ) {
					$media_el = $dom->createElement( 'media' );
					foreach ( $media as $key => $value ) {
						$child = $dom->createElement( $key );
						$child->appendChild( $dom->createCDATASection( (string) $value ) );
						$media_el->appendChild( $child );
					}
					$gallery_el->appendChild( $media_el );
				}
				$item_el->appendChild( $gallery_el );
			}

			// Comments.
			if ( isset( $item['comments'] ) && ! empty( $item['comments'] ) ) {
				$comments_el = $dom->createElement( 'comments' );
				foreach ( $item['comments'] as $comment ) {
					$comment_el = $dom->createElement( 'comment' );
					foreach ( $comment as $key => $value ) {
						$child = $dom->createElement( $key );
						if ( 'content' === $key ) {
							$child->appendChild( $dom->createCDATASection( (string) $value ) );
						} else {
							$child->appendChild( $dom->createTextNode( (string) $value ) );
						}
						$comment_el->appendChild( $child );
					}
					$comments_el->appendChild( $comment_el );
				}
				$item_el->appendChild( $comments_el );
			}
		}

		return $dom->saveXML();
	}
}
