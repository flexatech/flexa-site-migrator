# Flexa Site Migrator

A plugin for migrating WordPress from **production → staging**. It creates a package consisting of one or more `archive-*.zip` files (files, split automatically) + `database.sql` + `installer.php`. It works whether staging is on the same server or a different one, and **requires no shell access**.

## Installation
1. Copy the `flexa-site-migrator/` folder into `wp-content/plugins/` on the **production** site.
2. Go to **Plugins → Activate** "Flexa Site Migrator".

## Creating a package (on production)
1. Go to **Tools → Flexa Site Migrator**.
2. Click **Create Package**. The plugin will:
   - Export the database in chunks (using `mysqldump` if available, otherwise pure PHP).
   - Compress all files in chunks (to avoid timeouts).
3. Download **all** files in the package: `installer.php`, the `archive-*.zip` files, `database.sql`, and `manifest.json`. Downloads are streamed through authenticated admin endpoints — the storage folder (`uploads/flexasm-packages/`) denies all direct web access.

## Installing on staging

There are **3 methods**; pick one:

### Method A — Pull via link (the simplest) ⭐⭐
No manual download/upload needed. Install the plugin on **both production and staging**:
1. On production: after building the package, copy the **link** shown under "Fastest method".
2. On staging: go to **Tools → Flexa Site Migrator Import**, paste the link into the "Pull from production via link" field, check the confirmation box, and click **Pull & Migrate**.
3. Staging automatically downloads the files from production (it supports byte-range downloads, so even very large files work fine), then runs extraction + DB import + search-replace on its own.

> The link contains an **SHA-256 hashed token**: production only stores the hash, while the real token lives in the link. Serving the files goes through PHP with token validation. You should still delete the package once you're done. Note: staging will make an HTTP call to the URL in the link on its own — only paste links you trust.

### Method B — Via wp-admin (package already present on staging)
1. Install and activate the "Flexa Site Migrator" plugin on **staging**.
2. Copy the entire package folder (`wp-content/uploads/flexasm-packages/<id>/`, which contains the `archive-*.zip` files, `database.sql`, and `manifest.json`) from production to **staging**, into the matching `wp-content/uploads/flexasm-packages/` location (via FTP/File Manager). If it's the same server, you can copy directly.
3. Go to **Tools → Flexa Site Migrator Import**, select the package, check the confirmation box, and click **Run Migrate**.
   - Extracting files: runs in chunks (with progress).
   - DB import + search-replace: runs in **a single request** (don't reload the page in the middle).
4. Done. You may **get logged out** (because the users table now belongs to production) → log back in with a production account.

> Why the DB import is bundled into one request: when overwriting the `users`/`options` tables, splitting it up would cause the next request to lose the login session and hit a 403 partway through. Bundling into one request (with authentication checked up front) avoids this problem entirely.

#### Very large databases
The database deploy runs in a single wp-admin request; the search-replace pass is keyset-paginated (500 rows per page) so memory stays flat. For multi-GB databases, check the **System check** panel and raise `max_execution_time`/`memory_limit` in php.ini first, or use the standalone installer (Method C), which runs outside WordPress.

> Prefix: the importer keeps production's prefix and automatically updates `$table_prefix` in staging's `wp-config.php` (if different). If `wp-config.php` isn't writable, the plugin will notify you to fix it manually.

### Method C — Standalone installer (when staging is empty / has no WordPress)
1. Create an **empty database** on staging.
2. Upload `installer.php`, **all** `archive-*.zip` files, `database.sql`, and `manifest.json` to the **root directory** of staging.
3. Open `https://your-staging.com/installer.php`, enter the DB details, and click **Start Migrate**.
4. **Delete immediately** the installer/archive/sql/manifest files once you're done.

## Multi-part archives (very large sites)
During the build, files are compressed into multiple parts — `archive-1.zip`, `archive-2.zip`, etc. — with each part closed once it exceeds ~200MB. The reason: `ZipArchive::close()` flushes all data at once, so packaging a multi-tens-of-GB site into a single file would exhaust RAM or time out. Splitting into parts keeps each zip close operation light and stable. All import paths (standalone installer, wp-admin import, pull-by-link) automatically extract all the parts.

## Why it's "accurate"
The most common source of errors when changing URLs is **serialized data** (widgets, settings, options). Replacing raw strings with `str_replace` corrupts the length prefixes inside serialized strings → broken data. The plugin uses a recursive **unserialize → replace → re-serialize** algorithm, so it preserves the structure intact.

## Notes / limitations
- Staging gets **completely overwritten** (files + DB). Only use it with a staging site you can throw away.
- The new `wp-config.php` is regenerated with random salts; any custom defines from the original config (cache, memory limit, etc.) must be re-added manually.
- The `wp-content/uploads/flexasm-packages/` folder holds sensitive data (the DB dump). Direct web access to it is blocked (deny-all `.htaccess`; files are only served through authenticated endpoints), but you should still delete the package after use.
- For very large sites (>a few GB), consider raising `memory_limit`/`max_execution_time` on staging.
```
flexa-site-migrator/
├── flexa-site-migrator.php        # bootstrap, menu, AJAX (build + import)
├── includes/
│   ├── class-flexasm-database.php     # export DB in chunks (+ mysqldump fast-path)
│   ├── class-flexasm-archive.php      # compress files in chunks
│   ├── class-flexasm-package.php      # orchestrates build + manifest
│   ├── class-flexasm-replace.php      # serialize-safe search-replace (importer)
│   ├── class-flexasm-importer.php     # IMPORT on the staging side (extract + import DB)
│   └── class-flexasm-pull.php         # pull-by-link: production serve + staging fetch
├── templates/
│   ├── admin-page.php            # package creation UI
│   ├── import-page.php           # import UI (staging)
│   └── installer.tpl             # standalone installer (for empty staging; streamed on download, never stored in uploads)
└── assets/  admin.js, admin.css
```
