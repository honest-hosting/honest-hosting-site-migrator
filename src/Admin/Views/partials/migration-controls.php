<?php
/**
 * Migration controls partial (includes log viewer).
 *
 * @package HonestHosting\SiteMigrator\Admin\Views
 *
 * @var array<string, mixed> $hh_view View data from AdminPage.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="hh-migrator-section" id="hh-migrator-migration-section">
	<h2><?php esc_html_e( 'Migration', 'honest-hosting-site-migrator' ); ?></h2>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">
				<label for="hh-migrator-mode"><?php esc_html_e( 'Mode', 'honest-hosting-site-migrator' ); ?></label>
			</th>
			<td>
				<select id="hh-migrator-mode">
					<option value="full"><?php esc_html_e( 'Full Import', 'honest-hosting-site-migrator' ); ?></option>
					<option value="incremental_all"><?php esc_html_e( 'Incremental — All', 'honest-hosting-site-migrator' ); ?></option>
					<option value="incremental_files"><?php esc_html_e( 'Incremental — Files Only', 'honest-hosting-site-migrator' ); ?></option>
					<option value="incremental_db"><?php esc_html_e( 'Incremental — Database Only', 'honest-hosting-site-migrator' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Status', 'honest-hosting-site-migrator' ); ?></th>
			<td>
				<code id="hh-migrator-import-status"><?php esc_html_e( 'Not Started', 'honest-hosting-site-migrator' ); ?></code>
				<button type="button" id="hh-migrator-refresh-status" class="button-link" title="<?php esc_attr_e( 'Refresh status', 'honest-hosting-site-migrator' ); ?>" style="margin-left: 4px; vertical-align: middle;">
					<span class="dashicons dashicons-update" style="font-size: 16px; width: 16px; height: 16px;"></span>
				</button>
			</td>
		</tr>
	</table>

	<p class="submit">
		<button type="button" id="hh-migrator-start-migration" class="button button-primary">
			<?php esc_html_e( 'Start Migration', 'honest-hosting-site-migrator' ); ?>
		</button>
		<button type="button" id="hh-migrator-resume-migration" class="button">
			<?php esc_html_e( 'Resume Migration', 'honest-hosting-site-migrator' ); ?>
		</button>
		<button type="button" id="hh-migrator-cancel-migration" class="button">
			<?php esc_html_e( 'Cancel', 'honest-hosting-site-migrator' ); ?>
		</button>
	</p>

	<div id="hh-migrator-progress-section" style="display:none;">
		<div class="hh-migrator-progress">
			<div id="hh-migrator-progress-bar" class="hh-migrator-progress-bar" style="width:0%;">0%</div>
		</div>
	</div>

	<div style="margin-top: 15px;">
		<h3 style="margin-bottom: 10px;"><?php esc_html_e( 'Migration Log', 'honest-hosting-site-migrator' ); ?></h3>

		<p>
			<button type="button" id="hh-migrator-refresh-log" class="button">
				<?php esc_html_e( 'Refresh Log', 'honest-hosting-site-migrator' ); ?>
			</button>
			<button type="button" id="hh-migrator-clear-log" class="button">
				<?php esc_html_e( 'Clear Log', 'honest-hosting-site-migrator' ); ?>
			</button>
		</p>

		<table class="widefat striped hh-migrator-log-table">
				<thead>
					<tr>
						<th class="column-created_at"><?php esc_html_e( 'Time', 'honest-hosting-site-migrator' ); ?></th>
						<th class="column-level"><?php esc_html_e( 'Level', 'honest-hosting-site-migrator' ); ?></th>
						<th class="column-event"><?php esc_html_e( 'Event', 'honest-hosting-site-migrator' ); ?></th>
						<th><?php esc_html_e( 'Message', 'honest-hosting-site-migrator' ); ?></th>
					</tr>
				</thead>
				<tbody id="hh-migrator-log-body">
					<?php
					$hh_migrator_logger  = new \HonestHosting\SiteMigrator\Log\MigrationLogger();
					$hh_migrator_entries = $hh_migrator_logger->get_recent( 100 );

					if ( empty( $hh_migrator_entries ) ) :
						?>
						<tr>
							<td colspan="4"><?php esc_html_e( 'No log entries yet.', 'honest-hosting-site-migrator' ); ?></td>
						</tr>
						<?php
					else :
						foreach ( $hh_migrator_entries as $hh_migrator_entry ) :
							?>
							<tr>
								<td><?php echo esc_html( $hh_migrator_entry->created_at ); ?></td>
								<td><?php echo esc_html( $hh_migrator_entry->level ); ?></td>
								<td><code><?php echo esc_html( $hh_migrator_entry->event ); ?></code></td>
								<td><?php echo esc_html( $hh_migrator_entry->message ); ?></td>
							</tr>
							<?php
						endforeach;
					endif;
					?>
				</tbody>
			</table>
	</div>
</div>
