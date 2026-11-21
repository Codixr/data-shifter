<?php
/**
 * Data Shifter Media Handler
 *
 * Handles media import/export operations including downloading remote images,
 * extracting media from post content, custom fields (meta), and managing attachment metadata.
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

/**
 * Class Codixr_Data_Shifter_Media
 *
 * All static methods for media import and export operations.
 *
 * @since 1.0.0
 */
class Codixr_Data_Shifter_Media {

	/**
	 * Download or save an image from a URL or bundled base64 data and add to the WordPress Media Library.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url         The source image URL.
	 * @param int    $post_id     Optional. The post ID to attach the image to. Default 0.
	 * @param string $base64_data Optional. Bundled base64-encoded image data. Default empty.
	 * @param array  $extra_data  Optional. Additional metadata (title, alt, caption, description).
	 *
	 * @return int|WP_Error Attachment ID on success, WP_Error on failure.
	 */
	public static function import_from_url( $url, $post_id = 0, $base64_data = '', $extra_data = array() ) {
		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new WP_Error( 'codixr_data_shifter_invalid_url', __( 'Invalid URL provided for media import.', 'data-shifter' ) );
		}

		// Check if we already imported this URL in this installation to avoid duplicates.
		$existing = self::find_existing_attachment_by_url( $url );
		if ( $existing ) {
			return $existing;
		}

