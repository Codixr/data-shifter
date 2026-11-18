<?php
/**
 * Plugin Name:       Data Shifter
 * Description:       Export and import WordPress content, including posts, pages, custom post types, custom fields, taxonomies, comments and media, in JSON, ZIP, CSV or XML.
 * Version:           1.0.0
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            Codixr
 * Author URI:        https://codixr.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       data-shifter
 * Domain Path:       /languages
 *
 * @package Data_Shifter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check minimum PHP version.
 */
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
				/* translators: %s: required PHP version */
					__( 'Data Shifter requires PHP %s or higher. Please update your PHP version.', 'data-shifter' ),
					'7.4'
				)
			);
			echo '</p></div>';
		}
	);
	return;
}

/**
 * Check minimum WordPress version.
 */
if ( version_compare( get_bloginfo( 'version' ), '5.0', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
				/* translators: %s: required WordPress version */
					__( 'Data Shifter requires WordPress %s or higher. Please update WordPress.', 'data-shifter' ),
					'5.0'
				)
			);
			echo '</p></div>';
		}
	);
	return;
}

/**
 * Plugin constants.
 */
define( 'DATA_SHIFTER_VERSION', '1.0.0' );
define( 'DATA_SHIFTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'DATA_SHIFTER_URL', plugin_dir_url( __FILE__ ) );
define( 'DATA_SHIFTER_INCLUDES', DATA_SHIFTER_PATH . 'includes/' );
define( 'DATA_SHIFTER_VIEWS', DATA_SHIFTER_PATH . 'views/' );

/**
 * Include required files.
 */
require_once DATA_SHIFTER_INCLUDES . 'class-data-shifter-admin.php';
require_once DATA_SHIFTER_INCLUDES . 'class-data-shifter-exporter.php';
require_once DATA_SHIFTER_INCLUDES . 'class-data-shifter-importer.php';
require_once DATA_SHIFTER_INCLUDES . 'class-data-shifter-media.php';
require_once DATA_SHIFTER_INCLUDES . 'class-data-shifter-ajax.php';

/**
 * Initialize the plugin on plugins_loaded.
 */
add_action( 'plugins_loaded', 'data_shifter_init' );

/**
 * Raise the reported maximum upload size on Data Shifter screens and requests only.
 *
 * Imports are uploaded in chunks, so this only changes the limit shown and
 * checked on Data Shifter pages. Other screens keep the server default.
 *
 * @param int $size Current upload size limit in bytes.
 * @return int
 */
function data_shifter_upload_size_limit( $size ) {
	// phpcs:disable WordPress.Security.NonceVerification -- Read-only check of the current page or action; no data is changed here.
	$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification
	if ( 0 === strpos( $page, 'data-shifter' ) || 0 === strpos( $action, 'data_shifter_' ) ) {
		return max( $size, 1024 * 1024 * 1024 ); // 1 GB.
	}
	return $size;
}
add_filter( 'upload_size_limit', 'data_shifter_upload_size_limit' );

/**
 * Bootstrap all plugin components.
 *
 * @return void
 */
function data_shifter_init() {
	if ( is_admin() ) {
		new Data_Shifter_Admin();
		new Data_Shifter_Ajax();
	}
}

/**
 * Activation hook — create the temp upload directory.
 *
 * @return void
 */
function data_shifter_activate() {
	$upload_dir = wp_upload_dir();
	$base       = trailingslashit( $upload_dir['basedir'] ) . 'data-shifter';
	$temp       = trailingslashit( $base ) . 'temp';

	if ( ! file_exists( $base ) ) {
		wp_mkdir_p( $base );
	}
	if ( ! file_exists( $temp ) ) {
		wp_mkdir_p( $temp );
	}

	// Protect the directory with an index file and .htaccess.
	// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Small static files written once on activation.
	if ( ! file_exists( trailingslashit( $base ) . 'index.php' ) ) {
		file_put_contents( trailingslashit( $base ) . 'index.php', '<?php // Silence is golden.' );
	}
	if ( ! file_exists( trailingslashit( $temp ) . 'index.php' ) ) {
		file_put_contents( trailingslashit( $temp ) . 'index.php', '<?php // Silence is golden.' );
	}
	if ( ! file_exists( trailingslashit( $base ) . '.htaccess' ) ) {
		file_put_contents( trailingslashit( $base ) . '.htaccess', "Options -Indexes\nDeny from all" );
	}
	// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
}
register_activation_hook( __FILE__, 'data_shifter_activate' );

/**
 * Deactivation hook — clean temp files.
 *
 * @return void
 */
function data_shifter_deactivate() {
	$upload_dir = wp_upload_dir();
	$temp       = trailingslashit( $upload_dir['basedir'] ) . 'data-shifter/temp';

	if ( is_dir( $temp ) ) {
		$files = glob( trailingslashit( $temp ) . '*' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				if ( is_file( $file ) && basename( $file ) !== 'index.php' ) {
					wp_delete_file( $file );
				}
			}
		}
	}
}
register_deactivation_hook( __FILE__, 'data_shifter_deactivate' );
