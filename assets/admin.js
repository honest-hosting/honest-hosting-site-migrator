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
			}).fail(function () {
				// Hide any active spinners on the page.
				$('.spinner.is-active').remove();
				$('.button:disabled').prop('disabled', false);
				HHMigrator.showNotice('error', 'Request failed. Please try again.');
			});
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

			// Auto-dismiss: errors stay 8s, others 4s.
			var delay = type === 'error' ? 8000 : 4000;
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
				if (data.site && data.site.url) {
					HHMigrator.showDestUrl(data.site.url);
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
				chunk_size: $('#hh-migrator-chunk-size').val()
			}, function (data) {
				HHMigrator.hideSpinner($btn);
				var type = data.has_errors ? 'warning' : 'success';
				HHMigrator.showNotice(type, data.message || 'Configuration saved.');
				HHMigrator._fetchAndRenderLog(null);
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
			HHMigrator.ajax('hh_migrator_refresh_log', {}, function (data) {
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

				if (onComplete) { onComplete(); }
			}, function () {
				if (onComplete) { onComplete(); }
			});
		},

		/**
		 * Fetch the current import status from the API and update button states.
		 */
		fetchStatus: function () {
			HHMigrator.ajax('hh_migrator_get_status', {}, function (data) {
				HHMigrator.currentImportStatus = data.status || 'none';
				HHMigrator.updateButtonStates();
			}, function () {
				HHMigrator.currentImportStatus = 'none';
				HHMigrator.updateButtonStates();
			});
		},

		/**
		 * Enable or disable migration buttons based on the current import status.
		 *
		 * Status values: none, pending, uploading, ready, running, completed, cancelled, error
		 */
		updateButtonStates: function () {
			var status = this.currentImportStatus;
			var isActive = (status === 'pending' || status === 'uploading' || status === 'ready' || status === 'running');
			var canResume = (status === 'error');

			$('#hh-migrator-start-migration').prop('disabled', isActive);
			$('#hh-migrator-resume-migration').prop('disabled', isActive && !canResume);
			$('#hh-migrator-cancel-migration').prop('disabled', !isActive);

			var label = (status === 'none') ? 'Not Started' : status;
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
		 * Clear all migration log entries.
		 *
		 * @param {Event} e Click event.
		 */
		clearLog: function (e) {
			e.preventDefault();
			if (!confirm('Are you sure you want to clear all log entries?')) {
				return;
			}
			var $btn = $(this);
			HHMigrator.showSpinner($btn);

			HHMigrator.ajax('hh_migrator_clear_log', {}, function (data) {
				HHMigrator.hideSpinner($btn);
				HHMigrator.showNotice('success', data.message || 'Log cleared.');
				$('#hh-migrator-log-body').empty().append(
					'<tr><td colspan="4">No log entries yet.</td></tr>'
				);
			}, function (data) {
				HHMigrator.hideSpinner($btn);
				HHMigrator.showNotice('error', data.message || 'Failed to clear log.');
			});
		},

		/**
		 * Download debug data.
		 *
		 * @param {Event} e Click event.
		 */
		/**
		 * Show the destination site URL in the configuration section.
		 *
		 * @param {string} url Destination site URL.
		 */
		showDestUrl: function (url) {
			$('#hh-migrator-dest-url-link').attr('href', url).text(url);
			$('#hh-migrator-dest-url-row').show();
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
