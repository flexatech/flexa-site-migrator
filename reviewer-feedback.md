Hi, I changed the plugin slug to "flexa-site-migrator".

You changed the plugin display name to "Flexa Site Migrator".

It's time to move forward with the plugin review "flexatech"!

Your plugin is not yet ready to be approved, you are receiving this email because the volunteers have manually checked it and have found some issues in the code / functionality of your plugin.

Please check this email thoroughly, address any issues listed, test your changes, and upload a corrected version of your code if all is well.

List of issues found


🔴️ Unsafe SQL calls

When making database calls, it's highly important to protect your code from SQL injection vulnerabilities. You need to update your code to use wpdb calls and prepare() with your queries to protect them.

Please review the following:

    https://developer.wordpress.org/reference/classes/wpdb/#protect-queries-against-sql-injection-attacks
    https://codex.wordpress.org/Data_Validation#Database
    https://make.wordpress.org/core/2012/12/12/php-warning-missing-argument-2-for-wpdb-prepare/
    https://ottopress.com/2013/better-know-a-vulnerability-sql-injection/

Example(s) from your plugin:

includes/class-flexasm-database.php:169 mysqli_real_escape_string($dbh, $value);
includes/class-flexasm-importer.php:263 mysqli_query($mysqli, 'SET FOREIGN_KEY_CHECKS=0');
includes/class-flexasm-replace.php:139 mysqli_real_escape_string($mysqli, $u['new'][$col]);
includes/class-flexasm-importer.php:271 mysqli_fetch_row($mode_res);
includes/class-flexasm-replace.php:105 mysqli_query($mysqli, 'SELECT * FROM ' . self::esc_id($table), MYSQLI_USE_RESULT);
includes/class-flexasm-importer.php:274 mysqli_query($mysqli, "SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
includes/class-flexasm-replace.php:133 mysqli_free_result($rres);
includes/class-flexasm-replace.php:146 mysqli_real_escape_string($mysqli, $u['row'][$pk]);

... out of a total of 23 incidences.


🔴️ Writing data to disallowed or incorrect locations, and/or asking users to edit plugin files

We cannot accept a plugin that forces (or tells) users to edit plugin files in order to function, or that writes data to locations where plugins are not supposed to write.

Plugins must never write to WordPress core directories (such as wp-admin, wp-includes), to other plugins' or themes' folders, to their own plugin folder and only under certain exceptions to the site root, to wp-content or to paths outside the WordPress installation. These locations are not guaranteed to exist, to be writable, or to persist, and writing to them can break sites, be silently wiped, or expose data.

Some specific reasons this is a problem:

    Plugin and core folders are deleted or replaced when upgraded, so anything stored there is lost.
    Many hosts run WordPress with read-only or restricted filesystems, or with paths that differ from the defaults. Hard-coded paths will fail there.
    Writing outside the expected locations breaks multisite and other one-off configurations.


It is preferable that you save your information to the database, via the Settings API, especially if it's privileged data.

If that's not possible because you're uploading media files, you should use the media uploader.

If you can't do either of those, you must save the data in the uploads directory, resolved at runtime with wp_upload_dir() (never a hard-coded path), creating a folder there with the slug of your plugin as name. If the data is not meant to be public, it must be protected from direct access.

Writing directly to the wp-content folder or in a folder there is acceptable only when the nature of your plugin genuinely requires it. Typical examples are caching plugins, which may need drop-ins or cache files outside the uploads directory, and backup or migration plugins, which may need storage that is independent of the per-site uploads directory in multisite.

Please refer to the following links:

    https://developer.wordpress.org/plugins/settings/
    https://developer.wordpress.org/reference/functions/media_handle_upload/
    https://developer.wordpress.org/reference/functions/wp_handle_upload/
    https://developer.wordpress.org/reference/functions/wp_upload_dir/


Example(s) from your plugin:

includes/class-flexasm-pull.php:338 fwrite($fh, $body);
# ✨ Pulled package files, including sensitive backup contents such as database.sql, are written into a publicly reachable uploads folder without access restrictions.
includes/class-flexasm-database.php:90 fwrite($fh, $this->header());
# ✨ The database dump is written as database.sql inside uploads, and backup/migration dumps should not be left directly publicly accessible there.




🔴️ Other possible issues

The AI detected certain cases not classified to specific sections of this report that can be related to security, compatibility, guidelines or other potential issues.

We know that the AI can be picky at times, so please review these cases carefully.

If there are issues, please resolve them. That way, we won't need to expend AI tokens checking the same thing again :)

From your plugin:

includes/class-flexasm-package.php:143 copy(FLEXASM_PATH . 'templates/installer.tpl', $this->dir . '/installer.php');
# ✨ Creates an executable installer.php file inside the uploads-based package directory, which can expose a public PHP entry point on servers that execute PHP there.




👉 Continue with the review process.

Read this email thoroughly.

Take the time to thoroughly review and understand the issues identified. Examine the provided examples, consult the relevant documentation, and conduct any additional research necessary. The goal of our review process is to help you clearly understand the reported issues so you can resolve them effectively and prevent similar problems in future updates to your plugin.
Note that there may be false positives - we are humans and make mistakes, we apologize if there is anything we have gotten wrong. If you have doubts you can ask us for clarification, when asking us please be clear, concise, direct and include an example.

📋 Complete your checklist.

✔️ I fixed all the issues in my plugin based on the feedback I received and my own review, as I know that the Plugins Team may not share all cases of the same issue. I am familiar with tools such as Plugin Check, PHPCS + WPCS, and similar utilities to help me identify problems in my code.
✔️ I tested my updated plugin on a clean WordPress installation with WP_DEBUG set to true.

    ⚠️ Do not skip this step. Testing is essential to make sure your fixes actually work and that you haven’t introduced new issues. 


✔️ I acknowledge that this review will be rejected if I overlook the issues or fail to test my code.
✔️ I went to "Add your plugin" and uploaded the updated version. I can continue updating the code there throughout the review process — the team will always check the latest version.
✔️ I replied to this email. I was concise and shared any clarifications or important context that the team needed to know.
I didn't list all the changes, as the team will review the entire plugin again and that is not necessary at all.

ℹ️ To help speed up the review process, we kindly ask that you carefully verify and address all reported issues before resubmitting your code.

While we try to make our reviews as exhaustive as possible we, like you, are humans and may have missed things. We appreciate your patience and understanding.
