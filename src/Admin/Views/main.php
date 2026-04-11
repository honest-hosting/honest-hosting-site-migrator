<?php
/**
 * Main admin page template.
 *
 * @package HonestHosting\SiteMigrator\Admin\Views
 *
 * @var array<string, mixed> $hh_view View data from AdminPage.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'HonestHosting Site Migrator', 'honest-hosting-site-migrator' ); ?></h1>

	<div class="hh-migrator-section">
		<h2><?php esc_html_e( 'Instructions', 'honest-hosting-site-migrator' ); ?></h2>
		<p><?php esc_html_e( 'Migrate your site from this host into HonestHosting. Follow these steps to get started:', 'honest-hosting-site-migrator' ); ?></p>
		<ol>
			<li><?php esc_html_e( 'Enter your Destination Site Import Key (found in the HonestHosting dashboard for your destination site) and click Validate Key.', 'honest-hosting-site-migrator' ); ?></li>
			<li><?php esc_html_e( 'Click Save Configuration to store your settings.', 'honest-hosting-site-migrator' ); ?></li>
			<li><?php esc_html_e( 'Run Preflight Checks and review the migration log for any errors before proceeding.', 'honest-hosting-site-migrator' ); ?></li>
			<li><?php esc_html_e( 'Choose a migration mode (Full Import or Incremental) and start the sync.', 'honest-hosting-site-migrator' ); ?></li>
			<li><?php esc_html_e( 'Optionally, configure a periodic sync schedule for ongoing incremental updates.', 'honest-hosting-site-migrator' ); ?></li>
		</ol>
	</div>

	<div id="hh-migrator-notices"></div>

	<?php require HH_MIGRATOR_PATH . 'src/Admin/Views/partials/configuration.php'; ?>

	<?php require HH_MIGRATOR_PATH . 'src/Admin/Views/partials/migration-controls.php'; ?>

	<?php require HH_MIGRATOR_PATH . 'src/Admin/Views/partials/scheduling.php'; ?>

	<?php require HH_MIGRATOR_PATH . 'src/Admin/Views/partials/debug-download.php'; ?>
</div>
