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

	<div id="hh-migrator-notices"></div>

	<?php require HH_MIGRATOR_PATH . 'src/Admin/Views/partials/configuration.php'; ?>

	<?php require HH_MIGRATOR_PATH . 'src/Admin/Views/partials/destination-select.php'; ?>

	<?php require HH_MIGRATOR_PATH . 'src/Admin/Views/partials/preflight.php'; ?>

	<?php require HH_MIGRATOR_PATH . 'src/Admin/Views/partials/migration-controls.php'; ?>

	<?php require HH_MIGRATOR_PATH . 'src/Admin/Views/partials/scheduling.php'; ?>

	<?php require HH_MIGRATOR_PATH . 'src/Admin/Views/partials/log-viewer.php'; ?>

	<?php require HH_MIGRATOR_PATH . 'src/Admin/Views/partials/debug-download.php'; ?>
</div>
