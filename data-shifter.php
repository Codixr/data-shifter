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
 * @package Codixr_Data_Shifter
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
			// Shown only on the Plugins screen, to users who manage plugins, and dismissible (Guideline 11).
			global $pagenow;
			if ( 'plugins.php' !== $pagenow || ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error is-dismissible"><p>';
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
			// Shown only on the Plugins screen, to users who manage plugins, and dismissible (Guideline 11).
			global $pagenow;
			if ( 'plugins.php' !== $pagenow || ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error is-dismissible"><p>';
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
define( 'CODIXR_DATA_SHIFTER_VERSION', '1.0.0' );
define( 'CODIXR_DATA_SHIFTER_SLUG', 'data-shifter' );
define( 'CODIXR_DATA_SHIFTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'CODIXR_DATA_SHIFTER_URL', plugin_dir_url( __FILE__ ) );
define( 'CODIXR_DATA_SHIFTER_INCLUDES', CODIXR_DATA_SHIFTER_PATH . 'includes/' );
define( 'CODIXR_DATA_SHIFTER_VIEWS', CODIXR_DATA_SHIFTER_PATH . 'views/' );

/**
 * Include required files.
 */
require_once CODIXR_DATA_SHIFTER_INCLUDES . 'class-codixr-data-shifter-files.php';
require_once CODIXR_DATA_SHIFTER_INCLUDES . 'class-codixr-data-shifter-admin.php';
require_once CODIXR_DATA_SHIFTER_INCLUDES . 'class-codixr-data-shifter-exporter.php';
require_once CODIXR_DATA_SHIFTER_INCLUDES . 'class-codixr-data-shifter-importer.php';
require_once CODIXR_DATA_SHIFTER_INCLUDES . 'class-codixr-data-shifter-media.php';
require_once CODIXR_DATA_SHIFTER_INCLUDES . 'class-codixr-data-shifter-ajax.php';

/**
 * Initialize the plugin on plugins_loaded.
 */
add_action( 'plugins_loaded', 'codixr_data_shifter_init' );

/**
 * Raise the reported maximum upload size on Data Shifter screens and requests only.
 *
 * Imports are uploaded in chunks, so this only changes the limit shown and
 * checked on Data Shifter pages. Other screens keep the server default.
 *
 * @param int $size Current upload size limit in bytes.
 * @return int
 */
function codixr_data_shifter_upload_size_limit( $size ) {
	// phpcs:disable WordPress.Security.NonceVerification -- Read-only check of the current page or action; no data is changed here.
	$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification
	if ( 0 === strpos( $page, 'data-shifter' ) || 0 === strpos( $action, 'codixr_data_shifter_' ) ) {
		return max( $size, 1024 * 1024 * 1024 ); // 1 GB.
	}
	return $size;
}
add_filter( 'upload_size_limit', 'codixr_data_shifter_upload_size_limit' );

/**
 * Bootstrap all plugin components.
 *
 * @return void
 */
function codixr_data_shifter_init() {
	if ( is_admin() ) {
		new Codixr_Data_Shifter_Admin();
		new Codixr_Data_Shifter_Ajax();
	}
}

/**
 * Activation hook — create the temp upload directory.
 *
 * @return void
 */
function codixr_data_shifter_activate() {
	// Creates uploads/<slug>/temp/ with index.php files and an .htaccess deny rule.
	Codixr_Data_Shifter_Files::get_temp_dir();
}
register_activation_hook( __FILE__, 'codixr_data_shifter_activate' );

/**
 * Deactivation hook — clean temp files.
 *
 * @return void
 */
function codixr_data_shifter_deactivate() {
	$temp = Codixr_Data_Shifter_Files::get_base_dir() . 'temp';

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
register_deactivation_hook( __FILE__, 'codixr_data_shifter_deactivate' );
