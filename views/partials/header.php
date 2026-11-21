<?php
/**
 * Data Shifter shared page header with navigation tabs.
 *
 * Expects $codixr_data_shifter_current_tab to be set to 'export' or 'import'.
 *
 * @package Codixr_Data_Shifter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$codixr_data_shifter_current_tab = isset( $codixr_data_shifter_current_tab ) ? $codixr_data_shifter_current_tab : 'export';
$codixr_data_shifter_tabs        = array(
	'export' => array(
		'label' => __( 'Export', 'data-shifter' ),
		'icon'  => 'dashicons-database-export',
		'url'   => admin_url( 'admin.php?page=data-shifter' ),
	),
	'import' => array(
		'label' => __( 'Import', 'data-shifter' ),
		'icon'  => 'dashicons-database-import',
		'url'   => admin_url( 'admin.php?page=data-shifter-import' ),
	),
);
?>
<header class="data-shifter-header">
	<div class="data-shifter-header__title">
		<img class="data-shifter-header__logo-img" src="<?php echo esc_url( CODIXR_DATA_SHIFTER_URL . 'assets/images/data-shifter-icon.png' ); ?>" width="44" height="44" alt="" />
		<div>
			<h1><?php echo esc_html( 'export' === $codixr_data_shifter_current_tab ? __( 'Data Shifter Export', 'data-shifter' ) : __( 'Data Shifter Import', 'data-shifter' ) ); ?></h1>
			<p class="data-shifter-header__subtitle">
				<?php
				echo esc_html(
					'export' === $codixr_data_shifter_current_tab
						? __( 'Choose content, filter it, pick fields and download a file you can import on any site.', 'data-shifter' )
						: __( 'Upload a Data Shifter, CSV or XML file, review a preview, then import it into this site.', 'data-shifter' )
				);
				?>
			</p>
		</div>
	</div>
	<nav class="nav-tab-wrapper data-shifter-tabs" aria-label="<?php esc_attr_e( 'Data Shifter sections', 'data-shifter' ); ?>">
		<?php foreach ( $codixr_data_shifter_tabs as $codixr_data_shifter_key => $codixr_data_shifter_tab ) : ?>
			<a href="<?php echo esc_url( $codixr_data_shifter_tab['url'] ); ?>" class="nav-tab<?php echo $codixr_data_shifter_key === $codixr_data_shifter_current_tab ? ' nav-tab-active' : ''; ?>"<?php echo $codixr_data_shifter_key === $codixr_data_shifter_current_tab ? ' aria-current="page"' : ''; ?>>
				<span class="dashicons <?php echo esc_attr( $codixr_data_shifter_tab['icon'] ); ?>" aria-hidden="true"></span>
				<?php echo esc_html( $codixr_data_shifter_tab['label'] ); ?>
			</a>
		<?php endforeach; ?>
	</nav>
</header>
<hr class="wp-header-end">
