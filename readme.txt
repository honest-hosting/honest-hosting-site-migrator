=== HonestHosting Site Migrator ===
Contributors: honesthosting
Tags: migration, hosting, import, export, site-migrator
Requires at least: 6.7
Tested up to: 6.9
Stable tag: 0.0.1
Requires PHP: 8.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Migrate WordPress sites to HonestHosting via streamed, chunked, resumable exports.

== Description ==

This plugin is installed on an external source WordPress site and handles:

* Configuration and destination site selection
* Source-side validation and preflight checks
* Scanning source files and database
* Exporting data in streamed, memory-bounded chunks
* Uploading chunks directly to S3 via presigned URLs
* Notifying the HonestHosting backend when the import is ready
* Resuming interrupted migrations
* Optional scheduled incremental sync via WP-Cron

== Installation ==

1. Download or build the plugin zip.
2. Upload to `wp-content/plugins/honest-hosting-site-migrator/`.
3. Activate via WordPress admin.
4. Navigate to **Tools > HH Site Migrator**.

== Frequently Asked Questions ==

= What hosting environments are supported? =

The plugin works on standard WordPress installations including shared hosting environments with limited PHP resources. No shell access or external binaries are required.

= Can I resume an interrupted migration? =

Yes. All progress is persisted in local state files. Click "Resume Migration" to continue from the last checkpoint.

= Does this support WordPress multisite? =

Yes. Each site in a multisite network must individually activate the plugin and configure its own import key and destination.

== Changelog ==

= 0.0.1 =
* Initial release.
