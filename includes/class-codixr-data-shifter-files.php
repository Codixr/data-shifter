<?php
/**
 * Data Shifter temporary file helpers.
 *
 * Central place for the protected temp folder, unguessable tokens, file
 * ownership, ZIP entry validation and CSV cell escaping.
 *
 * @package Codixr_Data_Shifter
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * The deny rules are tiny static files written with native functions.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
 */

/**
 * Class Codixr_Data_Shifter_Files
 */
class Codixr_Data_Shifter_Files {

	/**
	 * How long upload, ownership and session records live (seconds).
	 */
	const TTL = DAY_IN_SECONDS;

	/**
	 * File extensions that may be imported or exported as data files.
	 *
	 * @var string[]
	 */
	const DATA_EXTENSIONS = array( 'json', 'csv', 'xml' );

	/**
	 * Media extensions that may appear inside a ZIP package (under media/).
	 *
	 * @var string[]
	 */
	const MEDIA_EXTENSIONS = array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico', 'tif', 'tiff', 'heic', 'mp3', 'm4a', 'ogg', 'wav', 'mp4', 'm4v', 'mov', 'webm', 'pdf' );

	/**
	 * Extensions that are never accepted anywhere in a file name, even as an
	 * inner extension (for example image.php.jpg).
	 *
	 * @var string[]
	 */
	const BLOCKED_EXTENSIONS = array( 'php', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'inc', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'asp', 'aspx', 'jsp', 'exe', 'bat', 'cmd', 'dll', 'so', 'js', 'mjs', 'html', 'htm', 'shtml', 'svg', 'svgz', 'htaccess', 'htpasswd', 'ini' );

	/**
	 * Absolute path of the plugin base folder inside uploads, with trailing slash.
	 *
	 * @return string
	 */
	public static function get_base_dir() {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . CODIXR_DATA_SHIFTER_SLUG . '/';
	}

	/**
	 * Absolute path of the protected temp folder, with trailing slash.
	 *
	 * Creates the folder and its deny rules when missing.
	 *
	 * @return string
	 */
	public static function get_temp_dir() {
		$temp = self::get_base_dir() . 'temp/';
		if ( ! is_dir( $temp ) ) {
			wp_mkdir_p( $temp );
		}
		self::protect_dirs();
		return $temp;
	}

