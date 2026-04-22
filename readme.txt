=== HonestHosting Site Migrator ===
Contributors: mywp459
Tags: migration, hosting, import, export, site-migrator
Requires at least: 6.7
Tested up to: 6.9
Stable tag: 1.0.0
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

== External services ==

This plugin relies on two third-party services to perform site migrations to HonestHosting. Both are only contacted after the site administrator explicitly configures an import key and initiates a migration action from the plugin admin UI.

= HonestHosting API (api.honesthosting.io) =

The plugin communicates with the HonestHosting backend API at `https://api.honesthosting.io` to authenticate the site administrator's import key, enumerate eligible destination sites, obtain presigned S3 upload URLs, submit preflight estimates, and signal that the destination is ready to import.

What data is sent and when:

* When the administrator validates an import key or loads the migration UI: the import key and source site URL.
* During preflight: source PHP version, estimated `wp-content` total size and file count, database table summaries (table names, estimated row counts and byte sizes, engine types), and detected hosting-environment capabilities.
* During export: session identifiers, chunk manifests, file paths and sizes, database table progress, and hash values used to validate uploaded chunks.
* When upload completes: an import-ready signal containing the session metadata and destination site identifier.

The import key is sent in the `X-HH-Site-Import-Key` request header over HTTPS.

This service is provided by HonestHosting:

* Terms and Conditions: https://www.honesthosting.io/terms-and-conditions/
* Privacy Policy: https://www.honesthosting.io/privacy-policy/

= Amazon S3 (presigned upload URLs) =

The plugin uploads file and database export chunks directly to Amazon S3 using presigned `PUT` URLs issued by the HonestHosting backend. Payloads are not proxied through the HonestHosting API; they are transmitted straight from the source site to S3 over HTTPS.

What data is sent and when:

* During export: each chunk body is `PUT` to the presigned S3 URL. File chunks contain raw file bytes from `wp-content/`; database chunks contain SQL INSERT statements generated from the site's database tables. Requests carry only `Content-Type` and `Content-Length` headers — authentication is embedded in the presigned URL itself, so no account credentials or import keys are sent to S3.

The S3 buckets used for migration storage are operated by HonestHosting on Amazon Web Services. AWS terms and privacy documents apply to the underlying transport and storage:

* AWS Service Terms: https://aws.amazon.com/service-terms/
* AWS Privacy Notice: https://aws.amazon.com/privacy/

== Changelog ==

= 0.0.1 =
* Initial release.
