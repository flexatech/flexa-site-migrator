=== Flexa Site Migrator ===
Contributors: flexatech
Tags: migration, staging, clone, backup, duplicate
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 7.0
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Migrate WordPress from production to staging with no shell access. Creates a package (files + database + installer) that runs anywhere.

== Description ==

Flexa Site Migrator migrates a WordPress site from **production to staging**. It builds a package made of one or more `archive-*.zip` files (site files, split automatically), a `database.sql` dump, and a standalone `installer.php`. It works whether staging is on the same server or a different one, and **requires no shell/SSH access** — everything runs through the WordPress admin over regular HTTP.

**Key features**

* **Chunked build** — the database is exported in chunks (using `mysqldump` when available, otherwise pure PHP) and files are compressed in chunks to avoid timeouts.
* **Three ways to deploy to staging** — pull-by-link, wp-admin import, or a standalone installer for empty sites.
* **Serialize-safe search-replace** — URLs are updated with a recursive unserialize → replace → re-serialize algorithm, so serialized options/widgets never get corrupted.
* **Handles large sites** — the build is fully chunked and files are split into ~200MB archive parts; the database deploy runs in a single request (check the System check panel for your PHP limits before importing very large databases).
* **Token-protected transfers** — pull links carry an SHA-256 hashed token (only the hash is stored on the server), with optional password and IP allowlist restrictions.

**Deployment methods**

*Method A — Pull via link (simplest):* Install the plugin on both production and staging. Build the package on production, copy the link, paste it on staging under Tools → Flexa Site Migrator Import, and click Pull & Migrate. Staging downloads the files from production (byte-range supported) and runs extraction, DB import, and search-replace on its own.

*Method B — wp-admin import:* Copy the package folder to staging's `wp-content/flexasm-packages/`, then run the migration from Tools → Flexa Site Migrator Import.

*Method C — Standalone installer:* For an empty staging site with no WordPress. Upload `installer.php` and the package files to the site root, open `installer.php` in a browser, enter the database details, and start the migration.

== Installation ==

1. Upload the `flexa-site-migrator` folder to `/wp-content/plugins/` on the **production** site (or install it through Plugins → Add New → Upload Plugin).
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Tools → Flexa Site Migrator** to build a package.
4. To deploy via link or wp-admin import, install and activate the plugin on the **staging** site as well, then use **Tools → Flexa Site Migrator Import**.

== Frequently Asked Questions ==

= Does it require SSH or WP-CLI? =

No. The whole build and import process runs inside the WordPress admin over normal HTTP requests.

= Will I get logged out after importing on staging? =

Possibly. After the database is overwritten, the users table belongs to production, so you may need to log back in with a production account.

= Does it handle multi-gigabyte databases? =

The build side is fully chunked, so exporting is safe at any size. The import on staging runs in a single request through wp-admin; for very large databases, check the System check panel and raise `max_execution_time`/`memory_limit` in php.ini first, or use the standalone installer (Method C).

= Is the transfer secure? =

Pull links carry an SHA-256 hashed token — only the hash is stored on production, and the real token stays in the link. You can additionally protect a package with a password and restrict it to specific IP addresses. Always delete the package after the migration is complete.

= What happens to my staging site? =

Staging is **completely overwritten** (files and database). Only use it with a staging site you can safely throw away.

= Is it translation-ready? =

Yes. All admin-facing strings (PHP and JavaScript) are internationalized under the `flexa-site-migrator` text domain, and a `languages/flexa-site-migrator.pot` template is included.

== Screenshots ==

1. Build a migration package on the production site (Tools → Flexa Site Migrator).
2. Import or pull the package on staging (Tools → Flexa Site Migrator Import).

== Changelog ==

= 1.0.3 =
* Security: package storage under `uploads/flexasm-packages` now denies ALL direct web access (deny-all `.htaccess`); archives, `database.sql` and `manifest.json` are streamed through authenticated admin-ajax endpoints (capability + nonce) or the hashed-token pull endpoint instead of direct URLs.
* Security: `installer.php` is no longer written into the uploads directory — it is streamed on demand straight from the plugin's template, so no runnable PHP file ever lives in uploads.
* Security: removed the standalone `runner.php` chunked-import mechanism (a web-executable PHP file in uploads); the database deploy always runs through the authenticated wp-admin AJAX request.
* Change: all database work in the plugin now goes through `$wpdb` with `prepare()` — the direct `mysqli_*` calls were removed from the exporter, importer, and search-replace.
* Fix: the file archiver now excludes the package storage directory at its real (uploads-based) location, so packages no longer get zipped into themselves.

= 1.0.2 =
* Change: renamed the plugin to **Flexa Site Migrator** (slug `flexa-site-migrator`) for a distinctive, non-generic name.
* Change: prefixed all globals, constants, options, AJAX actions, script/style handles, and nonces with `flexasm_`/`FLEXASM_` under the `Flexa\SiteMigrator` namespace to avoid collisions.
* Security: hardened database export, import, and search-replace queries — table and column identifiers are now backtick-escaped, closing an identifier-interpolation gap.

= 1.0.1 =
* New: manual installer now runs a system requirements check (files present, database connection, PHP extensions) before starting a migration.
* New: one-click cleanup of the migration files (installer, archives, database dump, manifest) from the success screen after migrating.
* New: download an entire package as a single .zip, in addition to downloading each file separately.
* Fix: database import could fail on MySQL 5.7+/8.0 with "Invalid default value" on legacy zero-date columns (e.g. WooCommerce ActionScheduler).
* Fix: downloading installer.php could fail on servers that block direct access to PHP files under uploads; it is now served safely through the admin.
* Change: removed the "Remove plugin from the source site" button — uninstall the plugin on the source site manually.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.3 =
Locks down the package storage directory against direct web access, stops shipping runnable PHP files into uploads, and moves all database work to $wpdb->prepare().

= 1.0.2 =
Renames the plugin to Flexa Site Migrator, prefixes all identifiers to avoid collisions, and hardens database queries against identifier injection.

= 1.0.1 =
Adds a pre-migration system check, one-click cleanup, and single-zip package download; fixes database import on MySQL 5.7+/8.0 and installer.php downloads.

= 1.0.0 =
Initial release.
