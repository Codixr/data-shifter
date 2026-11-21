/**
 * Data Shifter Admin JavaScript
 *
 * Handles export wizard, step navigation, AJAX requests, drag-and-drop file upload,
 * data preview, and import execution.
 *
 * @package Codixr_Data_Shifter
 * @since   1.0.0
 */

(function () {
	'use strict';

	/**
	 * Escape a value for safe use inside HTML markup and attributes.
	 *
	 * @param {*} value Raw value.
	 * @return {string} Escaped string.
	 */
	const esc = function (value) {
		return String(value === undefined || value === null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	};

	const CodixrDataShifter = {
		currentStep: 1,
		selectedPostType: null,
		postTypesCache: [],
		uploadedFile: null,
		uploadedFormat: 'json',

		/**
		 * Initialization.
		 */
		init: function () {
			if (document.getElementById('step-panel-1')) {
				this.initExport();
			}

			if (document.getElementById('data-shifter-dropzone')) {
				this.initImport();
			}
		},

		/**
		 * Core AJAX helper using FormData and native fetch.
		 */
		ajax: function (action, data) {
			data = data || {};
			const formData = new FormData();
			formData.append('action', action);
			formData.append('nonce', codixr_data_shifter_ajax.nonce);

			if (data instanceof FormData) {
				for (let [key, val] of data.entries()) {
					formData.append(key, val);
				}
			} else {
				for (let key in data) {
					if (Object.prototype.hasOwnProperty.call(data, key)) {
						if (typeof data[key] === 'object' && data[key] !== null && !(data[key] instanceof File)) {
							formData.append(key, JSON.stringify(data[key]));
						} else {
							formData.append(key, data[key]);
						}
					}
				}
			}

			return fetch(codixr_data_shifter_ajax.ajax_url, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData,
			}).then(function (response) {
				if (!response.ok) {
					return response.text().then(function (text) {
						let clean = text.replace(/<[^>]*>?/gm, '').trim();
						throw new Error('HTTP ' + response.status + ': ' + (clean.substring(0, 150) || response.statusText));
					});
				}
				return response.text().then(function (text) {
					try {
						return JSON.parse(text);
					} catch (e) {
						console.error('Data Shifter response error:', text);
						return {
							success: false,
							data: {
								message: 'Server response error: ' + (text.replace(/<[^>]*>?/gm, '').trim().substring(0, 150) || 'Invalid response')
							}
						};
					}
				});
			});
		},

		// =========================================================================
		// EXPORT FLOW
		// =========================================================================

		initExport: function () {
			const self = this;

			// Load Post Types for Step 1
			this.loadPostTypes();

			// Step 1: Next button
			const step1Next = document.querySelector('#step-panel-1 .data-shifter-next-step');
			if (step1Next) {
				step1Next.addEventListener('click', function () {
					if (self.selectedPostType) {
						self.goToStep(2);
						self.loadTaxonomies(self.selectedPostType);
					}
				});
			}

			// Step 2: Next & Skip buttons
			const step2Next = document.querySelector('#step-panel-2 .data-shifter-next-step');
			if (step2Next) {
				step2Next.addEventListener('click', function () {
					self.goToStep(3);
					self.loadCustomFields(self.selectedPostType);
				});
			}

			const step2Skip = document.querySelector('#step-panel-2 .data-shifter-skip-step');
			if (step2Skip) {
				step2Skip.addEventListener('click', function () {
					self.goToStep(3);
					self.loadCustomFields(self.selectedPostType);
				});
			}

			// Step 3: Next button
			const step3Next = document.querySelector('#step-panel-3 .data-shifter-next-step');
			if (step3Next) {
				step3Next.addEventListener('click', function () {
					self.goToStep(4);
				});
			}

			// Prev step buttons
			document.querySelectorAll('.data-shifter-prev-step').forEach(function (btn) {
				btn.addEventListener('click', function () {
					self.goToStep(self.currentStep - 1);
				});
			});

			// Steps Header Navigation (Clickable steps)
			document.querySelectorAll('.data-shifter-steps .step').forEach(function (stepEl) {
				stepEl.addEventListener('click', function () {
					const targetStep = parseInt(this.getAttribute('data-step'), 10);
					if (targetStep < self.currentStep || (self.selectedPostType && targetStep <= 4)) {
						self.goToStep(targetStep);
					}
				});
			});

			// Select / Deselect All fields
			const selectAllBtn = document.getElementById('fields-select-all');
			if (selectAllBtn) {
				selectAllBtn.addEventListener('click', function () {
					document.querySelectorAll('#step-panel-3 input[type="checkbox"]').forEach(function (cb) {
						cb.checked = true;
					});
				});
			}

			const deselectAllBtn = document.getElementById('fields-deselect-all');
			if (deselectAllBtn) {
				deselectAllBtn.addEventListener('click', function () {
					document.querySelectorAll('#step-panel-3 input[type="checkbox"]').forEach(function (cb) {
						cb.checked = false;
					});
				});
			}

			// Internal Fields Toggle
			const toggleInternalBtn = document.getElementById('toggle-internal-fields');
			if (toggleInternalBtn) {
				toggleInternalBtn.addEventListener('click', function () {
					const content = document.getElementById('internal-fields-list');
					if (content) {
						content.style.display = content.style.display === 'none' ? 'grid' : 'none';
					}
				});
			}

			// Format Cards Selection
			document.querySelectorAll('.format-card').forEach(function (card) {
				card.addEventListener('click', function () {
					document.querySelectorAll('.format-card').forEach(function (c) {
						c.classList.remove('selected');
					});
					this.classList.add('selected');
					const radio = this.querySelector('input[type="radio"]');
					if (radio) {
						radio.checked = true;
					}

					// Toggle media bundle option for non-JSON
					const mediaOpt = document.getElementById('include-media-option');
					if (mediaOpt) {
						mediaOpt.style.display = radio.value === 'json' ? 'block' : 'none';
					}
				});
			});

			// Taxonomy select change in filters
			const taxSelect = document.getElementById('filter-taxonomy');
			if (taxSelect) {
				taxSelect.addEventListener('change', function () {
					const taxName = this.value;
					const termsBox = document.getElementById('filter-taxonomy-terms');
					const termsContainer = document.getElementById('term-checkboxes');

					if (!taxName) {
						if (termsBox) termsBox.style.display = 'none';
						return;
					}

					if (termsContainer) {
						const selectedTax = self.taxonomiesCache && self.taxonomiesCache.find(function (t) {
							return t.name === taxName;
						});

						if (selectedTax && selectedTax.terms && selectedTax.terms.length > 0) {
							let html = '';
							selectedTax.terms.forEach(function (term) {
								html += '<label class="field-checkbox">';
								html += '<input type="checkbox" name="filter_terms[]" value="' + esc(term.term_id) + '"> ';
								html += '<span>' + esc(term.name) + ' (' + esc(term.count) + ')</span>';
								html += '</label>';
							});
							termsContainer.innerHTML = html;
							if (termsBox) termsBox.style.display = 'block';
						} else {
							if (termsBox) termsBox.style.display = 'none';
						}
					}
				});
			}

			// Preview Export
			const previewBtn = document.getElementById('data-shifter-preview-export');
			if (previewBtn) {
				previewBtn.addEventListener('click', function () {
					self.runPreview();
				});
			}

			// Run Export
			const runExportBtn = document.getElementById('data-shifter-run-export');
			if (runExportBtn) {
				runExportBtn.addEventListener('click', function () {
					self.runExport();
				});
			}

			// Export Another
			const exportAnotherBtn = document.getElementById('data-shifter-export-another');
			if (exportAnotherBtn) {
				exportAnotherBtn.addEventListener('click', function () {
					self.goToStep(1);
				});
			}
		},

		goToStep: function (step) {
			this.currentStep = step;

			// Update steps bar
			document.querySelectorAll('.data-shifter-steps .step').forEach(function (el) {
				const s = parseInt(el.getAttribute('data-step'), 10);
				el.classList.remove('active', 'completed');
				if (s === step) {
					el.classList.add('active');
				} else if (s < step) {
					el.classList.add('completed');
				}
			});

			// Update panels
			document.querySelectorAll('.step-panel').forEach(function (panel) {
				panel.classList.remove('active');
			});

			const activePanel = document.getElementById('step-panel-' + step);
			if (activePanel) {
				activePanel.classList.add('active');
				window.scrollTo({ top: 0, behavior: 'smooth' });
			}
		},

		loadPostTypes: function () {
			const self = this;
			const grid = document.getElementById('data-shifter-post-type-grid');
			if (!grid) return;

			this.ajax('codixr_data_shifter_get_post_types').then(function (res) {
				if (!res.success || !res.data) {
					grid.innerHTML = '<p class="error">Failed to load post types.</p>';
					return;
				}

				self.postTypesCache = res.data;
				let html = '';

				res.data.forEach(function (pt) {
					let icon = 'dashicons-admin-post';
					if (pt.name === 'page') icon = 'dashicons-admin-page';
					else if (pt.name === 'attachment') icon = 'dashicons-admin-media';
					else if (pt.name === 'product') icon = 'dashicons-cart';
					else if (pt.name.indexOf('order') !== -1) icon = 'dashicons-clipboard';

					html += '<div class="post-type-card" data-post-type="' + esc(pt.name) + '">';
					html += '<span class="dashicons ' + esc(icon) + ' card-icon" aria-hidden="true"></span>';
					html += '<div class="card-title">' + esc(pt.label) + '</div>';
					html += '<div class="card-name">' + esc(pt.name) + '</div>';
					html += '<div class="card-count">Total: <strong>' + esc(pt.count) + '</strong></div>';
					html += '</div>';
				});

				grid.innerHTML = html;

				// Card selection handlers
				grid.querySelectorAll('.post-type-card').forEach(function (card) {
					card.addEventListener('click', function () {
						grid.querySelectorAll('.post-type-card').forEach(function (c) {
							c.classList.remove('selected');
						});
						this.classList.add('selected');
						self.selectedPostType = this.getAttribute('data-post-type');

						// Enable next button
						const nextBtn = document.querySelector('#step-panel-1 .data-shifter-next-step');
						if (nextBtn) {
							nextBtn.removeAttribute('disabled');
						}
					});
				});
			}).catch(function () {
				grid.innerHTML = '<p class="error">Error connecting to server.</p>';
			});
		},

		loadTaxonomies: function (postType) {
			const self = this;
			const select = document.getElementById('filter-taxonomy');
			if (!select) return;

			select.innerHTML = '<option value="">Loading taxonomies...</option>';

			this.ajax('codixr_data_shifter_get_taxonomies', { post_type: postType }).then(function (res) {
				if (!res.success || !res.data) {
					select.innerHTML = '<option value="">No taxonomies available</option>';
					return;
				}

				self.taxonomiesCache = res.data;
				let html = '<option value="">Select a taxonomy...</option>';

				res.data.forEach(function (tax) {
					const termsCount = tax.terms ? tax.terms.length : 0;
					html += '<option value="' + esc(tax.name) + '">' + esc(tax.label) + ' (' + esc(termsCount) + ' terms)</option>';
				});

				select.innerHTML = html;
			});
		},

		loadCustomFields: function (postType) {
			const list = document.getElementById('custom-fields-list');
			const internalWrapper = document.getElementById('internal-fields-wrapper');
			const internalList = document.getElementById('internal-fields-list');
			const internalCount = document.getElementById('internal-fields-count');

			if (!list) return;

			list.innerHTML = '<div class="data-shifter-loading"><span class="spinner is-active"></span><p>Loading custom fields...</p></div>';

			this.ajax('codixr_data_shifter_get_fields', { post_type: postType }).then(function (res) {
				if (!res.success || !res.data || res.data.length === 0) {
					list.innerHTML = '<p class="description">No custom fields found for this post type.</p>';
					if (internalWrapper) internalWrapper.style.display = 'none';
					return;
				}

				let standardHtml = '';
				let internalHtml = '';
				let intCount = 0;

				res.data.forEach(function (key) {
					if (key.indexOf('_') === 0 && key !== '_thumbnail_id') {
						intCount++;
						internalHtml += '<label class="field-checkbox">';
						internalHtml += '<input type="checkbox" name="custom_fields[]" value="' + esc(key) + '"> ';
						internalHtml += '<span>' + esc(key) + '</span>';
						internalHtml += '</label>';
					} else {
						standardHtml += '<label class="field-checkbox">';
						standardHtml += '<input type="checkbox" name="custom_fields[]" value="' + esc(key) + '" checked> ';
						standardHtml += '<span>' + esc(key) + '</span>';
						standardHtml += '</label>';
					}
				});

				list.innerHTML = standardHtml || '<p class="description">No public custom fields found.</p>';

				if (intCount > 0 && internalWrapper && internalList && internalCount) {
					internalCount.textContent = intCount;
					internalList.innerHTML = internalHtml;
					internalWrapper.style.display = 'block';
				} else if (internalWrapper) {
					internalWrapper.style.display = 'none';
				}
			});
		},

		buildExportConfig: function () {
			const format = document.querySelector('input[name="export_format"]:checked') ?
				document.querySelector('input[name="export_format"]:checked').value : 'json';

			// Collect filters
			const filters = {
				status: document.getElementById('filter-status') ? document.getElementById('filter-status').value : '',
				date_from: document.getElementById('filter-date-from') ? document.getElementById('filter-date-from').value : '',
				date_to: document.getElementById('filter-date-to') ? document.getElementById('filter-date-to').value : '',
				taxonomy: document.getElementById('filter-taxonomy') ? document.getElementById('filter-taxonomy').value : '',
				terms: [],
				author: document.getElementById('filter-author') ? document.getElementById('filter-author').value : '',
				specific_ids: document.getElementById('filter-post-ids') ? document.getElementById('filter-post-ids').value : '',
				search: document.getElementById('filter-search') ? document.getElementById('filter-search').value : '',
			};

			document.querySelectorAll('input[name="filter_terms[]"]:checked').forEach(function (cb) {
				filters.terms.push(cb.value);
			});

			// Collect fields
			const fields = [];
			document.querySelectorAll('input[name="fields[]"]:checked, input[name="custom_fields[]"]:checked').forEach(function (cb) {
				fields.push(cb.value);
			});

			return {
				post_type: this.selectedPostType || 'post',
				format: format,
				filters: filters,
				fields: fields,
				include_meta: 1,
				include_taxonomies: document.getElementById('include-taxonomies') ? (document.getElementById('include-taxonomies').checked ? 1 : 0) : 1,
				include_media: document.getElementById('include-featured-image') ? (document.getElementById('include-featured-image').checked ? 1 : 0) : 1,
				include_gallery: document.getElementById('include-media-gallery') ? (document.getElementById('include-media-gallery').checked ? 1 : 0) : 1,
				include_comments: document.getElementById('include-comments') ? (document.getElementById('include-comments').checked ? 1 : 0) : 0,
			};
		},

		runPreview: function () {
			const config = this.buildExportConfig();
			const previewArea = document.getElementById('export-preview-area');
			const summary = document.getElementById('export-preview-summary');
			const tableHead = document.querySelector('#export-preview-table thead tr');
			const tableBody = document.querySelector('#export-preview-table tbody');
			const btn = document.getElementById('data-shifter-preview-export');

			if (btn) btn.disabled = true;

			this.ajax('codixr_data_shifter_preview_export', config).then(function (res) {
				if (btn) btn.disabled = false;

				if (!res.success || !res.data) {
					alert(res.data && res.data.message ? res.data.message : 'Preview failed.');
					return;
				}

				if (previewArea) previewArea.style.display = 'block';
				if (summary) summary.textContent = 'Showing ' + (res.data.items ? res.data.items.length : 0) + ' sample items out of ' + res.data.total;

				if (tableHead && tableBody && res.data.items && res.data.items.length > 0) {
					tableHead.innerHTML = '<th>Title</th><th>Status</th><th>Date</th><th>Author</th>';
					let rows = '';
					res.data.items.forEach(function (item) {
						const title = item.post_data ? item.post_data.post_title : (item.Title || '—');
						const status = item.post_data ? item.post_data.post_status : (item.Status || 'publish');
						const date = item.post_data ? item.post_data.post_date : (item.Date || '—');
						const author = item.post_author ? (item.post_author.display_name || item.post_author.user_login) : (item.Author || '—');

						rows += '<tr><td><strong>' + esc(title) + '</strong></td><td>' + esc(status) + '</td><td>' + esc(date) + '</td><td>' + esc(author) + '</td></tr>';
					});
					tableBody.innerHTML = rows;
				}
			}).catch(function () {
				if (btn) btn.disabled = false;
				alert('Server communication error.');
			});
		},

		runExport: function () {
			const self = this;
			const config = this.buildExportConfig();
			const btn = document.getElementById('data-shifter-run-export');

			if (btn) {
				btn.disabled = true;
				btn.innerHTML = '<span class="spinner is-active" style="float:none; margin:0 6px 0 0;"></span> Exporting...';
			}

			this.ajax('codixr_data_shifter_run_export', config).then(function (res) {
				if (btn) {
					btn.disabled = false;
					btn.innerHTML = '<span class="dashicons dashicons-database-export"></span> Run Export';
				}

				if (!res.success || !res.data) {
					alert(res.data && res.data.message ? res.data.message : 'Export failed.');
					return;
				}

				// Populate Step 5
				const totalEl = document.getElementById('export-result-total');
				const formatEl = document.getElementById('export-result-format');
				const fileEl = document.getElementById('export-result-filename');
				const dlBtn = document.getElementById('data-shifter-download-btn');

				if (totalEl) totalEl.textContent = res.data.total;
				if (formatEl) formatEl.textContent = (res.data.format || config.format).toUpperCase();
				if (fileEl) fileEl.textContent = res.data.filename;
				if (dlBtn) {
					dlBtn.href = res.data.download_url;
					dlBtn.setAttribute('download', res.data.filename);
				}

				self.goToStep(5);
			}).catch(function (err) {
				if (btn) {
					btn.disabled = false;
					btn.innerHTML = '<span class="dashicons dashicons-database-export"></span> Run Export';
				}
				console.error('Data Shifter Export Error:', err);
				alert('Export failed: ' + (err && err.message ? err.message : 'Network / server communication error.'));
			});
		},

		// =========================================================================
		// IMPORT FLOW
		// =========================================================================

		initImport: function () {
			const self = this;
			const dropzone = document.getElementById('data-shifter-dropzone');
			const fileInput = document.getElementById('data-shifter-import-file');
			const chooseBtn = document.getElementById('data-shifter-choose-file-btn');

			// Duplicate handling: explain the choice and hide "Match by" when it does not apply.
			const dupSelect = document.getElementById('import-duplicate-action');
			const matchGroup = document.getElementById('match-by-group');
			const dupHelp = document.getElementById('import-duplicate-help');
			const syncDuplicateUi = function () {
				if (!dupSelect) {
					return;
				}
				const value = dupSelect.value;
				if (matchGroup) {
					matchGroup.hidden = value === 'create';
				}
				if (dupHelp && dupHelp.dataset[value]) {
					dupHelp.textContent = dupHelp.dataset[value];
				}
			};
			if (dupSelect) {
				dupSelect.addEventListener('change', syncDuplicateUi);
				syncDuplicateUi();
			}

			// Populate post types dropdown on import page
			const ptSelect = document.getElementById('import-post-type');
			if (ptSelect) {
				this.ajax('codixr_data_shifter_get_post_types').then(function (res) {
					if (res.success && res.data && res.data.length > 0) {
						let currentVal = ptSelect.value;
						let html = '';
						res.data.forEach(function (pt) {
							html += '<option value="' + esc(pt.name) + '">' + esc(pt.label) + ' (' + esc(pt.name) + ')</option>';
						});
						ptSelect.innerHTML = html;
						if (currentVal) {
							ptSelect.value = currentVal;
						}
					}
				}).catch(function () {});
			}

			if (chooseBtn && fileInput) {
				chooseBtn.addEventListener('click', function (e) {
					e.stopPropagation();
					fileInput.click();
				});
			}

			if (dropzone && fileInput) {
				dropzone.addEventListener('click', function () {
					fileInput.click();
				});

				['dragenter', 'dragover'].forEach(function (eventName) {
					dropzone.addEventListener(eventName, function (e) {
						e.preventDefault();
						e.stopPropagation();
						dropzone.classList.add('dragover');
					});
				});

				['dragleave', 'drop'].forEach(function (eventName) {
					dropzone.addEventListener(eventName, function (e) {
						e.preventDefault();
						e.stopPropagation();
						dropzone.classList.remove('dragover');
					});
				});

				dropzone.addEventListener('drop', function (e) {
					const files = e.dataTransfer.files;
					if (files.length > 0) {
						self.handleFileUpload(files[0]);
					}
				});

				fileInput.addEventListener('change', function () {
					if (this.files.length > 0) {
						self.handleFileUpload(this.files[0]);
					}
				});
			}

			// Cancel / Choose different file
			const cancelBtn = document.getElementById('import-cancel-btn');
			if (cancelBtn) {
				cancelBtn.addEventListener('click', function () {
					self.resetImport();
				});
			}

			// Run Import button
			const runBtn = document.getElementById('data-shifter-run-import');
			if (runBtn) {
				runBtn.addEventListener('click', function () {
					self.executeImport();
				});
			}

			// Import Another button
			const anotherBtn = document.getElementById('import-another-btn');
			if (anotherBtn) {
				anotherBtn.addEventListener('click', function () {
					self.resetImport();
				});
			}
		},

		handleFileUpload: function (file) {
			const self = this;
			const ext = file.name.split('.').pop().toLowerCase();

			if (!['json', 'zip', 'csv', 'xml'].includes(ext)) {
				alert('Please upload a valid JSON, ZIP, CSV, or XML file.');
				return;
			}

			const dropzoneContent = document.getElementById('data-shifter-dropzone-content');
			const dropzoneUploading = document.getElementById('data-shifter-dropzone-uploading');
			const uploadProgressFill = document.getElementById('upload-progress-fill');
			const uploadProgressText = document.getElementById('upload-progress-text');

			if (dropzoneContent) dropzoneContent.style.display = 'none';
			if (dropzoneUploading) dropzoneUploading.style.display = 'block';

			// Chunk size comes from the server so each chunk fits under upload_max_filesize and post_max_size.
			const serverChunk = (typeof codixr_data_shifter_ajax !== 'undefined') ? parseInt(codixr_data_shifter_ajax.chunk_size, 10) : 0;
			const chunkSize = serverChunk > 0 ? serverChunk : 1024 * 1024;
			const totalChunks = Math.max(1, Math.ceil(file.size / chunkSize));
			// The upload ID is created by the server (codixr_data_shifter_start_upload) and tied to the current user.
			let uploadId = '';
			let currentChunk = 0;

			function formatBytes(bytes) {
				if (bytes === 0) return '0 B';
				const k = 1024;
				const sizes = ['B', 'KB', 'MB', 'GB'];
				const i = Math.floor(Math.log(bytes) / Math.log(k));
				return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
			}

			function uploadNextChunk() {
				const start = currentChunk * chunkSize;
				const end = Math.min(start + chunkSize, file.size);
				const chunk = file.slice(start, end);

				const dsConfig = (typeof codixr_data_shifter_ajax !== 'undefined') ? codixr_data_shifter_ajax : {};
				const formData = new FormData();
				formData.append('action', 'codixr_data_shifter_upload_chunk');
				formData.append('nonce', dsConfig.nonce || '');
				formData.append('upload_id', uploadId);
				formData.append('chunk_index', currentChunk);
				formData.append('chunk_data', chunk, file.name);

				const percent = Math.round(((currentChunk + 1) / totalChunks) * 100);
				if (uploadProgressFill) uploadProgressFill.style.width = percent + '%';
				if (uploadProgressText) {
					uploadProgressText.textContent = 'Uploading chunk ' + (currentChunk + 1) + ' of ' + totalChunks + ' (' + formatBytes(end) + ' / ' + formatBytes(file.size) + ' — ' + percent + '%)...';
				}

				let retryCount = 0;
				function sendChunk() {
					const ajaxUrl = dsConfig.ajax_url || dsConfig.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php';
					fetch(ajaxUrl, {
						method: 'POST',
						body: formData,
					})
					.then(function (res) {
						if (!res.ok) {
							throw new Error('HTTP ' + res.status + ': ' + res.statusText);
						}
						return res.json();
					})
					.then(function (res) {
						if (!res.success) {
							// The server refused the file (for example a ZIP with a blocked entry): do not retry.
							const refused = new Error(res.data && res.data.message ? res.data.message : 'Server returned an error');
							refused.noRetry = true;
							throw refused;
						}

						currentChunk++;
						if (currentChunk < totalChunks) {
							uploadNextChunk();
						} else {
							// All chunks assembled! res.data contains the file preview
							if (uploadProgressText) {
								uploadProgressText.textContent = 'Upload complete! Preparing preview...';
							}
							self.uploadedFile = res.data.file;
							self.uploadedFormat = res.data.format;
							self.renderImportPreview(res.data);
						}
					})
					.catch(function (err) {
						if (retryCount < 3 && !(err && err.noRetry)) {
							retryCount++;
							if (uploadProgressText) {
								uploadProgressText.textContent = 'Retrying chunk ' + (currentChunk + 1) + ' (attempt ' + retryCount + ')...';
							}
							setTimeout(sendChunk, 1500);
						} else {
							alert('Upload failed: ' + (err && err.message ? err.message : 'Upload connection error.'));
							self.resetImport();
						}
					});
				}

				sendChunk();
			}

			self.ajax('codixr_data_shifter_start_upload', { filename: file.name, total_chunks: totalChunks })
				.then(function (res) {
					if (!res || !res.success || !res.data || !res.data.upload_id) {
						throw new Error(res && res.data && res.data.message ? res.data.message : 'Could not start the upload.');
					}
					uploadId = res.data.upload_id;
					uploadNextChunk();
				})
				.catch(function (err) {
					alert('Upload failed: ' + (err && err.message ? err.message : 'Upload connection error.'));
					self.resetImport();
				});
		},

		renderImportPreview: function (data) {
			const uploadSection = document.getElementById('data-shifter-import-upload-section');
			const previewSection = document.getElementById('data-shifter-import-preview');

			if (uploadSection) uploadSection.style.display = 'none';
			if (previewSection) previewSection.style.display = 'block';

			// Badges
			const formatEl = document.getElementById('preview-format');
			const totalEl = document.getElementById('preview-total');
			if (formatEl) formatEl.textContent = data.format.toUpperCase();
			if (totalEl) totalEl.textContent = data.total_items;

			// Auto-select detected post type
			if (data.post_type) {
				const ptSelect = document.getElementById('import-post-type');
				if (ptSelect) {
					let found = false;
					for (let i = 0; i < ptSelect.options.length; i++) {
						if (ptSelect.options[i].value === data.post_type) {
							ptSelect.selectedIndex = i;
							found = true;
							break;
						}
					}
					if (!found) {
						const opt = document.createElement('option');
						opt.value = data.post_type;
						opt.text = data.post_type.charAt(0).toUpperCase() + data.post_type.slice(1) + ' (' + data.post_type + ')';
						opt.selected = true;
						ptSelect.add(opt);
					}
				}
			}

			// Preview Table Body
			const tbody = document.getElementById('preview-table-body');
			if (tbody && data.preview && data.preview.length > 0) {
				let html = '';
				data.preview.forEach(function (item, index) {
					let imgHtml = '—';
					if (item.featured_image) {
						imgHtml = '<img src="' + esc(item.featured_image) + '" alt="" class="data-shifter-thumb">';
					}

					html += '<tr>';
					html += '<td>' + (index + 1) + '</td>';
					html += '<td><strong>' + esc(item.title || 'Untitled') + '</strong></td>';
					html += '<td><span class="badge">' + esc(item.status || 'publish') + '</span></td>';
					html += '<td>' + esc(item.date || '—') + '</td>';
					html += '<td>' + esc(item.excerpt || '—') + '</td>';
					html += '<td>' + imgHtml + '</td>';
					html += '</tr>';
				});
				tbody.innerHTML = html;
			}
		},

		executeImport: function () {
			const self = this;
			if (!this.uploadedFile) {
				alert('No file uploaded.');
				return;
			}

			const postType = document.getElementById('import-post-type') ? document.getElementById('import-post-type').value : 'post';
			const dupAction = document.getElementById('import-duplicate-action') ? document.getElementById('import-duplicate-action').value : 'skip';
			const matchBy = document.getElementById('import-match-by') ? document.getElementById('import-match-by').value : 'title';
			const importMedia = document.getElementById('import-media') ? (document.getElementById('import-media').checked ? 1 : 0) : 1;
			const importTax = document.getElementById('import-taxonomy') ? (document.getElementById('import-taxonomy').checked ? 1 : 0) : 1;

			const config = {
				file: this.uploadedFile,
				format: this.uploadedFormat,
				post_type: postType,
				update_existing: dupAction === 'update' ? 1 : 0,
				duplicate_action: dupAction,
				match_by: matchBy,
				import_media: importMedia,
				import_taxonomy: importTax,
				batch_size: 3,
			};

			const previewSection = document.getElementById('data-shifter-import-preview');
			const progressSection = document.getElementById('data-shifter-import-progress');
			const fill = document.getElementById('import-progress-fill');
			const txt = document.getElementById('import-progress-text');

			if (previewSection) previewSection.style.display = 'none';
			if (progressSection) progressSection.style.display = 'block';

			if (fill) fill.style.width = '0%';
			if (txt) txt.textContent = 'Preparing import session...';

			// Step 1: Initialize session
			self.ajax('codixr_data_shifter_init_import', config).then(function (initRes) {
				if (!initRes.success || !initRes.data) {
					throw new Error(initRes.data && initRes.data.message ? initRes.data.message : 'Failed to initialize import session.');
				}

				const sessionId = initRes.data.session_id;
				const totalItems = parseInt(initRes.data.total_items, 10) || 0;

				if (totalItems === 0) {
					if (progressSection) progressSection.style.display = 'none';
					alert('No items found in the import file.');
					self.resetImport();
					return;
				}

				let offset = 0;
				const batchSize = 3; // 3 items per batch ensures no PHP timeouts on any hosting server

				function processNextBatch() {
					const pct = Math.min(98, Math.round((offset / totalItems) * 100));
					if (fill) fill.style.width = pct + '%';
					if (txt) txt.textContent = 'Importing items ' + (offset + 1) + ' to ' + Math.min(offset + batchSize, totalItems) + ' of ' + totalItems + ' (' + pct + '%)...';

					const batchData = {
						session_id: sessionId,
						offset: offset,
						batch_size: batchSize,
						post_type: postType,
						update_existing: dupAction === 'update' ? 1 : 0,
						duplicate_action: dupAction,
						match_by: matchBy,
						import_media: importMedia,
						import_taxonomy: importTax,
					};

					self.ajax('codixr_data_shifter_import_batch', batchData).then(function (batchRes) {
						if (!batchRes.success || !batchRes.data) {
							throw new Error(batchRes.data && batchRes.data.message ? batchRes.data.message : 'Batch processing error.');
						}

						const bData = batchRes.data;
						offset = bData.offset;

						if (txt && bData.current_title) {
							const currentPct = Math.round((bData.processed / totalItems) * 100);
							txt.textContent = 'Processed ' + bData.processed + ' of ' + totalItems + ' (' + currentPct + '%)... Current: "' + bData.current_title + '"';
						}

						if (bData.is_finished || offset >= totalItems) {
							// Step 3: Finalize import (hierarchy resolution & cleanup)
							if (fill) fill.style.width = '99%';
							if (txt) txt.textContent = 'Linking hierarchy and finalizing import...';

							self.ajax('codixr_data_shifter_finalize_import', { session_id: sessionId }).then(function (finalRes) {
								if (progressSection) progressSection.style.display = 'none';
								if (!finalRes.success || !finalRes.data) {
									throw new Error(finalRes.data && finalRes.data.message ? finalRes.data.message : 'Finalization failed.');
								}

								if (fill) fill.style.width = '100%';
								self.showImportResults(finalRes.data);
							}).catch(function (finErr) {
								if (progressSection) progressSection.style.display = 'none';
								alert('Finalize error: ' + (finErr && finErr.message ? finErr.message : finErr));
								self.resetImport();
							});
						} else {
							// Process next batch seamlessly
							processNextBatch();
						}
					}).catch(function (batchErr) {
						if (progressSection) progressSection.style.display = 'none';
						alert('Batch import error: ' + (batchErr && batchErr.message ? batchErr.message : batchErr));
						self.resetImport();
					});
				}

				processNextBatch();
			}).catch(function (err) {
				if (progressSection) progressSection.style.display = 'none';
				const msg = (err && err.message) ? err.message : (typeof err === 'string' ? err : 'Import failed to start.');
				alert(msg);
				self.resetImport();
			});
		},

		showImportResults: function (data) {
			const resultsSection = document.getElementById('data-shifter-import-results');
			if (resultsSection) resultsSection.style.display = 'block';

			const crEl = document.getElementById('result-created');
			const upEl = document.getElementById('result-updated');
			const skEl = document.getElementById('result-skipped');
			const erEl = document.getElementById('result-errors');

			if (crEl) crEl.textContent = data.created || 0;
			if (upEl) upEl.textContent = data.updated || 0;
			if (skEl) skEl.textContent = data.skipped || 0;
			if (erEl) erEl.textContent = data.errors || 0;

			// Show any messages or errors
			const errBox = document.getElementById('error-details-box');
			const errList = document.getElementById('error-details-list');

			if (errBox && errList && data.messages && data.messages.length > 0) {
				let listHtml = '';
				data.messages.forEach(function (msg) {
					listHtml += '<li>' + esc(msg) + '</li>';
				});
				errList.innerHTML = listHtml;
				errBox.style.display = 'block';
			} else if (errBox) {
				errBox.style.display = 'none';
			}
		},

		resetImport: function () {
			this.uploadedFile = null;
			this.uploadedFormat = 'json';

			const uploadSection = document.getElementById('data-shifter-import-upload-section');
			const previewSection = document.getElementById('data-shifter-import-preview');
			const progressSection = document.getElementById('data-shifter-import-progress');
			const resultsSection = document.getElementById('data-shifter-import-results');
			const fileInput = document.getElementById('data-shifter-import-file');
			const dropzoneContent = document.getElementById('data-shifter-dropzone-content');
			const dropzoneUploading = document.getElementById('data-shifter-dropzone-uploading');
			const uploadProgressFill = document.getElementById('upload-progress-fill');

			if (dropzoneContent) dropzoneContent.style.display = 'block';
			if (dropzoneUploading) dropzoneUploading.style.display = 'none';
			if (uploadProgressFill) uploadProgressFill.style.width = '0%';

			if (uploadSection) {
				uploadSection.style.display = 'block';
				uploadSection.style.opacity = '1';
			}
			if (previewSection) previewSection.style.display = 'none';
			if (progressSection) progressSection.style.display = 'none';
			if (resultsSection) resultsSection.style.display = 'none';
			if (fileInput) fileInput.value = '';
		},
	};

	document.addEventListener('DOMContentLoaded', function () {
		CodixrDataShifter.init();
	});
})();
