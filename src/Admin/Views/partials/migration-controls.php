<?php
/**
 * Migration controls partial.
 *
 * @package HonestHosting\SiteMigrator\Admin\Views
 *
 * @var array<string, mixed> $hh_view View data from AdminPage.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="hh-migrator-section" id="hh-migrator-migration-section" style="<?php echo empty( $hh_view['destination_id'] ) ? 'display:none;' : ''; ?>">
	<h2><?php esc_html_e( 'Migration', 'honest-hosting-site-migrator' ); ?></h2>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">
				<label for="hh-migrator-mode"><?php esc_html_e( 'Mode', 'honest-hosting-site-migrator' ); ?></label>
			</th>
			<td>
				<select id="hh-migrator-mode">
					<option value="full"><?php esc_html_e( 'Full Import', 'honest-hosting-site-migrator' ); ?></option>
					<option value="incremental_all"><?php esc_html_e( 'Incremental \u2014 All', 'honest-hosting-site-migrator' ); ?></option>
					<option value="incremental_files"><?php esc_html_e( 'Incremental \u2014 Files Only', 'honest-hosting-site-migrator' ); ?></option>
					<option value="incremental_db"><?php esc_html_e( 'Incremental \u2014 Database Only', 'honest-hosting-site-migrator' ); ?></option>
				</select>
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
		<p>
			<?php esc_html_e( 'Status:', 'honest-hosting-site-migrator' ); ?>
			<span id="hh-migrator-status-label" class="hh-migrator-status pending"><?php esc_html_e( 'Pending', 'honest-hosting-site-migrator' ); ?></span>
		</p>
		<div class="hh-migrator-progress">
			<div id="hh-migrator-progress-bar" class="hh-migrator-progress-bar" style="width:0%;">0%</div>
		</div>
	</div>
</div>
