Flexa Site Migrator — Migration Package
=======================================

This package contains everything needed to migrate your WordPress site
to a staging (or new) location:

  installer.php    Standalone installer — open it in a browser
  archive.zip      Site files (large sites are split into parts:
                   archive.zip, archive-2.zip, archive-3.zip, ...)
  database.sql     Database dump
  manifest.json    Package metadata read by the installer
  README.txt       This file

IMPORTANT: the destination site is COMPLETELY OVERWRITTEN (files and
database). Only migrate onto a site you can safely throw away.


How to migrate — standalone installer (empty host, no WordPress needed)
-----------------------------------------------------------------------

1. Upload ALL files from this package into the destination site's web
   root (e.g. public_html/) or the subfolder the site should live in.
   installer.php, every archive part, database.sql and manifest.json
   must sit together in the same folder.

2. Create an empty MySQL database and a database user with full
   privileges on it. (You can also reuse an existing database — tables
   with the same prefix will be dropped and replaced.)

3. Open the installer in your browser:

       https://your-staging-domain.com/installer.php

4. The installer first runs a system check: PHP version and extensions,
   archive parts present, database connection. Fix anything marked as
   failed before continuing.

5. Enter the database credentials, the table prefix, the new site URL
   and the directory path (the last two are pre-filled with a best
   guess), then start the migration. The installer extracts the
   archives, imports the database, updates wp-config.php and replaces
   the old URL everywhere — serialized data is handled safely.

6. When the migration finishes, click the one-click cleanup button. It
   deletes installer.php, the archive parts, database.sql,
   manifest.json and this README.txt from the server. Do not skip
   this step — these files contain your entire site and database.


Alternative — import through wp-admin
-------------------------------------

If the destination already runs WordPress, install and activate the
Flexa Site Migrator plugin there, copy this package's folder into
wp-content/uploads/flexasm-packages/ on the destination, then go to
Site Migrator -> Import in wp-admin and run the migration from there.

Tip: if both sites run the plugin, you don't need this download at all —
build the package on production, copy the pull link, and paste it on
staging under Site Migrator -> Import (Pull from production via link).


After the migration
-------------------

* Log in at /wp-admin/ using a username and password from the SOURCE
  (production) site — the users table was copied over.
* If pages 404, re-save the permalink structure once under
  Settings -> Permalinks.
* Delete any leftover migration files (step 6 above) if you skipped
  the cleanup.