		// Case 1: If base64 data was bundled with the export (e.g. from local .local site).
		if ( ! empty( $base64_data ) ) {
			if ( strpos( $base64_data, ',' ) !== false ) {
				$base64_data = substr( $base64_data, strpos( $base64_data, ',' ) + 1 );
			}
			$binary = base64_decode( $base64_data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Bundles image bytes inside export packages.
			if ( ! empty( $binary ) ) {
				return self::save_image_binary( $binary, $url, $post_id, $extra_data );
			}
		}

		// Case 2: Check if this URL is from a local unreachable domain (.local, localhost, 127.0.0.1, etc.).
		$parsed          = wp_parse_url( $url );
		$host            = isset( $parsed['host'] ) ? strtolower( $parsed['host'] ) : '';
		$is_local_domain = (
			'localhost' === $host ||
			'127.0.0.1' === $host ||
			substr( $host, -6 ) === '.local' ||
			substr( $host, -5 ) === '.test' ||
			substr( $host, -4 ) === '.loc' ||
			substr( $host, -4 ) === '.lan' ||
			substr( $host, -8 ) === '.example'
		);

		if ( $is_local_domain ) {
			return new WP_Error(
				'codixr_data_shifter_local_unreachable',
				sprintf(
					/* translators: %s: host name */
					__( 'Local host %s cannot be reached from this server without bundled image data.', 'data-shifter' ),
					$host
				)
			);
		}

		// Case 3: Public remote URL - download with safe 15-second timeout.
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'User-Agent' => 'WordPress/CodixrDataShifter-Importer; ' . home_url(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'codixr_data_shifter_download_failed',
				sprintf(
					/* translators: 1: URL, 2: error message */
					__( 'Failed to download media from %1$s: %2$s', 'data-shifter' ),
					esc_url( $url ),
					$response->get_error_message()
				)
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			return new WP_Error(
				'codixr_data_shifter_download_http_error',
				sprintf(
					/* translators: 1: URL, 2: HTTP status code */
					__( 'HTTP error %2$d when downloading %1$s.', 'data-shifter' ),
					esc_url( $url ),
					$response_code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return new WP_Error( 'codixr_data_shifter_empty_response', __( 'Downloaded file is empty.', 'data-shifter' ) );
		}

		return self::save_image_binary( $body, $url, $post_id, $extra_data );
	}

	/**
	 * Save raw image binary data as a WordPress attachment.
	 *
	 * @since 1.0.0
	 *
	 * @param string $body       Raw binary image data.
	 * @param string $url        Source URL for filename and metadata.
	 * @param int    $post_id    Post ID to attach media to.
	 * @param array  $extra_data Optional metadata (title, alt, caption, description).
	 *
	 * @return int|WP_Error Attachment ID on success, WP_Error on failure.
	 */
	public static function save_image_binary( $body, $url, $post_id = 0, $extra_data = array() ) {
		// wp_generate_attachment_metadata() (called below) lives in wp-admin/includes/image.php.
		// The other functions used here (wp_upload_bits, wp_check_filetype, wp_insert_attachment)
		// are always loaded by core.
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Determine the filename from the URL, stripping query parameters.
		$parsed_url = wp_parse_url( $url );
		$path       = isset( $parsed_url['path'] ) ? $parsed_url['path'] : 'image.png';
		$path_parts = pathinfo( $path );
		$filename   = sanitize_file_name( $path_parts['basename'] ?? 'image.png' );

		// If no extension or unrecognized, determine from binary content magic bytes.
		if ( empty( $path_parts['extension'] ) || strlen( $path_parts['extension'] ) > 5 ) {
			if ( function_exists( 'finfo_open' ) ) {
				$finfo = finfo_open( FILEINFO_MIME_TYPE );
				if ( $finfo ) {
					$mime = finfo_buffer( $finfo, $body );
					finfo_close( $finfo );
					$mime_to_ext = array(
						'image/jpeg'    => 'jpg',
						'image/png'     => 'png',
						'image/gif'     => 'gif',
						'image/webp'    => 'webp',
						'image/svg+xml' => 'svg',
					);
					if ( isset( $mime_to_ext[ $mime ] ) ) {
						$filename .= '.' . $mime_to_ext[ $mime ];
					}
				}
			}
		}

		// Clean query strings from filename if present.
		$filename = preg_replace( '/\?.*$/', '', $filename );

		// Save the file using wp_upload_bits.
		$upload = wp_upload_bits( $filename, null, $body );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'codixr_data_shifter_upload_failed', $upload['error'] );
		}

		// Determine MIME type.
		$filetype = wp_check_filetype( $upload['file'], null );
		if ( empty( $filetype['type'] ) ) {
			wp_delete_file( $upload['file'] );
			return new WP_Error( 'codixr_data_shifter_invalid_filetype', __( 'Could not determine file type.', 'data-shifter' ) );
		}

		$title       = ! empty( $extra_data['title'] ) ? $extra_data['title'] : ( $path_parts['filename'] ?? 'Image' );
		$caption     = ! empty( $extra_data['caption'] ) ? $extra_data['caption'] : '';
		$description = ! empty( $extra_data['description'] ) ? $extra_data['description'] : '';

		// Create the attachment post.
		$attachment_data = array(
			'post_mime_type' => $filetype['type'],
			'post_title'     => sanitize_text_field( $title ),
			'post_excerpt'   => sanitize_text_field( $caption ),
			'post_content'   => wp_kses_post( $description ),
			'post_status'    => 'inherit',
			'guid'           => $upload['url'],
		);

		$attachment_id = wp_insert_attachment( $attachment_data, $upload['file'], $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $upload['file'] );
			return $attachment_id;
		}

