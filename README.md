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
- PHP 8.0, 8.1, 8.2, 8.3, 8.4, or 8.5
- Composer (for development)
- No shell access required
- No external binaries required (pure PHP)

## Installation

1. Download or build the plugin zip
2. Upload to `wp-content/plugins/honest-hosting-site-migrator/`
3. Activate via WordPress admin
4. Navigate to **Tools > HH Site Migrator**

## Configuration

### Admin UI

1. **API Base URL** - Pre-filled with `https://api.honesthosting.io`. Override if needed.
2. **Site Import Key** - Provided by HonestHosting during onboarding.
3. **Chunk Size** - Default 2 MB. Configurable from 2 MB to 200 MB.

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

If a migration is interrupted (PHP timeout, page reload, network error), click "Resume Migration" to continue from the last checkpoint. All progress is persisted in local JSON state files.

### Debug

Click "Download Debug Data" to generate a JSON bundle containing session state, logs, and environment info. Email this to HonestHosting support for troubleshooting. The import key is redacted in the bundle.

## Architecture

```
src/
  Plugin.php                 # Singleton lifecycle, hooks, cron schedules
  Admin/
    AdminPage.php            # Tools menu page, WordPress native admin UI
    AjaxHandler.php          # AJAX endpoints (nonce + capability verified)
    Views/                   # PHP template partials
  Api/
    ApiEndpoints.php         # Central URL registry (all endpoints in one place)
    HonestHostingClient.php  # HTTP client for HH backend
    S3Uploader.php           # Presigned URL chunk uploader with retry
  Export/
    FileExporter.php         # wp-content scan, chunked file export
    DatabaseExporter.php     # PHP-native SQL export, streamed rows
    ChunkEncoder.php         # Gzip compression + metadata framing
  Migration/
    MigrationOrchestrator.php # Top-level flow controller
    SessionManager.php       # JSON state persistence, atomic writes, locks
    ManifestBuilder.php      # Migration manifest generation
    ResumeHandler.php        # Resume detection and continuation
  Preflight/
    PreflightRunner.php      # Orchestrates all checks
    PreflightResult.php      # Error/warning/info result DTO
    Checks/                  # Individual check implementations
  Schedule/
    CronScheduler.php        # WP-Cron incremental sync with jitter
  Log/
    MigrationLogger.php      # Structured event logging to DB
  Util/
    ChunkSizeValidator.php   # Human-readable size parsing (2MB-200MB)
```

## API Endpoints

All requests include `X-HH-Site-Import-Key` header. Endpoints are defined in `ApiEndpoints.php`:

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/v1/siteImport/site/filter` | List eligible destination sites |
| POST | `/v1/siteImport/site/{siteId}` | Create import session |
| POST | `/v1/siteImport/site/{siteId}/validate` | Validate capacity |
| GET | `/v1/siteImport/filter` | List import sessions |
| GET | `/v1/siteImport/{importId}` | Get import status |
| POST | `/v1/siteImport/{importId}/uploadUrl` | Get presigned S3 URL |
| GET | `/v1/siteImport/{importId}/ready` | Check destination readiness |

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

### Code Quality

```bash
composer cs:check    # PHPCS (WordPress standards)
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
- HTTPS enforced for all backend communication
- State files protected with `.htaccess` deny-all
- Presigned S3 URLs are short-lived and single-use
- Debug download redacts the import key

## License

MIT
