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
		init: function () {
			this.bindEvents();
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
			$('#hh-migrator-download-debug').on('click', this.downloadDebug);
			$(document).on('click', '.hh-migrator-select-site', this.selectDestination);
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
				HHMigrator.showNotice('error', 'Request failed. Please try again.');
			});
		},

		/**
		 * Show a WordPress admin notice.
		 *
		 * @param {string} type    Notice type: 'success', 'error', 'warning', 'info'.
		 * @param {string} message Notice message.
		 */
		showNotice: function (type, message) {
			var $container = $('#hh-migrator-notices');
			var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
			$container.empty().append($notice);

			// Trigger WordPress dismissible notice JS if available.
			if (typeof wp !== 'undefined' && wp.notices) {
				wp.notices.removeDismissible();
			}
		},

		/**
		 * Validate the import key.
		 *
		 * @param {Event} e Click event.
		 */
		validateKey: function (e) {
			e.preventDefault();
			var $btn = $(this);
			$btn.prop('disabled', true).text('Validating...');

			HHMigrator.ajax('hh_migrator_validate_key', {
				import_key: $('#hh-migrator-import-key').val(),
				api_base_url: $('#hh-migrator-api-base-url').val()
			}, function (data) {
				$btn.prop('disabled', false).text('Validate Key');
				HHMigrator.showNotice('success', data.message || 'Import key is valid.');
				if (data.sites) {
					HHMigrator.renderDestinations(data.sites);
				}
			}, function (data) {
				$btn.prop('disabled', false).text('Validate Key');
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
			HHMigrator.ajax('hh_migrator_save_config', {
				api_base_url: $('#hh-migrator-api-base-url').val(),
				import_key: $('#hh-migrator-import-key').val(),
				chunk_size: $('#hh-migrator-chunk-size').val()
			}, function (data) {
				HHMigrator.showNotice('success', data.message || 'Configuration saved.');
			});
		},

		/**
		 * Render destination sites table.
		 *
		 * @param {Array} sites Array of site objects.
		 */
		renderDestinations: function (sites) {
			var $table = $('#hh-migrator-destinations-body');
			$table.empty();

			if (!sites.length) {
				$table.append('<tr><td colspan="4">No eligible destination sites found.</td></tr>');
				return;
			}

			$.each(sites, function (i, site) {
				$table.append(
					'<tr>' +
					'<td>' + site.name + '</td>' +
					'<td>' + site.domain + '</td>' +
					'<td>' + (site.php_version || 'N/A') + '</td>' +
					'<td><button class="button hh-migrator-select-site" data-site-id="' + site.id + '">Select</button></td>' +
					'</tr>'
				);
			});

			$('#hh-migrator-destination-section').show();
		},

		/**
		 * Select a destination site.
		 *
		 * @param {Event} e Click event.
		 */
		selectDestination: function (e) {
			e.preventDefault();
			var siteId = $(this).data('site-id');

			HHMigrator.ajax('hh_migrator_select_destination', {
				site_id: siteId
			}, function (data) {
				HHMigrator.showNotice('success', data.message || 'Destination site selected.');
				$('#hh-migrator-selected-site').text(data.site_name || siteId);
				$('#hh-migrator-migration-section').show();
			});
		},

		/**
		 * Run preflight checks.
		 *
		 * @param {Event} e Click event.
		 */
		runPreflight: function (e) {
			e.preventDefault();
			var $btn = $(this);
			$btn.prop('disabled', true).text('Running checks...');

			HHMigrator.ajax('hh_migrator_run_preflight', {}, function (data) {
				$btn.prop('disabled', false).text('Run Preflight Checks');
				HHMigrator.renderPreflightResults(data.results);
			}, function (data) {
				$btn.prop('disabled', false).text('Run Preflight Checks');
			});
		},

		/**
		 * Render preflight results.
		 *
		 * @param {Array} results Array of result items.
		 */
		renderPreflightResults: function (results) {
			var $container = $('#hh-migrator-preflight-results');
			$container.empty();

			if (!results || !results.length) {
				$container.append('<p>No issues found. Ready to migrate.</p>');
				return;
			}

			$.each(results, function (i, item) {
				$container.append(
					'<div class="hh-migrator-preflight-item ' + item.type + '">' +
					'<strong>[' + item.type.toUpperCase() + ']</strong> ' + item.message +
					'</div>'
				);
			});
		},

		/**
		 * Start a migration.
		 *
		 * @param {Event} e Click event.
		 */
		startMigration: function (e) {
			e.preventDefault();
			var mode = $('#hh-migrator-mode').val();

			HHMigrator.ajax('hh_migrator_start_migration', {
				mode: mode
			}, function (data) {
				HHMigrator.showNotice('success', 'Migration started.');
				HHMigrator.pollStatus(data.import_id);
			});
		},

		/**
		 * Resume an interrupted migration.
		 *
		 * @param {Event} e Click event.
		 */
		resumeMigration: function (e) {
			e.preventDefault();

			HHMigrator.ajax('hh_migrator_resume_migration', {}, function (data) {
				HHMigrator.showNotice('success', 'Migration resumed.');
				HHMigrator.pollStatus(data.import_id);
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

			HHMigrator.ajax('hh_migrator_cancel_migration', {}, function () {
				HHMigrator.showNotice('info', 'Migration cancelled.');
				HHMigrator.updateProgressUI(null);
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

			HHMigrator.ajax('hh_migrator_update_schedule', {
				enabled: $('#hh-migrator-schedule-enabled').is(':checked') ? '1' : '0',
				interval: $('#hh-migrator-schedule-interval').val()
			}, function (data) {
				HHMigrator.showNotice('success', data.message || 'Schedule updated.');
			});
		},

		/**
		 * Download debug data.
		 *
		 * @param {Event} e Click event.
		 */
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
