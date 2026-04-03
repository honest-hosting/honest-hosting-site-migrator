<?php
/**
 * Debug download partial.
 *
 * @package HonestHosting\SiteMigrator\Admin\Views
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="hh-migrator-section">
	<h2><?php esc_html_e( 'Debug', 'honest-hosting-site-migrator' ); ?></h2>

	<p class="description">
		<?php esc_html_e( 'Download a debug data bundle containing session state, logs, and environment info. You can email this file to HonestHosting support for troubleshooting.', 'honest-hosting-site-migrator' ); ?>
	</p>

	<p>
		<button type="button" id="hh-migrator-download-debug" class="button">
			<?php esc_html_e( 'Download Debug Data', 'honest-hosting-site-migrator' ); ?>
		</button>
	</p>
</div>
