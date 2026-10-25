# VirtueMart 4 cleanup utility

`cleanup-cli.php` is a standalone PHP CLI tool, and `cleanup-web.php` is a password-protected HTTPS page suitable for shared hosting. The web page runs the cleanup logic directly in PHP and does not require a terminal or `proc_open`. Both support one VirtueMart 4 site per config file. They can:

- Find product-image media rows that have no product association, and remove their local files/media records.
- Delete products created before a supplied date, including their product price rows, manufacturer associations, category/shopper-group associations, custom-field values, and product-media associations.
- Process image cleanup and old-product deletion in one run.
- Preserve products referenced by any VirtueMart order item. Joomla author/audit user IDs by themselves do not block deletion.
- Preserve a parent when an order-protected, newer, or otherwise retained child remains; a child is also preserved if its parent is retained.
- Record run summaries and JSON snapshots of affected products, associations, media rows, and image paths in SQLite.

Manufacturer records, custom-field definitions, order records, and user records are never deleted. Product custom-field values are deleted, not global field definitions.

## Requirements

- PHP 8.1 or newer.
- `pdo_mysql` for the Joomla MySQL/MariaDB database.
- `pdo_sqlite` for the audit database.
- A database account with SELECT and DELETE permissions on the relevant VirtueMart tables.
- A backup of the Joomla database and image directories before execution.
- For the web page: HTTPS, PHP sessions, and a strong password in the private config.

Confirm the PHP version and PDO extensions in your hosting control panel. Use only on a staging copy first.

## Configure for multiple sites

Copy `config.sample.php` to a private config for each site, such as `site-a.php` and `site-b.php`. Set each site's actual table prefix, DB connection, absolute media roots, and a distinct absolute SQLite audit path. For shared hosting, use the server's actual paths (often under `/home/<account>/...`), not the example paths. Keep configs and audit files outside `public_html`; do not put real credentials in the script or a publicly accessible folder.

`media_roots` must be absolute, existing directories. The cleanup utility will only resolve and delete local image paths inside those roots. URLs with schemes, traversal segments, missing files, or paths outside the allowed roots are not deleted. Add the Joomla root as an allowed root only when the site's stored media URLs require that path layout.

## Review mode (default)

All operations are dry-run unless `--execute` is provided:

```text
php cleanup-cli.php --config=site-a.php --mode=orphan-images
php cleanup-cli.php --config=site-a.php --mode=old-products --cutoff=2020-01-01 --limit=50
php cleanup-cli.php --config=site-a.php --mode=all --cutoff=2020-01-01
```

The run and snapshots are still saved to SQLite during a dry-run. Review them before executing.

## Execute

```text
php cleanup-cli.php --config=site-a.php --mode=all --cutoff=2020-01-01 --execute
```

Without `--yes`, execution requires typing `DELETE`. `--yes` is intended for reviewed automation. `--limit` bounds the number of age-qualified product candidates selected in a run; order- and parent/child-protected candidates count toward that limit. `--batch-size` controls the number of product deletions per database transaction. `--audit-db`, `--media-root`, and `--site-label` override the configured audit path, media roots, and audit label.

The age field is VirtueMart `created_on`; the cutoff day is exclusive (`created_on < YYYY-MM-DD 00:00:00`). Products with any order-item reference are preserved irrespective of order status. A qualifying parent is also preserved if any child product will remain. `old-products` cleans image files/media rows made orphaned by those product deletions; `all` additionally sweeps all other orphan product images. The tool deletes product-to-manufacturer associations, not manufacturer records, which may be shared by other products.

## Audit data

The SQLite database contains:

- `cleanup_runs`: mode, cutoff, dry-run flag, timestamps, site label, and summary.
- `cleanup_events`: per-product or per-media snapshots, action status, and timestamps.

Keep the audit database private because product/media snapshots and paths may contain store data. A successful database deletion is committed before orphan-image cleanup runs. If file removal fails, the media row is retained and an error is shown. File operations cannot be rolled back; keep filesystem backups.

## Safety notes

- The web page requires HTTPS and a password. Keep the private config and SQLite database outside the web document root.
- Run a dry-run first, check the SQLite audit log, and take verified backups.
- Test table naming and image URL layouts on a staging copy. The tool checks key VM4 columns and fails on missing requirements.
- Orphan image detection only considers media marked `file_is_product_image = 1`; a media row referenced by any discovered media-ID column in the database is retained.
- Deletion is permanent. No order, user, manufacturer-definition, or custom-field-definition rows are modified.

## Shared-host web page and SQLite viewer

1. In your hosting control panel's File Manager, create a private folder outside `public_html`, for example `/home/your-account/private/vm4-cleanup/`.
2. Copy `config.sample.php` into that private folder as `web-config.php`. Edit it with the host-provided database credentials and absolute paths. Set `web_password` to a unique password of at least 16 characters. Update every `web_sites` entry to an existing private site config; the first entry can point to `web-config.php` itself.
3. In `cleanup-web.php`, set `PRIVATE_CONFIG_PATH` near the top to the absolute path of `web-config.php`, for example `/home/your-account/private/vm4-cleanup/web-config.php`. This is a PHP setting in the file; no shell or environment-variable access is needed. Leave `allow_http_localhost` set to `false` for hosting.
4. Upload `cleanup-web.php` and `cleanup-cli.php` to a protected directory under `public_html`. Enable HTTPS for the site. Browse to `https://your-domain.example/cleanup-web.php`, sign in, choose a site and operation, then review the dry-run. Execution requires selecting **Execute** and typing `DELETE`.
5. Select **SQLite audit** to see the configured SQLite file path, recent run summaries, and saved event snapshots. The database is created on the first run, including a dry-run. Its directory must exist, be writable by PHP, and remain outside `public_html`.

The shared-host page includes `cleanup-cli.php` as a private code library and runs cleanup in the current PHP request. Direct web requests to `cleanup-cli.php` are rejected. Start with a small candidate limit because hosting providers may enforce request time limits. Do not use the page over plain HTTP or leave it publicly accessible without the password.

For local development only, set `allow_http_localhost` to `true` in the private config, point `PRIVATE_CONFIG_PATH` in `cleanup-web.php` to that config, and open `http://127.0.0.1:8000/cleanup-web.php`. HTTP is still rejected for every non-loopback client. Set it back to `false` before deploying the config to shared hosting.
# vm4_cleanup
