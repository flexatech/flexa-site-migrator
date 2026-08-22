(function ($) {
	'use strict';

	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;

	var pkg = null;
	var sdBuildPass = '';
	var sdBuildIps = '';

	// A build/import is ~200 sequential AJAX calls; one dropped connection must
	// not abort the whole run. Transport failures (HTTP error, parse error) are
	// retried with backoff — application errors (success:false) are not.
	function post(action, data, retries) {
		retries = (retries === undefined) ? 2 : retries;
		var dfd = $.Deferred();
		var attempt = 0;
		(function run() {
			$.post(FLEXASM.ajax, $.extend({ action: action, nonce: FLEXASM.nonce }, data || {}))
				.done(function (res) { dfd.resolve(res); })
				.fail(function (xhr) {
					if (attempt < retries) {
						attempt++;
						setTimeout(run, 1500 * attempt);
					} else {
						dfd.reject(xhr);
					}
				});
		})();
		return dfd.promise();
	}

	function connErr(xhr) {
		return sprintf(
			/* translators: %d: HTTP status code (0 = the request never reached the server). */
			__('Server connection error (HTTP %d).', 'flexa-site-migrator'),
			(xhr && xhr.status) || 0
		);
	}

	function setBar(step, pct) {
		$('.flexasm-step[data-step="' + step + '"] .flexasm-bar i').css('width', pct + '%');
	}
	function status(msg) { $('.flexasm-status').text(msg); }
	function fail(msg) {
		$('#flexasm-progress').hide();
		$('#flexasm-error').show().find('p').text(msg || __('An error occurred.', 'flexa-site-migrator'));
		$('#flexasm-build').prop('disabled', false);
		$('#flexasm-build-spin').hide();
	}

	// Loop a step (db/files) until it's done.
	function loopStep(action, step, onDone) {
		post(action, { package: pkg })
			.done(function (res) {
				if (!res.success) { return fail(res.data && res.data.message); }
				setBar(step, res.data.progress || 0);
				if (res.data.method === 'mysqldump') { status(__('Database: using mysqldump (fast)…', 'flexa-site-migrator')); }
				if (res.data.done) { setBar(step, 100); onDone(); }
				else { loopStep(action, step, onDone); }
			})
			.fail(function (xhr) { fail(connErr(xhr)); });
	}

	$('#flexasm-build').on('click', function () {
		sdBuildPass = $('#flexasm-build-pass').length ? $('#flexasm-build-pass').val() : '';
		sdBuildIps = $('#flexasm-build-ips').length ? $('#flexasm-build-ips').val() : '';
		$(this).prop('disabled', true);
		$('#flexasm-build-spin').show();
		$('#flexasm-error').hide();
		$('#flexasm-result').hide();
		$('#flexasm-progress').show();
		setBar('db', 0); setBar('files', 0);
		status(__('Scanning site…', 'flexa-site-migrator'));

		post('flexasm_build_init')
			.done(function (res) {
				if (!res.success) { return fail(res.data && res.data.message); }
				pkg = res.data.package;
				status(sprintf(
					/* translators: %d: number of database tables. */
					_n('Exporting database (%d table)…', 'Exporting database (%d tables)…', res.data.tables, 'flexa-site-migrator'),
					res.data.tables
				));

				loopStep('flexasm_build_database', 'db', function () {
					status(sprintf(
						/* translators: %d: number of files. */
						_n('Compressing files (%d file)…', 'Compressing files (%d files)…', res.data.file_total, 'flexa-site-migrator'),
						res.data.file_total
					));
					loopStep('flexasm_build_files', 'files', function () {
						status(__('Finalizing…', 'flexa-site-migrator'));
						post('flexasm_build_finalize', { package: pkg, password: sdBuildPass, allow_ips: sdBuildIps })
							.done(function (r) {
								if (!r.success) { return fail(r.data && r.data.message); }
								renderResult(r.data);
							})
							.fail(function () { fail(__('Error while finalizing.', 'flexa-site-migrator')); });
					});
				});
			})
			.fail(function (xhr) { fail(connErr(xhr)); });
	});

	function renderResult(data) {
		var files = data.files || {};
		$('#flexasm-progress').hide();
		if (data.pull_link) {
			$('#flexasm-pull-link').val(data.pull_link);
		}
		var $box = $('#flexasm-result .flexasm-pull-box');
		$box.find('.flexasm-pass-note').remove();
		if (data.has_pass) {
			$box.append($('<p class="flexasm-pass-note" style="color:#b26b00;margin-top:8px;"></p>')
				.text('🔒 ' + __('The package is password-protected. Send the password to the importer through a separate channel (don\'t paste it alongside the link).', 'flexa-site-migrator')));
		}
		if (files.package) {
			$('#flexasm-dl-package').attr('href', files.package).show();
		} else {
			$('#flexasm-dl-package').hide();
		}
		var $ul = $('#flexasm-result .flexasm-files').empty();
		if (files.installer) { $ul.append(link(files.installer, 'installer.php')); }
		(files.archives || []).forEach(function (a) {
			$ul.append(link(a.url, a.name));
		});
		if (files.database) { $ul.append(link(files.database, 'database.sql')); }
		if (files.manifest) { $ul.append(link(files.manifest, 'manifest.json')); }
		$('#flexasm-result').show();
		$('#flexasm-build').prop('disabled', false);
		$('#flexasm-build-spin').hide();
	}
	function link(url, label) {
		return '<li><a href="' + url + '" download="' + label + '">⬇ ' + label + '</a></li>';
	}

	$(document).on('click', '#flexasm-pull-copy', function () {
		var el = document.getElementById('flexasm-pull-link');
		el.select(); el.setSelectionRange(0, 99999);
		try { document.execCommand('copy'); $(this).text(__('Copied!', 'flexa-site-migrator')); } catch (e) {}
	});

	// Trigger every download link one after another (staggered so the browser
	// doesn't drop the queued downloads), so the user gets all parts in a single
	// click instead of clicking each file.
	function downloadSeq(links, $btn) {
		if (!links.length) { return; }
		var origText = $btn.text();
		$btn.prop('disabled', true);
		var i = 0;
		(function next() {
			if (i >= links.length) {
				$btn.prop('disabled', false).text(origText);
				return;
			}
			var src = links[i];
			i++;
			$btn.text(sprintf(
				/* translators: 1: current file number, 2: total number of files. */
				__('Downloading %1$d/%2$d…', 'flexa-site-migrator'), i, links.length
			));
			var a = document.createElement('a');
			a.href = src.href;
			a.download = src.getAttribute('download') || '';
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
			setTimeout(next, 800);
		})();
	}

	$(document).on('click', '#flexasm-dl-all', function () {
		downloadSeq($('#flexasm-result .flexasm-files a[download]').toArray(), $(this));
	});

	/* ---------- Existing packages (re-shown after reload) ---------- */

	$(document).on('click', '.flexasm-pkg-dlall', function () {
		downloadSeq($(this).closest('.flexasm-pkg').find('.flexasm-files a[download]').toArray(), $(this));
	});

	$(document).on('click', '.flexasm-pkg-link', function () {
		var $pkg = $(this).closest('.flexasm-pkg');
		var $btn = $(this).prop('disabled', true);
		post('flexasm_regen_link', { package: $pkg.data('id') })
			.done(function (r) {
				$btn.prop('disabled', false);
				if (r && r.success) {
					$pkg.find('.flexasm-pkg-linkrow').show().find('.flexasm-pkg-linkinput').val(r.data.pull_link);
					$pkg.find('.flexasm-pkg-linknote').show();
				} else {
					window.alert((r && r.data && r.data.message) || __('An error occurred.', 'flexa-site-migrator'));
				}
			})
			.fail(function (xhr) {
				$btn.prop('disabled', false);
				window.alert(connErr(xhr));
			});
	});

	$(document).on('click', '.flexasm-pkg-linkcopy', function () {
		var el = $(this).closest('.flexasm-pkg-linkrow').find('.flexasm-pkg-linkinput')[0];
		if (!el) { return; }
		el.select(); el.setSelectionRange(0, 99999);
		try { document.execCommand('copy'); $(this).text(__('Copied!', 'flexa-site-migrator')); } catch (e) {}
	});

	$(document).on('click', '.flexasm-pkg-delete', function () {
		if (!window.confirm(__('Delete this package permanently? Its files will no longer be available for download.', 'flexa-site-migrator'))) {
			return;
		}
		var $pkg = $(this).closest('.flexasm-pkg');
		var $btn = $(this).prop('disabled', true);
		post('flexasm_delete_pkg', { package: $pkg.data('id') })
			.done(function (r) {
				if (r && r.success) {
					$pkg.slideUp(200, function () { $pkg.remove(); });
				} else {
					$btn.prop('disabled', false);
					window.alert((r && r.data && r.data.message) || __('An error occurred.', 'flexa-site-migrator'));
				}
			})
			.fail(function (xhr) {
				$btn.prop('disabled', false);
				window.alert(connErr(xhr));
			});
	});

	/* ---------------- Import (staging) ---------------- */

	var impPkg = null;
	var impParts = [];
	var impFilesTotal = 0;
	var impInsecure = 0;
	var impPassword = '';
	var impLink = '';

	function impBar(step, pct) {
		$('#flexasm-imp-progress .flexasm-step[data-step="' + step + '"] .flexasm-bar i').css('width', pct + '%');
	}
	function impStatus(msg) { $('#flexasm-imp-progress .flexasm-status').text(msg); }
	function impFail(msg) {
		$('#flexasm-imp-progress').hide();
		$('#flexasm-imp-error').show().find('p').text(msg || __('An error occurred.', 'flexa-site-migrator'));
		$('#flexasm-run-import').prop('disabled', false);
		$('#flexasm-pull-start').prop('disabled', false);
		$('#flexasm-pull-spin').hide();
	}

	$('#flexasm-confirm').on('change', function () {
		var ok = $(this).is(':checked') && $('input[name=flexasm_pkg]:checked').length > 0;
		$('#flexasm-run-import').prop('disabled', !ok);
	});
	$(document).on('change', 'input[name=flexasm_pkg]', function () {
		var ok = $('#flexasm-confirm').is(':checked');
		$('#flexasm-run-import').prop('disabled', !ok);
	});

	function impStart() {
		$('#flexasm-imp-panel').show();
		$('#flexasm-imp-error').hide();
		$('#flexasm-imp-result').hide();
		$('#flexasm-imp-progress').show();
		impBar('download', 0); impBar('extract', 0); impBar('db', 0);
	}

	// --- Select a package already present on staging ---
	$('#flexasm-run-import').on('click', function () {
		impPkg = $('input[name=flexasm_pkg]:checked').val();
		if (!impPkg) { return; }
		$(this).prop('disabled', true);
		impStart();
		$('.flexasm-step[data-step="download"]').hide();
		impStatus(__('Preparing…', 'flexa-site-migrator'));
		startMigrate();
	});

	// --- Pull from production via link ---
	function pullReady() {
		var has = $.trim($('#flexasm-pull-input').val()) !== '';
		$('#flexasm-pull-test').prop('disabled', !has);
		$('#flexasm-pull-start').prop('disabled', !($('#flexasm-pull-confirm').is(':checked') && has));
	}
	$('#flexasm-pull-confirm').on('change', pullReady);
	$('#flexasm-pull-input').on('input', pullReady);

	$('#flexasm-pull-test').on('click', function () {
		var link = $.trim($('#flexasm-pull-input').val());
		if (!link) { return; }
		var $btn = $(this).prop('disabled', true).text(__('Testing…', 'flexa-site-migrator'));
		var $out = $('#flexasm-pull-testresult').show()
			.attr('class', 'flexasm-testresult flexasm-test-info').text(__('Contacting production…', 'flexa-site-migrator'));

		post('flexasm_pull_test', { link: link, password: $('#flexasm-pull-password').val() })
			.done(function (res) {
				$btn.prop('disabled', false).text(__('Test connection', 'flexa-site-migrator'));
				if (!res.success) {
					return $out.attr('class', 'flexasm-testresult flexasm-test-fail').text('✖ ' + (res.data && res.data.message));
				}
				var d = res.data;
				if (d.ok) {
					if (d.insecure_needed) { $('#flexasm-pull-insecure').prop('checked', true); }
					$out.attr('class', 'flexasm-testresult flexasm-test-ok')
						.text('✔ ' + sprintf(
							/* translators: 1: status message from production, 2: number of files, 3: human-readable size. */
							_n('%1$s — %2$d file, %3$s.', '%1$s — %2$d files, %3$s.', d.files, 'flexa-site-migrator'),
							d.message, d.files, d.size
						));
				} else {
					$out.attr('class', 'flexasm-testresult flexasm-test-fail').text('✖ ' + d.message);
				}
			})
			.fail(function () {
				$btn.prop('disabled', false).text(__('Test connection', 'flexa-site-migrator'));
				$out.attr('class', 'flexasm-testresult flexasm-test-fail').text('✖ ' + __('Connection error to staging.', 'flexa-site-migrator'));
			});
	});

	$('#flexasm-pull-start').on('click', function () {
		var link = $.trim($('#flexasm-pull-input').val());
		if (!link) { return; }
		impInsecure = $('#flexasm-pull-insecure').is(':checked') ? 1 : 0;
		impPassword = $('#flexasm-pull-password').val();
		impLink = link;
		$(this).prop('disabled', true);
		$('#flexasm-pull-spin').show();
		impStart();
		$('.flexasm-step[data-step="download"]').show();
		impStatus(__('Fetching package info from production…', 'flexa-site-migrator'));

		post('flexasm_pull_info', { link: link, insecure: impInsecure, password: impPassword })
			.done(function (res) {
				if (!res.success) { return impFail(res.data && res.data.message); }
				impPkg = res.data.id;
				var files = res.data.files || [];
				var totalBytes = files.reduce(function (s, f) { return s + (f.size || 0); }, 0);
				impStatus(sprintf(
					/* translators: 1: number of files, 2: human-readable total size. */
					_n('Downloading %1$d file (%2$s)…', 'Downloading %1$d files (%2$s)…', files.length, 'flexa-site-migrator'),
					files.length, bytes(totalBytes)
				));
				downloadAll(link, files, 0, 0, totalBytes, function () {
					impBar('download', 100);
					// Delete the package (including the DB dump) from the source server — best-effort.
					post('flexasm_pull_cleanup', { link: link, insecure: impInsecure, password: impPassword });
					startMigrate();
				});
			})
			.fail(function () { impFail(__('Connection error to staging.', 'flexa-site-migrator')); });
	});

	function downloadAll(link, files, idx, done, total, onDone) {
		if (idx >= files.length) { return onDone(); }
		var f = files[idx];
		downloadFile(link, f.name, 0, f.size, done, total, function () {
			downloadAll(link, files, idx + 1, done + (f.size || 0), total, onDone);
		});
	}
	function downloadFile(link, name, offset, size, base, total, onDone) {
		post('flexasm_pull_download', { link: link, name: name, offset: offset, total: size, insecure: impInsecure, password: impPassword })
			.done(function (res) {
				if (!res.success) { return impFail(res.data && res.data.message); }
				var g = base + res.data.offset;
				impBar('download', total ? Math.min(100, Math.round(g / total * 100)) : 100);
				if (res.data.done) { onDone(); }
				else { downloadFile(link, name, res.data.offset, size, base, total, onDone); }
			})
			.fail(function () { impFail(sprintf(
				/* translators: %s: file name. */
				__('Error while downloading %s.', 'flexa-site-migrator'), name)); });
	}
	function bytes(n) {
		if (!n) { return '0 B'; }
		var u = ['B', 'KB', 'MB', 'GB'], i = Math.floor(Math.log(n) / Math.log(1024));
		return (n / Math.pow(1024, i)).toFixed(1) + ' ' + u[i];
	}

	// Run migrate on the local package impPkg (either already present or just pulled).
	function startMigrate() {
		impStatus(__('Preparing…', 'flexa-site-migrator'));
		post('flexasm_import_prepare', { package: impPkg })
			.done(function (res) {
				if (!res.success) { return impFail(res.data && res.data.message); }
				impParts = res.data.parts || [];
				impFilesTotal = res.data.files_total || 0;
				impStatus(sprintf(
					/* translators: 1: number of files, 2: number of parts. */
					__('Extracting %1$d files (%2$d parts)…', 'flexa-site-migrator'),
					impFilesTotal, impParts.length
				));
				extractAllParts(0, 0, function () {
					runDbSingleRequest();
				});
			})
			.fail(function (xhr) { impFail(connErr(xhr)); });
	}

	function extractAllParts(pi, base, onDone) {
		if (pi >= impParts.length) { impBar('extract', 100); return onDone(); }
		var part = impParts[pi];
		extractPartLoop(part.name, 0, base, function () {
			extractAllParts(pi + 1, base + part.entries, onDone);
		});
	}

	function extractPartLoop(name, offset, base, onDone) {
		post('flexasm_import_extract', { package: impPkg, part: name, offset: offset })
			.done(function (res) {
				if (!res.success) { return impFail(res.data && res.data.message); }
				var globalDone = base + res.data.offset;
				impBar('extract', impFilesTotal ? Math.min(100, Math.round(globalDone / impFilesTotal * 100)) : 100);
				if (res.data.done) { onDone(); }
				else { extractPartLoop(name, res.data.offset, base, onDone); }
			})
			.fail(function (xhr) { impFail(__('Error while extracting.', 'flexa-site-migrator') + ' ' + connErr(xhr)); });
	}

	// Import + search-replace + finalize in one request (auth is verified at the
	// start; splitting it would lose the login session once users/options are overwritten).
	function runDbSingleRequest() {
		impStatus(__('Importing database + search-replace (single request, don\'t reload the page)…', 'flexa-site-migrator'));
		// No transport retry: the deploy overwrites the whole database in one shot —
		// if only the response was lost, re-running the import would be a second full overwrite.
		post('flexasm_import_deploy', { package: impPkg }, 0)
			.done(function (r) {
				if (!r.success) { return impFail(r.data && r.data.message); }
				showImportDone({ stmts: r.data.statements, changed: r.data.changed, note: r.data.prefix_note, new_url: r.data.new_url });
			})
			.fail(function (xhr) { impFail(__('Error while importing database (may time out on large sites).', 'flexa-site-migrator') + ' ' + connErr(xhr)); });
	}

	function showImportDone(r) {
		impBar('db', 100);
		$('#flexasm-imp-progress').hide();
		$('#flexasm-pull-spin').hide();
		var log = sprintf(
			/* translators: 1: number of SQL statements, 2: number of updated data cells. */
			__('Imported %1$d statements, updated %2$d data cells.', 'flexa-site-migrator'),
			(r.stmts || 0), (r.changed || 0)
		) + ' ' + (r.note || '');
		$('#flexasm-imp-result .flexasm-imp-log').text(log);
		$('#flexasm-imp-result .flexasm-imp-login').attr('href', (r.new_url || '') + '/wp-admin/');
		$('#flexasm-imp-result').show();
	}

	// Show/hide (eye) toggle on password fields — both the build protection
	// password (export page) and the pull password (import page).
	$(document).on('click', '.flexasm-pw-toggle', function () {
		var $btn = $(this);
		var $input = $btn.siblings('input');
		var show = $input.attr('type') === 'password';
		$input.attr('type', show ? 'text' : 'password');
		$btn.find('.dashicons')
			.toggleClass('dashicons-visibility', !show)
			.toggleClass('dashicons-hidden', show);
		$btn.attr('aria-label', show ? __('Hide password', 'flexa-site-migrator') : __('Show password', 'flexa-site-migrator'));
	});

})(jQuery);
