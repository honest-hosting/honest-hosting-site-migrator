# HonestHosting Site Migrator

WordPress plugin for migrating sites to HonestHosting via streamed, chunked, resumable exports.

## Overview

This plugin is installed on an **external source WordPress site** and handles:

- Configuration and destination site selection
- Source-side validation and preflight checks
- Scanning source files and database
- Exporting data in streamed, memory-bounded chunks
- Uploading chunks directly to S3 via presigned URLs
- Notifying the HonestHosting backend when the import is ready
- Resuming interrupted migrations
- Optional scheduled incremental sync via WP-Cron

## Requirements

- WordPress 6.7+
- PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, or 8.5
- Composer (for development)
- No shell access required
- No external binaries required (pure PHP)

PHP 7.4 is supported so that legacy source sites can be migrated to HonestHosting without
requiring a PHP upgrade on the source host first. It is past end-of-life upstream — a
migration-path concession, not an endorsement of running 7.4 long term.

## Installation

1. Download or build the plugin zip
2. Upload to `wp-content/plugins/honest-hosting-site-migrator/`
3. Activate via WordPress admin
4. Navigate to **Tools > HH Site Migrator**

## Configuration

### Admin UI

1. **API Base URL** - Pre-filled with `https://api.honesthosting.io`. Override if needed.
2. **Site Import Key** - Provided by HonestHosting during onboarding.
3. **Chunk Size** - Default 10 MB. Configurable from 5 MB to 20 MB.

### Constants (wp-config.php)

Override the API base URL for development/staging:

```php
define( 'HH_MIGRATOR_API_BASE_URL', 'https://staging-api.honesthosting.io' );
```

## Usage

### Full Migration

1. Enter your import key and validate it
2. Select a destination site from the list
3. Run preflight checks
4. Click "Start Migration" with mode "Full Import"
5. Monitor progress in the admin UI

### Incremental Migration

After an initial full import, run incremental updates:

- **Incremental - All**: Upload changed files + re-export changed database tables
- **Incremental - Files Only**: Upload changed files only
- **Incremental - Database Only**: Re-export database if tables changed

### Scheduled Sync

If WP-Cron is available, enable scheduled incremental sync at intervals of 1h, 4h, 12h, or 24h. Scheduled runs use randomized jitter to avoid thundering herd behavior. Only incremental sync can be scheduled.

### Resume

If a migration is interrupted (PHP timeout, page reload, network error), click "Resume Migration" to continue from the last checkpoint. Progress is persisted in a local session store — SQLite3 when the extension is available, otherwise a table in the site's own database.

### Cancel

"Cancel" clears both the local session and the import registered on the HonestHosting backend
(`DELETE /v1/siteImport`, scoped to the import key). It stays available whenever a destination is
configured, not only while a migration is visibly running — a backend import left in
`pending`/`uploading`/`ready`/`running` will otherwise reject new imports with HTTP 409, and local
state can be absent or diverged after a plugin reinstall or a wiped session store. Cancelling when
nothing is active is a harmless no-op.

### Debug

Click "Download Debug Data" to generate a JSON bundle containing session state, logs, and environment info. Email this to HonestHosting support for troubleshooting. The import key is redacted in the bundle.

## Architecture

```
src/
  Plugin.php                  # Singleton lifecycle, hooks, cron schedules
  Admin/
    AdminPage.php             # Tools menu page, WordPress native admin UI
    AjaxHandler.php           # AJAX endpoints (nonce + capability verified)
    Views/                    # PHP template partials
  Api/
    ApiEndpoints.php          # Central URL registry (all endpoints in one place)
    HonestHostingClient.php   # HTTP client for HH backend
    S3Uploader.php            # Presigned URL chunk uploader with retry
  Export/
    FileExporter.php          # wp-content scan, chunked file export
    DatabaseExporter.php      # PHP-native SQL export, streamed rows
    ChunkEncoder.php          # Gzip compression + metadata framing
  Migration/
    MigrationOrchestrator.php # Top-level flow controller
    BackgroundRunner.php      # Dispatches export/resume as WP-Cron events
    SessionManager.php        # Session state, progress, locks
    SourceEstimator.php       # Source file/database size estimation
    ManifestBuilder.php       # Migration manifest generation
    ResumeHandler.php         # Resume detection and continuation
  Storage/
    StorageFactory.php        # Selects SQLite3 when available, else MySQL
    SessionStorageInterface.php
    SqliteStorage.php         # SQLite3 session store (preferred)
    MysqlStorage.php          # Fallback store in the site's own database
  Preflight/
    PreflightRunner.php       # Orchestrates all checks
    PreflightResult.php       # Error/warning/info result DTO
    Checks/                   # Individual check implementations
  Schedule/
    CronScheduler.php         # WP-Cron incremental sync with jitter
  Log/
    MigrationLogger.php       # Structured event logging to DB
  Util/
    ChunkSizeValidator.php    # Human-readable size parsing (5MB-20MB)
    FormatHelper.php          # Byte/duration formatting
    LockHolder.php            # Session lock ownership
    PathHelper.php            # Path normalization helpers
```

