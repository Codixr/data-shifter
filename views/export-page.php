<?php
/**
 * Data Shifter — Export Page Template
 *
 * Multi-step wizard for exporting WordPress data.
 *
 * @package Codixr_Data_Shifter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap data-shifter-wrap">
	<?php
	$codixr_data_shifter_current_tab = 'export';
	require CODIXR_DATA_SHIFTER_VIEWS . 'partials/header.php';
	?>

	<!-- Progress Steps Bar -->
	<ol class="data-shifter-steps" aria-label="<?php esc_attr_e( 'Export steps', 'data-shifter' ); ?>">
		<li class="step active" data-step="1">
			<span class="step-number">1</span>
			<span class="step-label"><?php esc_html_e( 'Select Data', 'data-shifter' ); ?></span>
		</li>
		<li class="step" data-step="2">
			<span class="step-number">2</span>
			<span class="step-label"><?php esc_html_e( 'Filter', 'data-shifter' ); ?></span>
		</li>
		<li class="step" data-step="3">
			<span class="step-number">3</span>
			<span class="step-label"><?php esc_html_e( 'Fields', 'data-shifter' ); ?></span>
		</li>
		<li class="step" data-step="4">
			<span class="step-number">4</span>
			<span class="step-label"><?php esc_html_e( 'Options', 'data-shifter' ); ?></span>
		</li>
		<li class="step" data-step="5">
			<span class="step-number">5</span>
			<span class="step-label"><?php esc_html_e( 'Export', 'data-shifter' ); ?></span>
		</li>
	</ol>

	<!-- Step 1: Select Data Type -->
	<div class="step-panel active" id="step-panel-1" data-step="1">
		<div class="step-panel-header">
			<h2><?php esc_html_e( 'Select Data Type', 'data-shifter' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Choose the type of content you want to export.', 'data-shifter' ); ?></p>
		</div>
		<div class="data-shifter-post-type-grid" id="data-shifter-post-type-grid">
			<div class="data-shifter-loading">
				<span class="spinner is-active"></span>
				<p><?php esc_html_e( 'Loading post types…', 'data-shifter' ); ?></p>
			</div>
		</div>
		<div class="step-nav">
			<span></span>
			<button type="button" class="button button-primary data-shifter-next-step" disabled>
				<?php esc_html_e( 'Next: Filters', 'data-shifter' ); ?> <span aria-hidden="true" class="dashicons dashicons-arrow-right-alt2"></span>
			</button>
		</div>
	</div>

	<!-- Step 2: Filters -->
	<div class="step-panel" id="step-panel-2" data-step="2">
		<div class="step-panel-header">
			<h2><?php esc_html_e( 'Filter Your Data', 'data-shifter' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Optionally narrow down the data to export. All filters are optional.', 'data-shifter' ); ?></p>
		</div>
		<div class="data-shifter-filter-form">
			<div class="filter-grid">
				<!-- Status -->
				<div class="filter-group">
					<label for="filter-status"><?php esc_html_e( 'Post Status', 'data-shifter' ); ?></label>
					<select id="filter-status" class="data-shifter-select">
						<option value=""><?php esc_html_e( 'All Statuses', 'data-shifter' ); ?></option>
						<option value="publish"><?php esc_html_e( 'Published', 'data-shifter' ); ?></option>
						<option value="draft"><?php esc_html_e( 'Draft', 'data-shifter' ); ?></option>
						<option value="pending"><?php esc_html_e( 'Pending Review', 'data-shifter' ); ?></option>
						<option value="private"><?php esc_html_e( 'Private', 'data-shifter' ); ?></option>
						<option value="trash"><?php esc_html_e( 'Trash', 'data-shifter' ); ?></option>
					</select>
				</div>

				<!-- Date Range -->
				<div class="filter-group">
					<label><?php esc_html_e( 'Date Range', 'data-shifter' ); ?></label>
					<div class="date-range-inputs">
						<input type="date" id="filter-date-from" class="data-shifter-input" placeholder="<?php esc_attr_e( 'From', 'data-shifter' ); ?>">
						<span class="date-separator"><?php esc_html_e( 'to', 'data-shifter' ); ?></span>
						<input type="date" id="filter-date-to" class="data-shifter-input" placeholder="<?php esc_attr_e( 'To', 'data-shifter' ); ?>">
					</div>
				</div>

				<!-- Taxonomy Filter -->
				<div class="filter-group filter-group-full">
					<label for="filter-taxonomy"><?php esc_html_e( 'Taxonomy Filter', 'data-shifter' ); ?></label>
					<select id="filter-taxonomy" class="data-shifter-select">
						<option value=""><?php esc_html_e( 'Select a taxonomy…', 'data-shifter' ); ?></option>
					</select>
					<div class="taxonomy-terms" id="filter-taxonomy-terms" style="display:none;">
						<p class="description"><?php esc_html_e( 'Select terms to include:', 'data-shifter' ); ?></p>
						<div class="term-checkboxes" id="term-checkboxes"></div>
					</div>
				</div>

				<!-- Author Filter -->
				<div class="filter-group">
					<label for="filter-author"><?php esc_html_e( 'Author', 'data-shifter' ); ?></label>
					<?php
					wp_dropdown_users(
						array(
							'show_option_all' => __( 'All Authors', 'data-shifter' ),
							'id'              => 'filter-author',
							'name'            => 'filter-author',
							'class'           => 'data-shifter-select',
						)
					);
					?>
				</div>

				<!-- Specific Post IDs -->
				<div class="filter-group">
					<label for="filter-post-ids"><?php esc_html_e( 'Specific Post IDs', 'data-shifter' ); ?></label>
					<input type="text" id="filter-post-ids" class="data-shifter-input" placeholder="<?php esc_attr_e( 'e.g. 1, 5, 23, 42', 'data-shifter' ); ?>">
					<p class="field-description"><?php esc_html_e( 'Comma-separated list of post IDs.', 'data-shifter' ); ?></p>
				</div>

				<!-- Search -->
				<div class="filter-group filter-group-full">
					<label for="filter-search"><?php esc_html_e( 'Search Keyword', 'data-shifter' ); ?></label>
					<input type="text" id="filter-search" class="data-shifter-input" placeholder="<?php esc_attr_e( 'Search within post title and content…', 'data-shifter' ); ?>">
				</div>
			</div>
		</div>
		<div class="step-nav">
			<button type="button" class="button data-shifter-prev-step">
				<span aria-hidden="true" class="dashicons dashicons-arrow-left-alt2"></span> <?php esc_html_e( 'Back', 'data-shifter' ); ?>
			</button>
			<div class="step-nav-right">
				<button type="button" class="button data-shifter-skip-step">
					<?php esc_html_e( 'Skip Filters', 'data-shifter' ); ?>
				</button>
				<button type="button" class="button button-primary data-shifter-next-step">
					<?php esc_html_e( 'Apply Filters & Continue', 'data-shifter' ); ?> <span aria-hidden="true" class="dashicons dashicons-arrow-right-alt2"></span>
				</button>
			</div>
		</div>
	</div>

	<!-- Step 3: Select Fields -->
	<div class="step-panel" id="step-panel-3" data-step="3">
		<div class="step-panel-header">
			<h2><?php esc_html_e( 'Select Fields', 'data-shifter' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Choose which fields to include in your export.', 'data-shifter' ); ?></p>
		</div>
		<div class="fields-actions">
			<button type="button" class="button" id="fields-select-all"><?php esc_html_e( 'Select All', 'data-shifter' ); ?></button>
			<button type="button" class="button" id="fields-deselect-all"><?php esc_html_e( 'Deselect All', 'data-shifter' ); ?></button>
		</div>
		<div class="fields-sections">
			<!-- Standard Fields -->
			<div class="fields-section">
				<h3><?php esc_html_e( 'Standard Fields', 'data-shifter' ); ?></h3>
				<div class="fields-checkbox-grid">
					<label class="field-checkbox">
						<input type="checkbox" name="fields[]" value="title" checked>
						<span class="checkbox-label"><?php esc_html_e( 'Title', 'data-shifter' ); ?></span>
					</label>
					<label class="field-checkbox">
						<input type="checkbox" name="fields[]" value="content" checked>
						<span class="checkbox-label"><?php esc_html_e( 'Content', 'data-shifter' ); ?></span>
					</label>
					<label class="field-checkbox">
						<input type="checkbox" name="fields[]" value="excerpt" checked>
						<span class="checkbox-label"><?php esc_html_e( 'Excerpt', 'data-shifter' ); ?></span>
					</label>
					<label class="field-checkbox">
						<input type="checkbox" name="fields[]" value="status" checked>
						<span class="checkbox-label"><?php esc_html_e( 'Status', 'data-shifter' ); ?></span>
					</label>
					<label class="field-checkbox">
						<input type="checkbox" name="fields[]" value="date" checked>
						<span class="checkbox-label"><?php esc_html_e( 'Date', 'data-shifter' ); ?></span>
					</label>
					<label class="field-checkbox">
						<input type="checkbox" name="fields[]" value="slug" checked>
						<span class="checkbox-label"><?php esc_html_e( 'Slug', 'data-shifter' ); ?></span>
					</label>
					<label class="field-checkbox">
						<input type="checkbox" name="fields[]" value="author" checked>
						<span class="checkbox-label"><?php esc_html_e( 'Author', 'data-shifter' ); ?></span>
					</label>
				</div>
			</div>

			<!-- Custom Fields (loaded via AJAX) -->
			<div class="fields-section" id="custom-fields-section">
				<h3><?php esc_html_e( 'Custom Fields', 'data-shifter' ); ?></h3>
				<div class="fields-checkbox-grid" id="custom-fields-list">
					<div class="data-shifter-loading">
						<span class="spinner is-active"></span>
						<p><?php esc_html_e( 'Loading custom fields…', 'data-shifter' ); ?></p>
					</div>
				</div>
				<!-- Internal Fields (collapsible) -->
				<div class="collapsible-section" id="internal-fields-wrapper" style="display:none;">
					<button type="button" class="collapsible-toggle" id="toggle-internal-fields">
						<span aria-hidden="true" class="dashicons dashicons-arrow-right-alt2"></span>
						<?php esc_html_e( 'Internal Fields (prefixed with _)', 'data-shifter' ); ?>
						<span class="field-count" id="internal-fields-count">0</span>
					</button>
					<div class="collapsible-content" id="internal-fields-list" style="display:none;"></div>
				</div>
			</div>

			<!-- Toggle Options -->
			<div class="fields-section">
				<h3><?php esc_html_e( 'Additional Data', 'data-shifter' ); ?></h3>
				<div class="toggle-options">
					<label class="toggle-switch">
						<input type="checkbox" id="include-taxonomies" checked>
						<span class="toggle-slider"></span>
						<span class="toggle-label"><?php esc_html_e( 'Include Taxonomies', 'data-shifter' ); ?></span>
					</label>
					<label class="toggle-switch">
						<input type="checkbox" id="include-featured-image" checked>
						<span class="toggle-slider"></span>
						<span class="toggle-label"><?php esc_html_e( 'Include Featured Image', 'data-shifter' ); ?></span>
					</label>
					<label class="toggle-switch">
						<input type="checkbox" id="include-media-gallery" checked>
						<span class="toggle-slider"></span>
						<span class="toggle-label"><?php esc_html_e( 'Include Media Gallery & Custom Field Images', 'data-shifter' ); ?></span>
					</label>
					<label class="toggle-switch">
						<input type="checkbox" id="include-comments">
						<span class="toggle-slider"></span>
						<span class="toggle-label"><?php esc_html_e( 'Include Comments', 'data-shifter' ); ?></span>
					</label>
				</div>
			</div>
		</div>
		<div class="step-nav">
			<button type="button" class="button data-shifter-prev-step">
				<span aria-hidden="true" class="dashicons dashicons-arrow-left-alt2"></span> <?php esc_html_e( 'Back', 'data-shifter' ); ?>
			</button>
			<button type="button" class="button button-primary data-shifter-next-step">
				<?php esc_html_e( 'Next: Options', 'data-shifter' ); ?> <span aria-hidden="true" class="dashicons dashicons-arrow-right-alt2"></span>
			</button>
		</div>
	</div>

	<!-- Step 4: Export Options -->
	<div class="step-panel" id="step-panel-4" data-step="4">
		<div class="step-panel-header">
			<h2><?php esc_html_e( 'Export Options', 'data-shifter' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Choose your export format and additional options.', 'data-shifter' ); ?></p>
		</div>
		<div class="export-format-options">
			<h3><?php esc_html_e( 'Export Format', 'data-shifter' ); ?></h3>
			<div class="format-cards">
				<label class="format-card selected" data-format="json">
					<input type="radio" name="export_format" value="json" checked>
					<span class="format-icon"><span aria-hidden="true" class="dashicons dashicons-media-code"></span></span>
					<span class="format-name"><?php esc_html_e( 'JSON', 'data-shifter' ); ?></span>
					<span class="format-badge"><?php esc_html_e( 'Recommended', 'data-shifter' ); ?></span>
					<span class="format-desc"><?php esc_html_e( 'Best for complete data transfer between WordPress sites. Preserves all data types and relationships.', 'data-shifter' ); ?></span>
				</label>
				<label class="format-card" data-format="zip">
					<input type="radio" name="export_format" value="zip">
					<span class="format-icon"><span aria-hidden="true" class="dashicons dashicons-archive"></span></span>
					<span class="format-name"><?php esc_html_e( 'ZIP Package', 'data-shifter' ); ?></span>
					<span class="format-badge"><?php esc_html_e( 'With Media', 'data-shifter' ); ?></span>
					<span class="format-desc"><?php esc_html_e( 'Packages data.json + all image files into a ZIP archive. Highly recommended for media-heavy sites.', 'data-shifter' ); ?></span>
				</label>
				<label class="format-card" data-format="csv">
					<input type="radio" name="export_format" value="csv">
					<span class="format-icon"><span aria-hidden="true" class="dashicons dashicons-media-spreadsheet"></span></span>
					<span class="format-name"><?php esc_html_e( 'CSV', 'data-shifter' ); ?></span>
					<span class="format-badge"></span>
					<span class="format-desc"><?php esc_html_e( 'Best for opening in Excel or Google Sheets. Flat data format.', 'data-shifter' ); ?></span>
				</label>
				<label class="format-card" data-format="xml">
					<input type="radio" name="export_format" value="xml">
					<span class="format-icon"><span aria-hidden="true" class="dashicons dashicons-media-text"></span></span>
					<span class="format-name"><?php esc_html_e( 'XML', 'data-shifter' ); ?></span>
					<span class="format-badge"></span>
					<span class="format-desc"><?php esc_html_e( 'Standard XML format. Good for compatibility with other tools.', 'data-shifter' ); ?></span>
				</label>
			</div>
		</div>
		<div class="export-extra-options">
			<label class="export-option-checkbox" id="include-media-option">
				<input type="checkbox" id="export-include-media">
				<span><?php esc_html_e( 'Download and bundle media files with export', 'data-shifter' ); ?></span>
				<p class="field-description"><?php esc_html_e( 'Only available for JSON format. Media files will be included in the download.', 'data-shifter' ); ?></p>
			</label>
		</div>

		<!-- Preview area -->
		<div class="export-preview-area" id="export-preview-area" style="display:none;">
			<h3><?php esc_html_e( 'Export Preview', 'data-shifter' ); ?></h3>
			<p class="preview-summary" id="export-preview-summary"></p>
			<table class="wp-list-table widefat fixed striped" id="export-preview-table">
				<thead><tr></tr></thead>
				<tbody></tbody>
			</table>
		</div>

		<div class="export-action-buttons">
			<button type="button" class="button button-secondary button-hero" id="data-shifter-preview-export">
				<span aria-hidden="true" class="dashicons dashicons-visibility"></span> <?php esc_html_e( 'Preview Export', 'data-shifter' ); ?>
			</button>
			<button type="button" class="button button-primary button-hero" id="data-shifter-run-export">
				<span aria-hidden="true" class="dashicons dashicons-database-export"></span> <?php esc_html_e( 'Run Export', 'data-shifter' ); ?>
			</button>
		</div>
		<div class="step-nav">
			<button type="button" class="button data-shifter-prev-step">
				<span aria-hidden="true" class="dashicons dashicons-arrow-left-alt2"></span> <?php esc_html_e( 'Back', 'data-shifter' ); ?>
			</button>
			<span></span>
		</div>
	</div>

	<!-- Step 5: Export Result -->
	<div class="step-panel" id="step-panel-5" data-step="5">
		<div class="step-panel-header">
			<h2><?php esc_html_e( 'Export complete', 'data-shifter' ); ?></h2>
		</div>
		<div class="export-result-content">
			<div class="export-result-icon">
				<span aria-hidden="true" class="dashicons dashicons-yes-alt"></span>
			</div>
			<div class="export-result-summary" id="export-result-summary">
				<div class="result-stat">
					<span class="result-stat-label"><?php esc_html_e( 'Total Items:', 'data-shifter' ); ?></span>
					<span class="result-stat-value" id="export-result-total">0</span>
				</div>
				<div class="result-stat">
					<span class="result-stat-label"><?php esc_html_e( 'Format:', 'data-shifter' ); ?></span>
					<span class="result-stat-value" id="export-result-format">—</span>
				</div>
				<div class="result-stat">
					<span class="result-stat-label"><?php esc_html_e( 'Filename:', 'data-shifter' ); ?></span>
					<span class="result-stat-value" id="export-result-filename">—</span>
				</div>
			</div>
			<a href="#" class="button button-primary button-hero data-shifter-download-btn" id="data-shifter-download-btn" download>
				<span aria-hidden="true" class="dashicons dashicons-download"></span> <?php esc_html_e( 'Download Export File', 'data-shifter' ); ?>
			</a>
			<button type="button" class="button" id="data-shifter-export-another">
				<?php esc_html_e( 'Export Another', 'data-shifter' ); ?>
			</button>
		</div>
	</div>
</div>
