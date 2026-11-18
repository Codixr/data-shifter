<?php
/**
 * Data Shifter — Import Page Template
 *
 * Single-page drag-and-drop workflow for importing data into WordPress.
 *
 * @package Data_Shifter
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap data-shifter-wrap">
	<?php
	$data_shifter_current_tab = 'import';
	require DATA_SHIFTER_VIEWS . 'partials/header.php';
	?>

	<!-- Step 1: Upload Area -->
	<div class="data-shifter-import-upload" id="data-shifter-import-upload-section">
		<div class="data-shifter-dropzone" id="data-shifter-dropzone">
			<div class="dropzone-content" id="data-shifter-dropzone-content">
				<span aria-hidden="true" class="dashicons dashicons-upload dropzone-icon"></span>
				<h2 class="dropzone-title"><?php esc_html_e( 'Drag and drop your export file here', 'data-shifter' ); ?></h2>
				<p class="dropzone-or"><?php esc_html_e( 'or', 'data-shifter' ); ?></p>
				<button type="button" class="button button-primary button-hero" id="data-shifter-choose-file-btn">
					<span aria-hidden="true" class="dashicons dashicons-media-default"></span> <?php esc_html_e( 'Choose File', 'data-shifter' ); ?>
				</button>
				<label for="data-shifter-import-file" class="screen-reader-text"><?php esc_html_e( 'Choose an import file', 'data-shifter' ); ?></label>
				<input type="file" id="data-shifter-import-file" class="data-shifter-hidden-input" accept=".json,.zip,.csv,.xml">
				<p class="description dropzone-hint">
					<span aria-hidden="true" class="dashicons dashicons-info-outline"></span>
					<?php esc_html_e( 'Supported formats: JSON, ZIP package, CSV and XML. Large files are uploaded in chunks, so the server upload limit does not apply.', 'data-shifter' ); ?>
				</p>
			</div>

			<!-- Uploading progress inside dropzone (hidden by default) -->
			<div class="dropzone-uploading" id="data-shifter-dropzone-uploading" style="display:none;" role="status" aria-live="polite">
				<span class="spinner is-active import-spinner"></span>
				<h3 id="dropzone-upload-title"><?php esc_html_e( 'Uploading File...', 'data-shifter' ); ?></h3>
				<div class="progress-bar progress-bar--upload">
					<div class="progress-fill" id="upload-progress-fill" style="width: 0%;"></div>
				</div>
				<p class="description" id="upload-progress-text"><?php esc_html_e( 'Uploading in chunks (no size limit)...', 'data-shifter' ); ?></p>
			</div>
		</div>
	</div>

	<!-- Step 2: Preview & Configuration (hidden initially) -->
	<div class="data-shifter-import-preview" id="data-shifter-import-preview" style="display:none;">
		<div class="preview-header">
			<h2><?php esc_html_e( 'Import Preview & Options', 'data-shifter' ); ?></h2>
			<div class="preview-badges">
				<span class="badge badge-info">
					<?php esc_html_e( 'Format:', 'data-shifter' ); ?> <strong id="preview-format">—</strong>
				</span>
				<span class="badge badge-info">
					<?php esc_html_e( 'Detected Items:', 'data-shifter' ); ?> <strong id="preview-total">0</strong>
				</span>
			</div>
		</div>

		<!-- Preview Table -->
		<div class="preview-table-wrapper">
			<h3><?php esc_html_e( 'Data Preview (First 10 Items)', 'data-shifter' ); ?></h3>
			<table class="wp-list-table widefat fixed striped" id="preview-table">
				<thead>
					<tr>
						<th class="column-index"><?php esc_html_e( '#', 'data-shifter' ); ?></th>
						<th><?php esc_html_e( 'Title', 'data-shifter' ); ?></th>
						<th><?php esc_html_e( 'Status', 'data-shifter' ); ?></th>
						<th><?php esc_html_e( 'Date', 'data-shifter' ); ?></th>
						<th><?php esc_html_e( 'Excerpt / Summary', 'data-shifter' ); ?></th>
						<th><?php esc_html_e( 'Image', 'data-shifter' ); ?></th>
					</tr>
				</thead>
				<tbody id="preview-table-body">
					<!-- Injected dynamically via JS -->
				</tbody>
			</table>
		</div>

		<!-- Import Options Section -->
		<div class="data-shifter-card import-options-card">
			<h3><span aria-hidden="true" class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'Import Configuration', 'data-shifter' ); ?></h3>

			<div class="filter-grid">
				<!-- Target Post Type -->
				<div class="filter-group">
					<label for="import-post-type"><?php esc_html_e( 'Import As Post Type', 'data-shifter' ); ?></label>
					<select id="import-post-type" class="data-shifter-select">
						<!-- Post types populated dynamically or fallback -->
						<option value="post"><?php esc_html_e( 'Posts (post)', 'data-shifter' ); ?></option>
						<option value="page"><?php esc_html_e( 'Pages (page)', 'data-shifter' ); ?></option>
					</select>
				</div>

				<!-- Duplicate Handling -->
				<div class="filter-group">
					<label for="import-duplicate-action"><?php esc_html_e( 'Duplicate Handling', 'data-shifter' ); ?></label>
					<select id="import-duplicate-action" class="data-shifter-select" aria-describedby="import-duplicate-help">
						<option value="skip"><?php esc_html_e( 'Skip existing posts', 'data-shifter' ); ?></option>
						<option value="update"><?php esc_html_e( 'Update existing posts', 'data-shifter' ); ?></option>
						<option value="create"><?php esc_html_e( 'Always create new (allow duplicates)', 'data-shifter' ); ?></option>
					</select>
					<p class="description" id="import-duplicate-help" aria-live="polite"
						data-skip="<?php esc_attr_e( 'Items that match an existing post are left out. Your existing posts are not changed.', 'data-shifter' ); ?>"
						data-update="<?php esc_attr_e( 'Items that match an existing post overwrite that post. Other items are created as new posts.', 'data-shifter' ); ?>"
						data-create="<?php esc_attr_e( 'Every item is created as a new post, even if a post with the same title or slug exists. WordPress adds a number to repeated slugs.', 'data-shifter' ); ?>"
					><?php esc_html_e( 'Items that match an existing post are left out. Your existing posts are not changed.', 'data-shifter' ); ?></p>
				</div>

				<!-- Match By (for duplicate detection) -->
				<div class="filter-group" id="match-by-group">
					<label for="import-match-by"><?php esc_html_e( 'Match Existing Posts By', 'data-shifter' ); ?></label>
					<select id="import-match-by" class="data-shifter-select">
						<option value="title"><?php esc_html_e( 'Post Title', 'data-shifter' ); ?></option>
						<option value="slug"><?php esc_html_e( 'Post Slug (post_name)', 'data-shifter' ); ?></option>
					</select>
				</div>

				<!-- Media & Taxonomy Toggles -->
				<div class="filter-group filter-group-full">
					<div class="toggle-options">
						<label class="toggle-switch">
							<input type="checkbox" id="import-media" checked>
							<span class="toggle-slider"></span>
							<span class="toggle-label"><?php esc_html_e( 'Download and import media files (featured images & attachments)', 'data-shifter' ); ?></span>
						</label>
						<label class="toggle-switch">
							<input type="checkbox" id="import-taxonomy" checked>
							<span class="toggle-slider"></span>
							<span class="toggle-label"><?php esc_html_e( 'Import taxonomies & auto-create missing categories/tags', 'data-shifter' ); ?></span>
						</label>
					</div>
				</div>
			</div>
		</div>

		<!-- Action buttons -->
		<div class="import-action-buttons">
			<button type="button" class="button button-secondary" id="import-cancel-btn">
				<span aria-hidden="true" class="dashicons dashicons-no"></span> <?php esc_html_e( 'Choose Different File', 'data-shifter' ); ?>
			</button>
			<button type="button" class="button button-primary button-hero" id="data-shifter-run-import">
				<span aria-hidden="true" class="dashicons dashicons-database-import"></span> <?php esc_html_e( 'Start Import', 'data-shifter' ); ?>
			</button>
		</div>
	</div>

	<!-- Step 3: Progress Section (hidden initially) -->
	<div class="data-shifter-import-progress" id="data-shifter-import-progress" style="display:none;">
		<div class="progress-box" role="status" aria-live="polite">
			<span class="spinner is-active import-spinner"></span>
			<h2><?php esc_html_e( 'Importing data...', 'data-shifter' ); ?></h2>
			<p class="progress-desc"><?php esc_html_e( 'Please do not close this window while the import is in progress.', 'data-shifter' ); ?></p>
			
			<div class="progress-bar">
				<div class="progress-fill" id="import-progress-fill" style="width: 15%;"></div>
			</div>
			<p class="progress-text" id="import-progress-text"><?php esc_html_e( 'Processing items...', 'data-shifter' ); ?></p>
		</div>
	</div>

	<!-- Step 4: Results Section (hidden initially) -->
	<div class="data-shifter-import-results" id="data-shifter-import-results" style="display:none;">
		<div class="results-header">
			<span aria-hidden="true" class="dashicons dashicons-yes-alt results-icon"></span>
			<h2><?php esc_html_e( 'Import complete', 'data-shifter' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Every item in the file has been processed. Review the counts and any messages below.', 'data-shifter' ); ?></p>
		</div>

		<div class="results-grid">
			<div class="result-card is-success">
				<span class="count" id="result-created">0</span>
				<span class="label"><?php esc_html_e( 'Created', 'data-shifter' ); ?></span>
			</div>
			<div class="result-card is-info">
				<span class="count" id="result-updated">0</span>
				<span class="label"><?php esc_html_e( 'Updated', 'data-shifter' ); ?></span>
			</div>
			<div class="result-card is-warning">
				<span class="count" id="result-skipped">0</span>
				<span class="label"><?php esc_html_e( 'Skipped', 'data-shifter' ); ?></span>
			</div>
			<div class="result-card is-error">
				<span class="count" id="result-errors">0</span>
				<span class="label"><?php esc_html_e( 'Errors', 'data-shifter' ); ?></span>
			</div>
		</div>

		<div class="error-details-box" id="error-details-box" style="display:none;">
			<h3><?php esc_html_e( 'Import messages', 'data-shifter' ); ?></h3>
			<ul id="error-details-list"></ul>
		</div>

		<div class="results-footer">
			<button type="button" class="button button-primary button-hero" id="import-another-btn">
				<span aria-hidden="true" class="dashicons dashicons-update"></span> <?php esc_html_e( 'Import Another File', 'data-shifter' ); ?>
			</button>
		</div>
	</div>
</div>
