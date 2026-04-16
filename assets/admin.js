/**
 * HonestHosting Site Migrator — Admin JavaScript
 *
 * Handles AJAX calls, UI state management, and progress polling.
 */

/* global jQuery, hh_migrator_ajax */
(function ($) {
	'use strict';

	var HHMigrator = {

		/**
		 * Initialize event handlers.
		 */
		/**
		 * Auto-refresh interval ID.
		 *
		 * @type {number|null}
		 */
		autoRefreshTimer: null,

		/**
		 * Current import status.
		 *
		 * @type {string}
		 */
		currentImportStatus: 'none',

		/**
		 * Whether the current import's local worker has died.
		 *
		 * @type {boolean}
		 */
		currentImportStale: false,

		/**
		 * Current log page.
		 *
		 * @type {number}
		 */
		logPage: 1,

		init: function () {
			this.bindEvents();
			this.fetchStatus();
			this.startAutoRefresh();
		},

		/**
		 * Bind UI event handlers.
		 */
		bindEvents: function () {
			$('#hh-migrator-validate-key').on('click', this.validateKey);
			$('#hh-migrator-save-config').on('click', this.saveConfig);
			$('#hh-migrator-run-preflight').on('click', this.runPreflight);
			$('#hh-migrator-start-migration').on('click', this.startMigration);
			$('#hh-migrator-resume-migration').on('click', this.resumeMigration);
			$('#hh-migrator-cancel-migration').on('click', this.cancelMigration);
			$('#hh-migrator-update-schedule').on('click', this.updateSchedule);
			$('#hh-migrator-refresh-log').on('click', this.refreshLog);
			$('#hh-migrator-refresh-status').on('click', this.refreshStatusBtn);
			$('#hh-migrator-clear-log').on('click', this.clearLog);
			$('#hh-migrator-download-debug').on('click', this.downloadDebug);
			$('#hh-migrator-dest-url-copy').on('click', this.copyDestUrl);
			$('#hh-migrator-log-prev').on('click', function () { HHMigrator.changeLogPage(-1); });
			$('#hh-migrator-log-next').on('click', function () { HHMigrator.changeLogPage(1); });
		},

		/**
		 * Make an AJAX request to the plugin backend.
		 *
		 * @param {string} action  AJAX action name.
		 * @param {Object} data    Additional request data.
		 * @param {Function} onSuccess Success callback.
		 * @param {Function} onError   Error callback.
		 */
		ajax: function (action, data, onSuccess, onError) {
			data = data || {};
			data.action = action;
			data._ajax_nonce = hh_migrator_ajax.nonce;

			$.post(hh_migrator_ajax.ajax_url, data, function (response) {
				if (response.success) {
					if (onSuccess) {
						onSuccess(response.data);
					}
				} else {
					if (onError) {
						onError(response.data);
					} else {
						HHMigrator.showNotice('error', response.data.message || 'An error occurred.');
					}
				}
			}).fail(function (jqXHR, textStatus, errorThrown) {
				// Hide any active spinners on the page.
				$('.spinner.is-active').remove();
				$('.button:disabled').prop('disabled', false);
				HHMigrator.showNotice('error', HHMigrator.formatAjaxError(action, jqXHR, textStatus, errorThrown));
			});
		},

		/**
		 * Build a human-readable error message from a jQuery AJAX failure.
		 *
		 * Surfaces the HTTP status, statusText, and any parseable JSON message
		 * or response snippet so server-side failures (PHP fatals, 500s, gateway
		 * timeouts, nonce/cap rejections) aren't hidden behind a generic notice.
		 *
		 * @param {string} action      AJAX action name that failed.
		 * @param {Object} jqXHR       jQuery XHR wrapper.
		 * @param {string} textStatus  jQuery textStatus ('timeout', 'error', 'parsererror', 'abort').
		 * @param {string} errorThrown HTTP status text or thrown exception.
		 * @return {string} Notice-ready message.
		 */
		formatAjaxError: function (action, jqXHR, textStatus, errorThrown) {
			var status = (jqXHR && jqXHR.status) || 0;
			var detail = '';

			if (textStatus === 'timeout') {
				detail = 'request timed out';
			} else if (status === 0) {
				detail = 'no response (network error or request aborted)';
			} else {
				var body = (jqXHR && jqXHR.responseText) || '';
				var parsed = null;
				try { parsed = JSON.parse(body); } catch (e) { /* not JSON */ }

				if (parsed && parsed.data && parsed.data.message) {
					detail = parsed.data.message;
				} else if (parsed && typeof parsed === 'object' && parsed.message) {
					detail = parsed.message;
				} else if (body) {
					// Strip HTML tags and collapse whitespace to surface PHP fatal text.
					var text = body.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
					if (text.length > 240) { text = text.substring(0, 240) + '…'; }
					detail = text || (errorThrown || textStatus || 'unknown error');
				} else {
					detail = errorThrown || textStatus || 'unknown error';
				}
			}

			var prefix = 'Request failed (' + action;
			if (status) { prefix += ', HTTP ' + status; }
			prefix += '): ';
			return prefix + detail;
		},

		/**
		 * Show a spinner next to a button and disable it.
		 *
		 * @param {jQuery} $btn Button element.
		 */
		showSpinner: function ($btn) {
			$btn.prop('disabled', true);
			if (!$btn.next('.spinner').length) {
				$btn.after('<span class="spinner is-active" style="float:none;margin:0 0 0 4px;"></span>');
			} else {
				$btn.next('.spinner').addClass('is-active');
			}
		},

		/**
		 * Hide the spinner next to a button and re-enable it.
		 *
		 * @param {jQuery} $btn Button element.
		 */
		hideSpinner: function ($btn) {
			$btn.prop('disabled', false);
			$btn.next('.spinner').remove();
		},

		/**
		 * Show a WordPress admin notice.
		 *
		 * @param {string} type    Notice type: 'success', 'error', 'warning', 'info'.
		 * @param {string} message Notice message.
		 */
		showNotice: function (type, message) {
			var $container = $('#hh-migrator-notices');
			var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p>' +
				'<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button></div>');

			$container.append($notice);

			// Dismiss on click.
			$notice.find('.notice-dismiss').on('click', function () {
				$notice.addClass('hh-migrator-fade-out');
				setTimeout(function () { $notice.remove(); }, 300);
			});

			// Auto-dismiss: errors stay 30s (long enough to read diagnostics), others 4s.
			var delay = type === 'error' ? 30000 : 4000;
			setTimeout(function () {
				if ($notice.parent().length) {
					$notice.addClass('hh-migrator-fade-out');
					setTimeout(function () { $notice.remove(); }, 300);
				}
			}, delay);
		},

		/**
		 * Validate the import key and display destination site info.
		 *
		 * @param {Event} e Click event.
		 */
		validateKey: function (e) {
			e.preventDefault();
			var $btn = $(this);
			HHMigrator.showSpinner($btn);

			HHMigrator.ajax('hh_migrator_validate_key', {
				import_key: $('#hh-migrator-import-key').val(),
				api_base_url: $('#hh-migrator-api-base-url').val()
			}, function (data) {
				HHMigrator.hideSpinner($btn);
				HHMigrator.showNotice('success', data.message || 'Import key is valid.');
				if (data.site) {
					HHMigrator.showDestSite(data.site);
				}
			}, function (data) {
				HHMigrator.hideSpinner($btn);
				HHMigrator.showNotice('error', data.message || 'Invalid import key.');
			});
		},

		/**
		 * Save plugin configuration.
		 *
		 * @param {Event} e Click event.
		 */
		saveConfig: function (e) {
			e.preventDefault();
			var $btn = $(this);
			HHMigrator.showSpinner($btn);

			HHMigrator.ajax('hh_migrator_save_config', {
				api_base_url: $('#hh-migrator-api-base-url').val(),
				import_key: $('#hh-migrator-import-key').val(),
				chunk_size: $('#hh-migrator-chunk-size').val(),
				compression: $('#hh-migrator-compression').val()
			}, function (data) {
				HHMigrator.hideSpinner($btn);
				HHMigrator.showNotice('success', data.message || 'Configuration saved.');
				if (data.site) {
					HHMigrator.showDestSite(data.site);
				}
			}, function (data) {
				HHMigrator.hideSpinner($btn);
				HHMigrator.showNotice('error', data.message || 'Failed to save configuration.');
			});
		},

		/**
		 * Run preflight checks only (results logged to migration log).
		 *
		 * @param {Event} e Click event.
		 */
		runPreflight: function (e) {
			e.preventDefault();
			var $btn = $(this);
			HHMigrator.showSpinner($btn);

			HHMigrator.ajax('hh_migrator_run_preflight', {}, function (data) {
				HHMigrator.hideSpinner($btn);
				var type = data.has_errors ? 'warning' : 'success';
				HHMigrator.showNotice(type, data.has_errors ? 'Preflight checks found errors — review the migration log.' : 'Preflight checks passed.');
				HHMigrator._fetchAndRenderLog(null);
			}, function (data) {
				HHMigrator.hideSpinner($btn);
				HHMigrator.showNotice('error', data.message || 'Failed to run preflight checks.');
			});
		},

		/**
		 * Start a migration.
		 *
		 * @param {Event} e Click event.
		 */
		startMigration: function (e) {
			e.preventDefault();
			var $btn = $(this);
			var mode = $('#hh-migrator-mode').val();
			HHMigrator.showSpinner($btn);

			HHMigrator.ajax('hh_migrator_start_migration', {
				mode: mode
			}, function (data) {
				HHMigrator.hideSpinner($btn);
				HHMigrator.showNotice('success', data.message || 'Migration started in the background.');
				HHMigrator.fetchStatus();
			}, function (data) {
				HHMigrator.hideSpinner($btn);
				HHMigrator.showNotice('error', data.message || 'Failed to start migration.');
			});
		},

		/**
		 * Resume an interrupted migration.
		 *
		 * @param {Event} e Click event.
		 */
		resumeMigration: function (e) {
			e.preventDefault();
			var $btn = $(this);
			HHMigrator.showSpinner($btn);

			HHMigrator.ajax('hh_migrator_resume_migration', {}, function (data) {
				HHMigrator.hideSpinner($btn);
				HHMigrator.showNotice('success', data.message || 'Migration resumed in the background.');
				HHMigrator.fetchStatus();
			}, function (data) {
				HHMigrator.hideSpinner($btn);
				HHMigrator.showNotice('error', data.message || 'Failed to resume migration.');
			});
		},

		/**
		 * Cancel an in-progress migration.
		 *
		 * @param {Event} e Click event.
		 */
		cancelMigration: function (e) {
			e.preventDefault();
			if (!confirm('Are you sure you want to cancel the migration?')) {
				return;
			}
			var $btn = $(this);
			HHMigrator.showSpinner($btn);

			HHMigrator.ajax('hh_migrator_cancel_migration', {}, function () {
				HHMigrator.hideSpinner($btn);
				HHMigrator.showNotice('info', 'Migration cancelled.');
				HHMigrator.currentImportStatus = 'none';
				HHMigrator.updateButtonStates();
				HHMigrator.updateProgressUI(null);
			}, function (data) {
				HHMigrator.hideSpinner($btn);
				HHMigrator.showNotice('error', data.message || 'Failed to cancel migration.');
			});
		},

		/**
		 * Poll migration status.
		 *
		 * @param {string} importId Import session ID.
		 */
		pollStatus: function (importId) {
			HHMigrator.ajax('hh_migrator_get_status', {
				import_id: importId
			}, function (data) {
				HHMigrator.updateProgressUI(data);

				if (data.status === 'completed') {
					HHMigrator.showNotice('success', 'Migration completed successfully.');
				} else if (data.status === 'failed') {
					HHMigrator.showNotice('error', 'Migration failed: ' + (data.last_error || 'Unknown error'));
				} else if (data.status !== 'cancelled') {
					// Continue polling.
					setTimeout(function () {
						HHMigrator.pollStatus(importId);
					}, 3000);
				}
			});
		},

		/**
		 * Update progress bar and status display.
		 *
		 * @param {Object|null} data Status data.
		 */
		updateProgressUI: function (data) {
			if (!data) {
				$('#hh-migrator-progress-section').hide();
				return;
			}

			$('#hh-migrator-progress-section').show();
			$('#hh-migrator-status-label').text(data.status);

			var percent = 0;
			if (data.file_progress && data.file_progress.total_bytes > 0) {
				percent = Math.round((data.file_progress.uploaded_bytes / data.file_progress.total_bytes) * 100);
			}

			$('#hh-migrator-progress-bar')
				.css('width', percent + '%')
				.text(percent + '%');
		},

		/**
		 * Update cron schedule.
		 *
		 * @param {Event} e Click event.
		 */
		updateSchedule: function (e) {
			e.preventDefault();
			var $btn = $(this);
			HHMigrator.showSpinner($btn);

			HHMigrator.ajax('hh_migrator_update_schedule', {
				enabled: $('#hh-migrator-schedule-enabled').is(':checked') ? '1' : '0',
				interval: $('#hh-migrator-schedule-interval').val()
			}, function (data) {
				HHMigrator.hideSpinner($btn);
				HHMigrator.showNotice('success', data.message || 'Schedule updated.');
			}, function (data) {
				HHMigrator.hideSpinner($btn);
				HHMigrator.showNotice('error', data.message || 'Failed to update schedule.');
			});
		},

		/**
		 * Refresh migration log entries and import status (button click handler).
		 *
		 * @param {Event} e Click event.
		 */
		refreshLog: function (e) {
			e.preventDefault();
			var $btn = $(this);
			HHMigrator.showSpinner($btn);
			HHMigrator.logPage = 1;

			HHMigrator._fetchAndRenderLog(function () {
				HHMigrator.hideSpinner($btn);
			});
			HHMigrator.fetchStatus();
		},

		/**
		 * Refresh status button click handler — fetches status and logs.
		 *
		 * @param {Event} e Click event.
		 */
		refreshStatusBtn: function (e) {
			e.preventDefault();
			HHMigrator.fetchStatus();
			HHMigrator._fetchAndRenderLog(null);
		},

		/**
		 * Fetch log entries and render them into the log table.
		 *
		 * @param {Function|null} onComplete Callback when done (success or error).
		 */
		_fetchAndRenderLog: function (onComplete) {
			HHMigrator.ajax('hh_migrator_refresh_log', { page: HHMigrator.logPage }, function (data) {
				var $body = $('#hh-migrator-log-body');
				$body.empty();

				if (!data.entries || !data.entries.length) {
					$body.append('<tr><td colspan="4">No log entries yet.</td></tr>');
				} else {
					$.each(data.entries, function (i, entry) {
						var level = entry.level || 'INFO';
						$body.append(
							'<tr>' +
							'<td>' + $('<span>').text(entry.created_at).html() + '</td>' +
							'<td>' + $('<span>').text(level).html() + '</td>' +
							'<td><code>' + $('<span>').text(entry.event).html() + '</code></td>' +
							'<td>' + $('<span>').text(entry.message).html() + '</td>' +
							'</tr>'
						);
					});
				}

				// Update pagination.
				var totalPages = data.total_pages || 1;
				var totalCount = data.total_count || 0;
				var currentPage = data.page || 1;
				HHMigrator.logPage = currentPage;

				$('#hh-migrator-log-current-page').text(currentPage);
				$('#hh-migrator-log-total-pages').text(totalPages);
				$('#hh-migrator-log-total').text(totalCount + ' items');
				$('#hh-migrator-log-prev').prop('disabled', currentPage <= 1);
				$('#hh-migrator-log-next').prop('disabled', currentPage >= totalPages);
				$('#hh-migrator-log-pagination').toggle(totalCount > 0 && totalPages > 1);

				if (onComplete) { onComplete(); }
			}, function () {
				if (onComplete) { onComplete(); }
			});
		},

		/**
		 * Change the log page by a delta (-1 or +1).
		 *
		 * @param {number} delta Page change direction.
		 */
		changeLogPage: function (delta) {
			HHMigrator.logPage = Math.max(1, HHMigrator.logPage + delta);
			HHMigrator._fetchAndRenderLog(null);
		},

		/**
		 * Fetch the current import status from the API and update button states.
		 */
		fetchStatus: function () {
			HHMigrator.ajax('hh_migrator_get_status', {}, function (data) {
				HHMigrator.currentImportStatus = data.status || 'none';
				HHMigrator.currentImportStale = data.stale || false;
				HHMigrator.updateButtonStates();
			}, function () {
				HHMigrator.currentImportStatus = 'none';
				HHMigrator.currentImportStale = false;
				HHMigrator.updateButtonStates();
			});
		},

		/**
		 * Enable or disable migration buttons based on the current import status.
		 *
		 * Status values: none, pending, uploading, ready, running, completed, cancelled, error
		 * stale: true if the local worker has died (lock expired) while API still shows active
		 */
		updateButtonStates: function () {
			var status = this.currentImportStatus;
			var stale = this.currentImportStale;
			var activeStatuses = ['pending', 'uploading', 'ready', 'running', 'exporting_files', 'exporting_db', 'completing'];
			var isActive = activeStatuses.indexOf(status) !== -1;
			var canResume = (status === 'error' || (isActive && stale));

			$('#hh-migrator-start-migration').prop('disabled', isActive || canResume);
			$('#hh-migrator-resume-migration').prop('disabled', !canResume);
			$('#hh-migrator-cancel-migration').prop('disabled', !isActive && !canResume);

			var label = (status === 'none') ? 'Not Started' : status.replace(/_/g, ' ');
			if (stale) {
				label += ' (stalled)';
			}
			$('#hh-migrator-import-status').text(label);
		},

		/**
		 * Start auto-refreshing the log and status every 30 seconds.
		 */
		startAutoRefresh: function () {
			if (this.autoRefreshTimer) { return; }

			this.autoRefreshTimer = setInterval(function () {
				HHMigrator._fetchAndRenderLog(null);
				HHMigrator.fetchStatus();
			}, 30000);
		},

		/**
		 * Clear all migration log entries (no confirmation dialog).
		 *
		 * @param {Event} e Click event.
		 */
		clearLog: function (e) {
			e.preventDefault();
			var $btn = $(this);
			HHMigrator.showSpinner($btn);

			HHMigrator.ajax('hh_migrator_clear_log', {}, function (data) {
				HHMigrator.hideSpinner($btn);
				HHMigrator.showNotice('success', data.message || 'Log cleared.');
				// Reset log state and pagination.
				HHMigrator.logPage = 1;
				$('#hh-migrator-log-body').empty().append(
					'<tr><td colspan="4">No log entries yet.</td></tr>'
				);
				$('#hh-migrator-log-current-page').text('1');
				$('#hh-migrator-log-total-pages').text('1');
				$('#hh-migrator-log-total').text('0 items');
				$('#hh-migrator-log-prev').prop('disabled', true);
				$('#hh-migrator-log-next').prop('disabled', true);
				$('#hh-migrator-log-pagination').hide();
			}, function (data) {
				HHMigrator.hideSpinner($btn);
				HHMigrator.showNotice('error', data.message || 'Failed to clear log.');
			});
		},

		/**
		 * Show destination site name and URL in the configuration section.
		 *
		 * @param {Object} site Site data with name and url properties.
		 */
		showDestSite: function (site) {
			if (site.name) {
				$('#hh-migrator-dest-name').text(site.name);
				$('#hh-migrator-dest-name-row').show();
			}
			if (site.url) {
				$('#hh-migrator-dest-url-link').attr('href', site.url).text(site.url);
				$('#hh-migrator-dest-url-row').show();
			}
		},

		/**
		 * Copy destination URL to clipboard.
		 *
		 * @param {Event} e Click event.
		 */
		copyDestUrl: function (e) {
			e.preventDefault();
			var url = $('#hh-migrator-dest-url-link').attr('href');
			if (url && navigator.clipboard) {
				navigator.clipboard.writeText(url);
				HHMigrator.showNotice('success', 'Destination URL copied to clipboard.');
			}
		},

		downloadDebug: function (e) {
			e.preventDefault();
			window.location.href = hh_migrator_ajax.ajax_url +
				'?action=hh_migrator_download_debug&_ajax_nonce=' + hh_migrator_ajax.nonce;
		}
	};

	$(document).ready(function () {
		HHMigrator.init();
	});

})(jQuery);
