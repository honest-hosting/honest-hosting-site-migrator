<?php
/**
 * Scheduling controls partial.
 *
 * @package HonestHosting\SiteMigrator\Admin\Views
 *
 * @var array<string, mixed> $hh_view View data from AdminPage.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="hh-migrator-section">
	<h2><?php esc_html_e( 'Scheduled Incremental Sync', 'honest-hosting-site-migrator' ); ?></h2>

	<?php if ( ! $hh_view['wp_cron_available'] ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php esc_html_e( 'WP-Cron is disabled on this site. Scheduled sync is unavailable, but you can still run manual syncs.', 'honest-hosting-site-migrator' ); ?>
			</p>
		</div>
	<?php else : ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<?php esc_html_e( 'Enable Schedule', 'honest-hosting-site-migrator' ); ?>
				</th>
				<td>
					<label>
						<input
							type="checkbox"
							id="hh-migrator-schedule-enabled"
							value="1"
							<?php checked( $hh_view['schedule_enabled'] ); ?>
						/>
						<?php esc_html_e( 'Run incremental sync on a schedule', 'honest-hosting-site-migrator' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="hh-migrator-schedule-interval"><?php esc_html_e( 'Interval', 'honest-hosting-site-migrator' ); ?></label>
				</th>
				<td>
					<select id="hh-migrator-schedule-interval">
						<option value="hh_migrator_1h" <?php selected( $hh_view['schedule_interval'], 'hh_migrator_1h' ); ?>>
							<?php esc_html_e( 'Every 1 Hour', 'honest-hosting-site-migrator' ); ?>
						</option>
						<option value="hh_migrator_4h" <?php selected( $hh_view['schedule_interval'], 'hh_migrator_4h' ); ?>>
							<?php esc_html_e( 'Every 4 Hours', 'honest-hosting-site-migrator' ); ?>
						</option>
						<option value="hh_migrator_12h" <?php selected( $hh_view['schedule_interval'], 'hh_migrator_12h' ); ?>>
							<?php esc_html_e( 'Every 12 Hours', 'honest-hosting-site-migrator' ); ?>
						</option>
						<option value="hh_migrator_24h" <?php selected( $hh_view['schedule_interval'], 'hh_migrator_24h' ); ?>>
							<?php esc_html_e( 'Every 24 Hours', 'honest-hosting-site-migrator' ); ?>
						</option>
					</select>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="button" id="hh-migrator-update-schedule" class="button">
				<?php esc_html_e( 'Update Schedule', 'honest-hosting-site-migrator' ); ?>
			</button>
		</p>
	<?php endif; ?>
</div>