		// Generate and update attachment metadata (thumbnails, sizes).
		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		// Save alt text if provided.
		if ( ! empty( $extra_data['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $extra_data['alt'] ) );
		}

		// Store original source URL as meta for future duplicate detection.
		update_post_meta( $attachment_id, '_codixr_data_shifter_source_url', esc_url_raw( $url ) );

		return (int) $attachment_id;
	}

	/**
	 * Get featured image data for a post.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return array|null Array of image data or null if no featured image.
	 */
	public static function get_featured_image_data( $post_id ) {
		$thumbnail_id = get_post_thumbnail_id( $post_id );

		if ( empty( $thumbnail_id ) ) {
			return null;
		}

		return self::get_attachment_data( $thumbnail_id );
	}

	/**
	 * Get all media associated with a post (content inline images + attached gallery + custom field images).
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post $post                     The post object.
	 * @param bool    $include_attached_gallery Optional. Whether to include all attached media library items. Default false.
	 * @param array   $post_meta                Optional. Post meta array to extract custom field images from. Default empty array.
	 *
	 * @return array Array of media item arrays.
	 */
	public static function get_post_media( $post, $include_attached_gallery = false, $post_meta = array() ) {
		$media_items = array();
		$seen_urls   = array();
		$seen_ids    = array();

		// 1. Parse post_content for inline img tags.
		if ( ! empty( $post->post_content ) ) {
			$pattern = '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i';

			if ( preg_match_all( $pattern, $post->post_content, $matches ) ) {
				foreach ( $matches[1] as $img_url ) {
					if ( in_array( $img_url, $seen_urls, true ) ) {
						continue;
					}

					$seen_urls[] = $img_url;

					// Try to find this as an attachment in the media library.
					$attachment_id = function_exists( 'attachment_url_to_postid' ) ? attachment_url_to_postid( $img_url ) : 0;

					if ( $attachment_id ) {
						$seen_ids[] = (int) $attachment_id;
						$att_data   = self::get_attachment_data( $attachment_id );
						if ( $att_data ) {
							$att_data['content_url'] = $img_url;
							$media_items[]           = $att_data;
						}
					} else {
						// External or unattached image — check if present in local uploads folder.
						// Read the file only if it is a media file inside the uploads folder.
						$base64 = Codixr_Data_Shifter_Files::read_local_upload( $img_url );

						$alt           = '';
						$img_tag_index = array_search( $img_url, $matches[1], true );
						if ( false !== $img_tag_index ) {
							$full_tag = $matches[0][ $img_tag_index ];
							if ( preg_match( '/alt=["\']([^"\']*)["\']/', $full_tag, $alt_match ) ) {
								$alt = $alt_match[1];
							}
						}

						$media_items[] = array(
							'id'          => 0,
							'url'         => $img_url,
							'filename'    => basename( (string) wp_parse_url( $img_url, PHP_URL_PATH ) ),
							'title'       => '',
							'alt'         => $alt,
							'caption'     => '',
							'description' => '',
							'mime_type'   => '',
							'data'        => $base64,
						);
					}
				}
			}

			// Also check Gutenberg image blocks for an "id" attribute.
			if ( preg_match_all( '/<!--\s+wp:image\s+(\{[^}]+\})\s+-->/i', $post->post_content, $block_matches ) ) {
				foreach ( $block_matches[1] as $block_json ) {
					$block_data = json_decode( $block_json, true );
					if ( ! empty( $block_data['id'] ) && ! in_array( (int) $block_data['id'], $seen_ids, true ) ) {
						$b_id       = (int) $block_data['id'];
						$seen_ids[] = $b_id;
						$att_data   = self::get_attachment_data( $b_id );
						if ( $att_data && ! in_array( $att_data['url'], $seen_urls, true ) ) {
							$seen_urls[]   = $att_data['url'];
							$media_items[] = $att_data;
						}
					}
				}
			}
		}

		// 2. Scan Custom Post Type Meta (ACF, JetEngine, custom fields) for images.
		if ( ! empty( $post_meta ) && is_array( $post_meta ) ) {
			self::extract_media_from_meta( $post_meta, $seen_ids, $seen_urls, $media_items );
		}

		// 3. Include attached gallery media if requested.
		if ( $include_attached_gallery && function_exists( 'get_attached_media' ) ) {
			$attached_media = get_attached_media( 'image', $post->ID );
			if ( ! empty( $attached_media ) ) {
				foreach ( $attached_media as $attachment ) {
					if ( in_array( (int) $attachment->ID, $seen_ids, true ) ) {
						continue;
					}
					$att_url = wp_get_attachment_url( $attachment->ID );
					if ( $att_url && ! in_array( $att_url, $seen_urls, true ) ) {
						$seen_ids[]  = (int) $attachment->ID;
						$seen_urls[] = $att_url;
						$data        = self::get_attachment_data( $attachment->ID );
						if ( $data ) {
							$media_items[] = $data;
						}
					}
				}
			}
		}

		return $media_items;
	}

	/**
	 * Recursively scan post meta structures (including ACF arrays, galleries, and image IDs)
	 * and extract their media data into the media pool.
	 *
	 * @param mixed $data         Meta data to inspect.
	 * @param array &$seen_ids    Reference to already processed attachment IDs.
	 * @param array &$seen_urls   Reference to already processed URLs.
	 * @param array &$media_items Reference to collected media items list.
	 * @return void
	 */
	public static function extract_media_from_meta( $data, &$seen_ids, &$seen_urls, &$media_items ) {
		if ( empty( $data ) ) {
			return;
		}

		if ( is_string( $data ) && is_serialized( $data ) ) {
			$unserialized = @unserialize( $data, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Objects are disabled.
			if ( false !== $unserialized || 'b:0;' === $data ) {
				self::extract_media_from_meta( $unserialized, $seen_ids, $seen_urls, $media_items );
				return;
			}
		}

		if ( is_array( $data ) ) {
			foreach ( $data as $key => $val ) {
				self::extract_media_from_meta( $val, $seen_ids, $seen_urls, $media_items );
			}
			return;
		}

		// Check if value is a numeric attachment ID.
		if ( ( is_int( $data ) || ( is_string( $data ) && ctype_digit( (string) $data ) ) ) ) {
			$potential_id = (int) $data;
			if ( $potential_id > 0 && ! in_array( $potential_id, $seen_ids, true ) ) {
				$post = get_post( $potential_id );
				if ( $post && 'attachment' === $post->post_type ) {
					$seen_ids[] = $potential_id;
					$att_data   = self::get_attachment_data( $potential_id );
					if ( $att_data && ! in_array( $att_data['url'], $seen_urls, true ) ) {
						$seen_urls[]   = $att_data['url'];
						$media_items[] = $att_data;
					}
				}
			}
			return;
		}

		// Check if value is an image URL from uploads.
		if ( is_string( $data ) && filter_var( $data, FILTER_VALIDATE_URL ) ) {
			if ( preg_match( '/\.(jpe?g|png|gif|webp|svg)(\?.*)?$/i', $data ) ) {
				if ( ! in_array( $data, $seen_urls, true ) ) {
					$seen_urls[] = $data;

					$att_id = function_exists( 'attachment_url_to_postid' ) ? attachment_url_to_postid( $data ) : 0;
					if ( $att_id ) {
						$seen_ids[] = (int) $att_id;
						$att_data   = self::get_attachment_data( $att_id );
						if ( $att_data ) {
							$media_items[] = $att_data;
							return;
						}
					}

					// Read from local uploads if available.
					// Read the file only if it is a media file inside the uploads folder.
					$base64 = Codixr_Data_Shifter_Files::read_local_upload( $data );

					$media_items[] = array(
						'id'          => 0,
						'url'         => $data,
						'filename'    => basename( (string) wp_parse_url( $data, PHP_URL_PATH ) ),
						'title'       => '',
						'alt'         => '',
						'caption'     => '',
						'description' => '',
						'mime_type'   => '',
						'data'        => $base64,
					);
				}
			}
		}
	}

	/**
	 * Get data for a specific attachment, including its binary content.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id The attachment post ID.
	 *
	 * @return array|null Array of attachment data or null on failure.
	 */
	public static function get_attachment_data( $attachment_id ) {
		$attachment = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return null;
		}

		$url = wp_get_attachment_url( $attachment_id );
		if ( ! $url ) {
			return null;
		}

		// Try getting attached file path.
		$file_path = get_attached_file( $attachment_id );

		// Fallback: look up in wp_upload_dir if path is missing or relative.
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			$rel = get_post_meta( $attachment_id, '_wp_attached_file', true );
			if ( $rel ) {
				$upload_dir = wp_upload_dir();
				$alt_path   = path_join( $upload_dir['basedir'], $rel );
				if ( file_exists( $alt_path ) ) {
					$file_path = $alt_path;
				}
			}
		}

		$base64 = '';
		if ( $file_path && file_exists( $file_path ) && filesize( $file_path ) < 15728640 ) { // Up to 15 MB.
			$contents = @file_get_contents( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing files are handled by the caller.
			if ( false !== $contents ) {
				$base64 = base64_encode( $contents ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Bundles image bytes inside export packages.
			}
		}

		return array(
			'id'          => (int) $attachment_id,
			'url'         => $url,
			'filename'    => basename( (string) $file_path ),
			'title'       => $attachment->post_title,
			'alt'         => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'caption'     => $attachment->post_excerpt,
			'description' => $attachment->post_content,
			'mime_type'   => $attachment->post_mime_type,
			'data'        => $base64,
		);
	}

	/**
	 * Find an existing attachment that was previously imported from the given URL.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url The source URL to search for.
	 *
	 * @return int|false Attachment ID if found, false otherwise.
	 */
	public static function find_existing_attachment_by_url( $url ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact lookup of an attachment by its stored source URL.
		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
                WHERE meta_key IN ( '_codixr_data_shifter_source_url', '_data_shifter_source_url', '_datashift_source_url' )
                AND meta_value = %s
                LIMIT 1",
				esc_url_raw( $url )
			)
		);

		if ( $attachment_id ) {
			$post = get_post( (int) $attachment_id );
			if ( $post && 'attachment' === $post->post_type ) {
				return (int) $attachment_id;
			}
		}

		return false;
	}

