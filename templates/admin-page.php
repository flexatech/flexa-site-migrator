<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// This template is require'd inside Plugin::render_page(), so every variable
// here is method-local, not global. The prefix sniff can't see that when it
// scans the file in isolation, so silence its false positives file-wide.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
?>
<div class="wrap flexasm-wrap">
	<h1>Flexa Site Migrator</h1>
	<p class="description"><?php esc_html_e( 'Create a package to migrate this site to staging. Includes', 'flexa-site-migrator' ); ?> <code>files + database + installer</code>.</p>

	<?php \Flexa\SiteMigrator\Health::render(); ?>

	<div class="flexasm-card">
		<details class="flexasm-advanced">
			<summary><?php esc_html_e( 'Advanced options', 'flexa-site-migrator' ); ?> <span class="description"><?php esc_html_e( '(password, IP restriction, exclusions)', 'flexa-site-migrator' ); ?></span></summary>
		<p style="margin-top:0;">
			<label for="flexasm-build-pass"><?php esc_html_e( 'Protection password (optional):', 'flexa-site-migrator' ); ?></label><br>
			<span class="flexasm-pw-wrap">
				<input type="password" id="flexasm-build-pass" class="regular-text" placeholder="<?php esc_attr_e( 'Leave empty if not needed', 'flexa-site-migrator' ); ?>" autocomplete="new-password">
				<button type="button" class="flexasm-pw-toggle" aria-label="<?php esc_attr_e( 'Show password', 'flexa-site-migrator' ); ?>"><span class="dashicons dashicons-visibility" aria-hidden="true"></span></button>
			</span>
			<span class="description"><?php esc_html_e( "If set, the staging side must enter this password to pull the package (send it to them through a private channel, don't include it in the link).", 'flexa-site-migrator' ); ?></span>
		</p>
		<p>
			<label for="flexasm-build-ips"><?php esc_html_e( 'IP restriction (optional):', 'flexa-site-migrator' ); ?></label><br>
			<input type="text" id="flexasm-build-ips" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. 203.0.113.5, 203.0.113.6', 'flexa-site-migrator' ); ?>" autocomplete="off">
			<span class="description"><?php esc_html_e( "Only these IPs can pull the package. Leave empty = no restriction. Don't know the destination server's IP yet? Leave it empty, click Test connection on the destination side, and it will report the IP for you to add here.", 'flexa-site-migrator' ); ?></span>
		</p>
		<fieldset class="flexasm-excludes">
			<legend><?php esc_html_e( 'Exclude from package (optional):', 'flexa-site-migrator' ); ?></legend>
			<label><input type="checkbox" class="flexasm-exclude" value="media"> <?php esc_html_e( 'Exclude media library', 'flexa-site-migrator' ); ?> <code>wp-content/uploads</code></label>
			<label><input type="checkbox" class="flexasm-exclude" value="themes"> <?php esc_html_e( 'Exclude themes', 'flexa-site-migrator' ); ?> <code>wp-content/themes</code></label>
			<label><input type="checkbox" class="flexasm-exclude" value="mu-plugins"> <?php esc_html_e( 'Exclude must-use plugins', 'flexa-site-migrator' ); ?> <code>wp-content/mu-plugins</code></label>
			<label><input type="checkbox" class="flexasm-exclude" value="plugins"> <?php esc_html_e( 'Exclude plugins', 'flexa-site-migrator' ); ?> <code>wp-content/plugins</code></label>
			<label><input type="checkbox" class="flexasm-exclude" value="spam_comments"> <?php esc_html_e( 'Exclude spam comments', 'flexa-site-migrator' ); ?></label>
			<label><input type="checkbox" class="flexasm-exclude" value="revisions"> <?php esc_html_e( 'Exclude post revisions', 'flexa-site-migrator' ); ?></label>
			<p class="description"><?php esc_html_e( 'Ticked items are left out to make the package smaller. The first four skip whole folders in the file archive; the last two trim spam comments and post revisions (with their metadata) from the database dump.', 'flexa-site-migrator' ); ?></p>
		</fieldset>
		</details>

		<button id="flexasm-build" class="button button-primary button-hero"><?php esc_html_e( 'Create Package', 'flexa-site-migrator' ); ?></button>
		<span id="flexasm-build-spin" class="flexasm-spinner" style="display:none;" aria-hidden="true"></span>

		<div id="flexasm-progress" class="flexasm-progress" style="display:none;">
			<div class="flexasm-step" data-step="db">
				<span class="flexasm-label"><?php esc_html_e( 'Database', 'flexa-site-migrator' ); ?></span>
				<div class="flexasm-bar"><i style="width:0%"></i></div>
			</div>
			<div class="flexasm-step" data-step="files">
				<span class="flexasm-label"><?php esc_html_e( 'Files', 'flexa-site-migrator' ); ?></span>
				<div class="flexasm-bar"><i style="width:0%"></i></div>
			</div>
			<p class="flexasm-status"><?php esc_html_e( 'Initializing…', 'flexa-site-migrator' ); ?></p>
		</div>

		<div id="flexasm-result" class="flexasm-result" style="display:none;">
			<h2>✅ <?php esc_html_e( 'Package is ready', 'flexa-site-migrator' ); ?></h2>

			<div class="flexasm-pull-box">
				<strong>⚡ <?php esc_html_e( 'Fastest way — no upload/download needed:', 'flexa-site-migrator' ); ?></strong>
				<p><?php esc_html_e( 'Install this plugin on staging, go to', 'flexa-site-migrator' ); ?> <em>Tools → Flexa Site Migrator Import</em>, <?php esc_html_e( 'paste the link below and click run:', 'flexa-site-migrator' ); ?></p>
				<div class="flexasm-pull-row">
					<input type="text" id="flexasm-pull-link" readonly>
					<button type="button" id="flexasm-pull-copy" class="button"><?php esc_html_e( 'Copy', 'flexa-site-migrator' ); ?></button>
				</div>
				<p class="description"><?php esc_html_e( 'The link contains a token that grants access to the package. Only share it with people you trust; delete the package after the migration is done.', 'flexa-site-migrator' ); ?></p>
			</div>

			<details class="flexasm-manual">
				<summary><?php esc_html_e( "Or download the files manually (empty staging / can't connect)", 'flexa-site-migrator' ); ?></summary>
				<p>
					<a id="flexasm-dl-package" class="button button-primary" href="#" download>⬇ <?php esc_html_e( 'Download the whole package (.zip)', 'flexa-site-migrator' ); ?></a>
					<button type="button" id="flexasm-dl-all" class="button"><?php esc_html_e( 'Download files separately', 'flexa-site-migrator' ); ?></button>
				</p>
				<p class="description"><?php esc_html_e( 'The single .zip bundles every file; unzip it on staging, then run', 'flexa-site-migrator' ); ?> <code>installer.php</code>.</p>
				<ul class="flexasm-files"></ul>
			</details>
		</div>

		<div id="flexasm-error" class="notice notice-error" style="display:none;"><p></p></div>
	</div>

	<?php if ( ! empty( $packages ) ) : ?>
	<div class="flexasm-card flexasm-existing">
		<h2 style="margin-top:0;"><?php esc_html_e( 'Packages on this site', 'flexa-site-migrator' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Previously created packages are kept on disk, so you can download the files again or get a fresh pull link after reloading this page.', 'flexa-site-migrator' ); ?></p>

		<?php foreach ( $packages as $flexasm_pkg ) : ?>
			<div class="flexasm-pkg" data-id="<?php echo esc_attr( $flexasm_pkg['id'] ); ?>">
				<div class="flexasm-pkg-head">
					<code><?php echo esc_html( $flexasm_pkg['id'] ); ?></code>
					<span class="description"><?php echo esc_html( trim( $flexasm_pkg['site_url'] . ' · ' . $flexasm_pkg['created'] . ' · ' . $flexasm_pkg['size'], ' ·' ) ); ?></span>
				</div>

				<?php if ( 'ready' !== $flexasm_pkg['status'] ) : ?>
					<p class="description">
						<?php if ( 'cleaned' === $flexasm_pkg['status'] ) : ?>
							ℹ️ <?php esc_html_e( 'The migration files of this package were deleted from this server after a successful pull (automatic cleanup, so the database dump does not linger here). To migrate again, create a new package.', 'flexa-site-migrator' ); ?>
						<?php else : ?>
							⚠️ <?php esc_html_e( 'Leftovers from a build that never finished — safe to delete.', 'flexa-site-migrator' ); ?>
						<?php endif; ?>
					</p>
					<p class="flexasm-pkg-actions">
						<button type="button" class="button flexasm-pkg-delete"><?php esc_html_e( 'Delete', 'flexa-site-migrator' ); ?></button>
					</p>
				</div>
				<?php continue; ?>
				<?php endif; ?>

				<p class="flexasm-pkg-actions">
					<?php if ( ! empty( $flexasm_pkg['files']['package'] ) ) : ?>
						<a class="button button-primary flexasm-pkg-dlpackage" href="<?php echo esc_url( $flexasm_pkg['files']['package'] ); ?>" download>⬇ <?php esc_html_e( 'Download package (.zip)', 'flexa-site-migrator' ); ?></a>
					<?php endif; ?>
					<button type="button" class="button flexasm-pkg-dlall"><?php esc_html_e( 'Download files separately', 'flexa-site-migrator' ); ?></button>
					<?php if ( $flexasm_pkg['has_token'] ) : ?>
						<button type="button" class="button flexasm-pkg-link"><?php esc_html_e( 'Get pull link', 'flexa-site-migrator' ); ?></button>
					<?php endif; ?>
					<button type="button" class="button flexasm-pkg-delete"><?php esc_html_e( 'Delete', 'flexa-site-migrator' ); ?></button>
					<?php if ( $flexasm_pkg['has_pass'] ) : ?>
						<span class="description">🔒 <?php esc_html_e( 'password-protected', 'flexa-site-migrator' ); ?></span>
					<?php endif; ?>
				</p>

				<div class="flexasm-pkg-linkrow flexasm-pull-row" style="display:none;">
					<input type="text" class="flexasm-pkg-linkinput" readonly>
					<button type="button" class="button flexasm-pkg-linkcopy"><?php esc_html_e( 'Copy', 'flexa-site-migrator' ); ?></button>
				</div>
				<p class="description flexasm-pkg-linknote" style="display:none;"><?php esc_html_e( 'A brand-new link was generated (valid 48h). Any link shared earlier for this package no longer works.', 'flexa-site-migrator' ); ?></p>

				<ul class="flexasm-files flexasm-pkg-files">
					<?php
					$flexasm_f = $flexasm_pkg['files'];
					if ( ! empty( $flexasm_f['installer'] ) ) {
						printf( '<li><a href="%s" download="installer.php">⬇ installer.php</a></li>', esc_url( $flexasm_f['installer'] ) );
					}
					foreach ( $flexasm_f['archives'] as $flexasm_az ) {
						printf(
							'<li><a href="%1$s" download="%2$s">⬇ %2$s</a></li>',
							esc_url( $flexasm_az['url'] ),
							esc_html( $flexasm_az['name'] )
						);
					}
					if ( ! empty( $flexasm_f['database'] ) ) {
						printf( '<li><a href="%s" download>⬇ database.sql</a></li>', esc_url( $flexasm_f['database'] ) );
					}
					if ( ! empty( $flexasm_f['manifest'] ) ) {
						printf( '<li><a href="%s" download>⬇ manifest.json</a></li>', esc_url( $flexasm_f['manifest'] ) );
					}
					?>
				</ul>
			</div>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>
</div>
