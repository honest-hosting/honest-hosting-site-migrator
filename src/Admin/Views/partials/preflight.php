<?php
/**
 * Preflight checks partial.
 *
 * @package HonestHosting\SiteMigrator\Admin\Views
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="hh-migrator-section">
	<h2><?php esc_html_e( 'Preflight Checks', 'honest-hosting-site-migrator' ); ?></h2>

	<p class="description">
		<?php esc_html_e( 'Run preflight checks to identify potential issues before starting the migration.', 'honest-hosting-site-migrator' ); ?>
	</p>

	<p>
		<button type="button" id="hh-migrator-run-preflight" class="button">
			<?php esc_html_e( 'Run Preflight Checks', 'honest-hosting-site-migrator' ); ?>
		</button>
	</p>

	<div id="hh-migrator-preflight-results"></div>
</div>