	/**
	 * Recursively replace old local URLs and old attachment IDs in post meta
	 * (supporting strings, numbers, arrays, and serialized structures).
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $value           Meta value to transform.
	 * @param array  $url_map         Map of old URL => new live URL.
	 * @param array  $id_map          Map of old attachment ID => new attachment ID.
	 * @param string $source_site_url Old source site base URL (e.g. http://mysite.local).
	 * @param string $target_site_url New target site base URL (e.g. https://livesite.com).
	 *
	 * @return mixed Transformed meta value.
	 */
	public static function replace_meta_urls_and_ids( $value, $url_map = array(), $id_map = array(), $source_site_url = '', $target_site_url = '' ) {
		if ( empty( $value ) ) {
			return $value;
		}

		// If serialized string, unserialize, replace recursively, then re-serialize.
		if ( is_string( $value ) && is_serialized( $value ) ) {
			$unserialized = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Objects are disabled.
			if ( false !== $unserialized || 'b:0;' === $value ) {
				$replaced = self::replace_meta_urls_and_ids( $unserialized, $url_map, $id_map, $source_site_url, $target_site_url );
				return serialize( $replaced ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Writes the value back in the format it was read.
			}
		}

		// If array, recurse on each element.
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::replace_meta_urls_and_ids( $v, $url_map, $id_map, $source_site_url, $target_site_url );
			}
			return $value;
		}

		// If numeric attachment ID.
		if ( ( is_int( $value ) || ( is_string( $value ) && ctype_digit( (string) $value ) ) ) && ! empty( $id_map ) ) {
			$int_val = (int) $value;
			if ( isset( $id_map[ $int_val ] ) ) {
				return is_int( $value ) ? (int) $id_map[ $int_val ] : (string) $id_map[ $int_val ];
			}
		}

		// If string, replace mapped URLs and base site URLs.
		if ( is_string( $value ) ) {
			if ( ! empty( $url_map ) ) {
				foreach ( $url_map as $old_url => $new_url ) {
					if ( ! empty( $old_url ) && ! empty( $new_url ) ) {
						$value = str_replace( $old_url, $new_url, $value );
					}
				}
			}
			if ( ! empty( $source_site_url ) && ! empty( $target_site_url ) && $source_site_url !== $target_site_url ) {
				$value = str_replace( $source_site_url, $target_site_url, $value );
			}
		}

		return $value;
	}
}
