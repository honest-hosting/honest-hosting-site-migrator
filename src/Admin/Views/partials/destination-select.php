<?php
/**
 * Destination site selection partial.
 *
 * @package HonestHosting\SiteMigrator\Admin\Views
 *
 * @var array<string, mixed> $hh_view View data from AdminPage.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="hh-migrator-section" id="hh-migrator-destination-section" style="<?php echo empty( $hh_view['import_key'] ) ? 'display:none;' : ''; ?>">
	<h2><?php esc_html_e( 'Destination Site', 'honest-hosting-site-migrator' ); ?></h2>

	<?php if ( ! empty( $hh_view['destination_id'] ) ) : ?>
		<p>
			<?php esc_html_e( 'Selected:', 'honest-hosting-site-migrator' ); ?>
			<strong id="hh-migrator-selected-site"><?php echo esc_html( $hh_view['destination_id'] ); ?></strong>
		</p>
	<?php endif; ?>

	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'honest-hosting-site-migrator' ); ?></th>
				<th><?php esc_html_e( 'Domain', 'honest-hosting-site-migrator' ); ?></th>
				<th><?php esc_html_e( 'PHP Version', 'honest-hosting-site-migrator' ); ?></th>
				<th><?php esc_html_e( 'Action', 'honest-hosting-site-migrator' ); ?></th>
			</tr>
		</thead>
		<tbody id="hh-migrator-destinations-body">
			<tr>
				<td colspan="4">
					<?php esc_html_e( 'Validate your import key to load destination sites.', 'honest-hosting-site-migrator' ); ?>
				</td>
			</tr>
		</tbody>
	</table>
</div>