## API Endpoints

All requests include the `X-API-Site-Import-Token` header, which carries the site import key and
scopes every call to that one site. Endpoints are defined in `ApiEndpoints.php`:

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/v1/siteImport` | Get destination site metadata for the key |
| POST | `/v1/siteImport` | Create import session |
| DELETE | `/v1/siteImport` | Cancel the active import for the key |
| POST | `/v1/siteImport/validate` | Validate capacity (preflight) |
| GET | `/v1/siteImport/{importId}` | Get import status |
| POST | `/v1/siteImport/{importId}/uploadUrl` | Get presigned S3 URL |
| POST | `/v1/siteImport/finalize` | Signal the destination is ready to import |

## Multisite

Per-site activation. Each site in a multisite network must individually activate the plugin, configure its own import key, and select its destination. Exports only the current site's data (scoped to `$wpdb->prefix`). No network-wide import in V1.

## Development

### Setup

```bash
cd honest-hosting-site-migrator
composer install
```

### Testing

```bash
# Start test database
make test-setup

# Run unit tests
make test

# Run integration tests
TEST_TYPE=test:integration make test

# Run specific test
TEST="tests/Unit/Api/ApiEndpointsTest.php" make test
```

### Local PHP support matrix

`make localdev` runs a WordPress install per supported PHP version, each with its own ephemeral
MariaDB, so the plugin can be exercised by hand against every runtime it claims to support. All
cells run identical WordPress core, so any failure is attributable to the PHP version alone.

```bash
make localdev PHP=7.4        # single cell (the usual case)
make localdev PHP=all        # all 7 cells (14 containers)
make localdev-logs PHP=7.4   # tail a cell
make localdev-shell PHP=7.4  # shell into a cell
make clean                   # tears the matrix down, among other things
```

| PHP | WordPress | MariaDB |
|-----|-----------|---------|
| 7.4 | http://localhost:10074 | 30074 |
| 8.0 | http://localhost:10080 | 30080 |
| 8.1 | http://localhost:10081 | 30081 |
| 8.2 | http://localhost:10082 | 30082 |
| 8.3 | http://localhost:10083 | 30083 |
| 8.4 | http://localhost:10084 | 30084 |
| 8.5 | http://localhost:10085 | 30085 |

Login `administrator` / `dead-beef`. The built plugin is bind-mounted from
`build/honest-hosting-site-migrator/`, so `make build` and a browser refresh is the whole edit
loop — there is no deploy step. Configuration lives in `docker/localdev/`.

Overridable via environment: `WP_VERSION` (default 7.1), `WP_ADMIN_USER`, `WP_ADMIN_PASSWORD`,
`WP_EXTRA_PLUGINS`, and the deliberately modest `PHP_MEMORY_LIMIT` / `PHP_MAX_EXECUTION_TIME` /
`PHP_UPLOAD_MAX_FILESIZE` / `PHP_POST_MAX_SIZE`, which default to shared-hosting-like values so
the chunked export path gets exercised under realistic constraints.

```bash
WP_VERSION=7.0.4 make localdev PHP=8.5      # pin a different WordPress
PHP_MEMORY_LIMIT=64M make localdev PHP=7.4  # tighter limits
```

[Plugin Check (PCP)](https://wordpress.org/plugins/plugin-check/) is installed and activated on
every cell for pre-release validation:

```bash
docker exec hh-migrator-wp-74 \
  wp plugin check honest-hosting-site-migrator --allow-root --path=/var/www/html
```

The matrix is kept in its own Compose project (`hh-migrator-localdev`) and compose file so it
cannot disturb the integration-test database started by `make test-setup`.

### Code Quality

```bash
composer cs:check    # PHPCS (WordPress standards, PHP 7.4+ compatibility)
composer cs:fix      # Auto-fix code style
composer analyze     # PHPCS + PHPStan + PHPMD
```

### Build

```bash
make build           # Creates zip + sha256
make deploy          # SCP to WP instance
make clean           # Remove artifacts
```

## Security

- Import key stored as WP option, displayed as password field, never logged in plaintext
- All admin actions verified with nonces + `manage_options` capability
- Production backend communication uses HTTPS. The API base URL accepts `http://` so the plugin
  can be pointed at a non-TLS API during local development — note that the import key travels in
  plaintext if you do, so use HTTPS anywhere real
- State files protected with `.htaccess` deny-all
- Presigned S3 URLs are short-lived and single-use
- Debug download redacts the import key

## License

MIT
