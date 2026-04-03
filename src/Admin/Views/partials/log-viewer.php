<?php
/**
 * Log viewer partial.
 *
 * @package HonestHosting\SiteMigrator\Admin\Views
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="hh-migrator-section">
	<h2><?php esc_html_e( 'Migration Log', 'honest-hosting-site-migrator' ); ?></h2>

	<table class="widefat striped hh-migrator-log-table">
		<thead>
			<tr>
				<th class="column-created_at"><?php esc_html_e( 'Time', 'honest-hosting-site-migrator' ); ?></th>
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
					<td colspan="3"><?php esc_html_e( 'No log entries yet.', 'honest-hosting-site-migrator' ); ?></td>
				</tr>
				<?php
			else :
				foreach ( $hh_migrator_entries as $hh_migrator_entry ) :
					?>
					<tr>
						<td><?php echo esc_html( $hh_migrator_entry->created_at ); ?></td>
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
