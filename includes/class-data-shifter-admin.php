<?php
/**
 * Data Shifter Admin Class.
 *
 * Handles admin menus, page rendering, and asset enqueueing.
 *
 * @package Data_Shifter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Data_Shifter_Admin
 */
class Data_Shifter_Admin {

	/**
	 * Array of plugin page hook suffixes used for conditional asset loading.
	 *
	 * @var array
	 */
	private $page_hooks = array();

	/**
	 * Constructor — register all admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DATA_SHIFTER_PATH . 'data-shifter.php' ), array( $this, 'add_plugin_action_links' ) );
		add_filter( 'admin_footer_text', array( $this, 'admin_footer_text' ) );
	}

	/**
	 * Register admin menus under Tools.
	 *
	 * @return void
	 */
	public function register_menus() {
		$this->page_hooks[] = add_menu_page(
			__( 'Data Shifter', 'data-shifter' ),
			__( 'Data Shifter', 'data-shifter' ),
			'manage_options',
			'data-shifter',
			array( $this, 'render_export_page' ),
			'dashicons-database-export',
			76
		);

		$this->page_hooks[] = add_submenu_page(
			'data-shifter',
			__( 'Export', 'data-shifter' ),
			__( 'Export', 'data-shifter' ),
			'manage_options',
			'data-shifter',
			array( $this, 'render_export_page' )
		);

		$this->page_hooks[] = add_submenu_page(
			'data-shifter',
			__( 'Import', 'data-shifter' ),
			__( 'Import', 'data-shifter' ),
			'manage_options',
			'data-shifter-import',
			array( $this, 'render_import_page' )
		);
	}

	/**
	 * Enqueue CSS and JS on plugin pages only.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, $this->page_hooks, true ) ) {
			return;
		}

		wp_enqueue_style(
			'data-shifter-admin',
			DATA_SHIFTER_URL . 'assets/css/admin.css',
			array(),
			DATA_SHIFTER_VERSION
		);

		wp_enqueue_script(
			'data-shifter-admin',
			DATA_SHIFTER_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			DATA_SHIFTER_VERSION,
			true
		);

		wp_localize_script(
			'data-shifter-admin',
			'data_shifter_ajax',
			array(
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'data_shifter_nonce' ),
				'chunk_size' => $this->get_chunk_size(),
				'strings'    => array(
					'export_success' => __( 'Export completed successfully.', 'data-shifter' ),
					'export_error'   => __( 'An error occurred during export.', 'data-shifter' ),
					'import_success' => __( 'Import completed successfully.', 'data-shifter' ),
					'import_error'   => __( 'An error occurred during import.', 'data-shifter' ),
					'confirm_import' => __( 'Are you sure you want to run this import? This action cannot be undone.', 'data-shifter' ),
					'processing'     => __( 'Processing…', 'data-shifter' ),
					'downloading'    => __( 'Downloading…', 'data-shifter' ),
					'uploading'      => __( 'Uploading…', 'data-shifter' ),
				),
			)
		);
	}

	/**
	 * Render the Export admin page.
	 *
	 * @return void
	 */
	public function render_export_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'data-shifter' ) );
		}
		include DATA_SHIFTER_VIEWS . 'export-page.php';
	}

	/**
	 * Render the Import admin page.
	 *
	 * @return void
	 */
	public function render_import_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'data-shifter' ) );
		}
		include DATA_SHIFTER_VIEWS . 'import-page.php';
	}

	/**
	 * Show a short support line in the admin footer on Data Shifter screens.
	 *
	 * @param string $text Default footer text.
	 * @return string
	 */
	public function admin_footer_text( $text ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, $this->page_hooks, true ) ) {
			return $text;
		}
		return sprintf(
			/* translators: 1: Codixr website link, 2: support email link */
			esc_html__( 'Data Shifter is built by %1$s, a software agency. Need help? Email %2$s.', 'data-shifter' ),
			'<a href="https://codixr.com" target="_blank" rel="noopener noreferrer">Codixr</a>',
			'<a href="mailto:support@codixr.com">support@codixr.com</a>'
		);
	}

	/**
	 * Add a "Settings" link on the Plugins list page.
	 *
	 * @param array $links Existing plugin action links.
	 * @return array Modified plugin action links.
	 */
	public function add_plugin_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=data-shifter' ) ),
			esc_html__( 'Open Data Shifter', 'data-shifter' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Pick an upload chunk size that fits the server's PHP upload limits.
	 *
	 * Uses 4 MB when the server allows it, otherwise a size just under the
	 * smaller of upload_max_filesize and post_max_size (minimum 256 KB).
	 *
	 * @return int Chunk size in bytes.
	 */
	private function get_chunk_size() {
		$preferred = 4 * MB_IN_BYTES;
		$limits    = array_filter(
			array(
				wp_convert_hr_to_bytes( (string) ini_get( 'upload_max_filesize' ) ),
				wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) ),
			)
		);
		if ( empty( $limits ) ) {
			return $preferred;
		}
		$server_limit = (int) min( $limits );
		// Leave room for the other form fields in the request.
		$fits = $server_limit - ( 64 * KB_IN_BYTES );
		return (int) max( 256 * KB_IN_BYTES, min( $preferred, $fits ) );
	}
}