	/**
	 * Write index.php files and an .htaccess deny rule into the plugin folders.
	 *
	 * @return void
	 */
	public static function protect_dirs() {
		$base = self::get_base_dir();
		$temp = $base . 'temp/';

		foreach ( array( $base, $temp ) as $dir ) {
			if ( is_dir( $dir ) && ! file_exists( $dir . 'index.php' ) ) {
				file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
			}
		}
		if ( is_dir( $base ) && ! file_exists( $base . '.htaccess' ) ) {
			file_put_contents( $base . '.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n" );
		}
	}

	/**
	 * Return a long random token (32 letters and digits).
	 *
	 * @return string
	 */
	public static function token() {
		return wp_generate_password( 32, false, false );
	}

	/**
	 * Keep only letters and digits from a token sent by the browser.
	 *
	 * @param mixed $value Raw value.
	 * @return string Sanitized token, or an empty string when it is not 32 characters long.
	 */
	public static function sanitize_token( $value ) {
		$value = preg_replace( '/[^A-Za-z0-9]/', '', (string) $value );
		return 32 === strlen( $value ) ? $value : '';
	}

	/**
	 * Build a unique temp file name with a long random part.
	 *
	 * @param string $prefix    Readable prefix, for example 'export-post'.
	 * @param string $extension File extension without the dot.
	 * @return string
	 */
	public static function unique_filename( $prefix, $extension ) {
		return sanitize_file_name( CODIXR_DATA_SHIFTER_SLUG . '-' . $prefix . '-' . gmdate( 'Y-m-d-His' ) . '-' . self::token() . '.' . $extension );
	}

	/**
	 * Record that the current user owns a temp file.
	 *
	 * @param string $filename Base file name inside the temp folder.
	 * @return void
	 */
	public static function set_owner( $filename ) {
		set_transient( 'codixr_data_shifter_owner_' . md5( $filename ), get_current_user_id(), self::TTL );
	}

	/**
	 * Check that the current user owns a temp file.
	 *
	 * @param string $filename Base file name inside the temp folder.
	 * @return bool
	 */
	public static function is_owner( $filename ) {
		$owner = get_transient( 'codixr_data_shifter_owner_' . md5( $filename ) );
		return false !== $owner && get_current_user_id() === (int) $owner && get_current_user_id() > 0;
	}

	/**
	 * Forget the owner of a temp file.
	 *
	 * @param string $filename Base file name inside the temp folder.
	 * @return void
	 */
	public static function forget_owner( $filename ) {
		delete_transient( 'codixr_data_shifter_owner_' . md5( $filename ) );
	}

	/**
	 * Check whether a file name carries a blocked extension anywhere in it.
	 *
	 * @param string $name File name or path.
	 * @return bool
	 */
	public static function has_blocked_extension( $name ) {
		$base  = strtolower( wp_basename( str_replace( '\\', '/', (string) $name ) ) );
		$parts = explode( '.', $base );
		array_shift( $parts ); // The part before the first dot is the name, not an extension.
		foreach ( $parts as $part ) {
			if ( in_array( $part, self::BLOCKED_EXTENSIONS, true ) ) {
				return true;
			}
		}
		// Files such as .htaccess or .user.ini start with a dot.
		return in_array( $base, array( '.htaccess', '.htpasswd', '.user.ini', 'web.config' ), true );
	}

	/**
	 * Validate one entry of an import ZIP package.
	 *
	 * Only data.json at the root and media files inside media/ are accepted.
	 * Absolute paths, parent-directory segments and executable types are rejected.
	 *
	 * @param string $name Entry name as stored in the ZIP.
	 * @return true|WP_Error
	 */
	public static function validate_zip_entry( $name ) {
		$name = (string) $name;
		$bad  = new WP_Error(
			'codixr_data_shifter_zip_entry_rejected',
			sprintf(
				/* translators: %s: file name inside the ZIP package */
				__( 'The ZIP package contains a file that is not allowed: %s. Only data.json and media files in the media folder can be imported.', 'data-shifter' ),
				sanitize_text_field( $name )
			)
		);

		if ( '' === $name || false !== strpos( $name, "\0" ) || false !== strpos( $name, '\\' ) || '/' === $name[0] || preg_match( '#^[A-Za-z]:#', $name ) ) {
			return $bad;
		}
		foreach ( explode( '/', $name ) as $segment ) {
			if ( '..' === $segment || '.' === $segment ) {
				return $bad;
			}
		}
		if ( self::has_blocked_extension( $name ) ) {
			return $bad;
		}
		if ( 'data.json' === $name || 'media/' === $name ) {
			return true;
		}
		if ( 0 === strpos( $name, 'media/' ) && false === strpos( substr( $name, 6 ), '/' ) ) {
			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, self::MEDIA_EXTENSIONS, true ) ) {
				return true;
			}
		}
		return $bad;
	}

	/**
	 * Neutralize spreadsheet formulas in a CSV cell.
	 *
	 * Cells that start with =, +, -, @, a tab or a carriage return are prefixed
	 * with a single quote so spreadsheet apps show them as text.
	 *
	 * @param mixed $value Cell value.
	 * @return mixed
	 */
	public static function csv_safe_cell( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		if ( in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Whether a file name is an allowed media file for a ZIP package.
	 *
	 * @param string $name File name.
	 * @return bool
	 */
	public static function is_media_filename( $name ) {
		$ext = strtolower( pathinfo( (string) $name, PATHINFO_EXTENSION ) );
		return in_array( $ext, self::MEDIA_EXTENSIONS, true ) && ! self::has_blocked_extension( $name );
	}

	/**
	 * Undo csv_safe_cell() when a Data Shifter CSV is imported again.
	 *
	 * Removes the leading single quote only when it protects a formula character.
	 *
	 * @param mixed $value Cell value.
	 * @return mixed
	 */
	public static function csv_unescape_cell( $value ) {
		if ( is_string( $value ) && strlen( $value ) > 1 && "'" === $value[0] && in_array( $value[1], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return substr( $value, 1 );
		}
		return $value;
	}

	/**
	 * Read a media file from this site's uploads folder by URL and return it base64-encoded.
	 *
	 * The resolved path must stay inside the uploads folder, must not be in the
	 * plugin temp folder, must be a media type and must be smaller than 15 MB.
	 *
	 * @param string $url File URL found in post content or meta.
	 * @return string Base64 data, or an empty string when the file is not eligible.
	 */
	public static function read_local_upload( $url ) {
		$upload_dir    = wp_upload_dir();
		$clean_url     = preg_replace( '/^https?:/i', '', (string) strtok( (string) $url, '?#' ) );
		$clean_baseurl = preg_replace( '/^https?:/i', '', untrailingslashit( $upload_dir['baseurl'] ) );

		if ( '' === $clean_baseurl || 0 !== strpos( $clean_url, $clean_baseurl . '/' ) ) {
			return '';
		}

		$rel_path = rawurldecode( substr( $clean_url, strlen( $clean_baseurl ) ) );
		$ext      = strtolower( pathinfo( $rel_path, PATHINFO_EXTENSION ) );
		if ( false !== strpos( $rel_path, "\0" ) || ! in_array( $ext, self::MEDIA_EXTENSIONS, true ) || self::has_blocked_extension( $rel_path ) ) {
			return '';
		}

		$base = realpath( $upload_dir['basedir'] );
		$file = realpath( untrailingslashit( $upload_dir['basedir'] ) . $rel_path );
		if ( ! $base || ! $file || ! is_file( $file ) ) {
			return '';
		}
		$base = trailingslashit( wp_normalize_path( $base ) );
		$file = wp_normalize_path( $file );
		if ( 0 !== strpos( $file, $base ) || 0 === strpos( $file, $base . CODIXR_DATA_SHIFTER_SLUG . '/' ) || filesize( $file ) >= 15728640 ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file inside uploads, checked above.
		$contents = file_get_contents( $file );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Bundles image bytes inside export packages.
		return false !== $contents ? base64_encode( $contents ) : '';
	}
}
