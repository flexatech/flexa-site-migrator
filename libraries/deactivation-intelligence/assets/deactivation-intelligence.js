/**
 * Deactivation Intelligence - client behaviour.
 *
 * RELIABILITY CONTRACT (non-negotiable): this script must NEVER prevent a
 * plugin from being deactivated. It is a progressive enhancement over the
 * native "Deactivate" link:
 *   - If this script fails to load or throws, the link works normally.
 *   - Feedback is sent best-effort via navigator.sendBeacon (or fetch keepalive)
 *     and we ALWAYS proceed to the original deactivate URL afterwards, whether
 *     the network call succeeds, fails, times out, or the user is offline.
 *
 * There is no "await the API then deactivate" path anywhere in this file.
 */
(function () {
  "use strict";

  // Collect every SDK instance localized onto the page.
  var configs = [];
  for (var key in window) {
    if (key.indexOf("DeactivationIntelligenceConfig_") === 0 && window[key]) {
      configs.push(window[key]);
    }
  }
  if (!configs.length) return;

  configs.forEach(setupInstance);

  function setupInstance(cfg) {
    try {
      var row = document.querySelector('tr[data-plugin="' + cssEscape(cfg.pluginFile) + '"]');
      var link = row
        ? row.querySelector("span.deactivate a, .deactivate a")
        : document.querySelector('a[id^="deactivate-"]');
      if (!link) return;

      // Several Flexa plugins can each bundle and load their own copy of this
      // script. Every copy iterates all configs on the page, so a single
      // Deactivate link would otherwise receive one click handler per loaded
      // copy and open that many stacked modals (needing one Cancel each). Bind
      // each link exactly once, whichever copy reaches it first.
      if (link.getAttribute("data-di-bound")) return;
      link.setAttribute("data-di-bound", "1");

      link.addEventListener("click", function (e) {
        // Enhancement only: intercept, show modal, then continue to this href.
        e.preventDefault();
        openModal(cfg, link.href);
      });
    } catch (err) {
      // Swallow: never break the plugins screen. Native link stays functional.
    }
  }

  /* ----------------------------------------------------------------------- */
  /* Modal                                                                   */
  /* ----------------------------------------------------------------------- */

  function openModal(cfg, deactivateUrl) {
    // Never stack modals: if one is already open, do nothing.
    if (document.querySelector(".di-overlay")) return;

    var i18n = cfg.i18n;
    var lastFocused = document.activeElement;

    var overlay = el("div", "di-overlay");
    var modal = el("div", "di-modal");
    modal.setAttribute("role", "dialog");
    modal.setAttribute("aria-modal", "true");
    modal.setAttribute("aria-labelledby", "di-title");

    modal.innerHTML =
      '<h2 id="di-title" class="di-title"></h2>' +
      '<p class="di-subtitle"></p>' +
      '<form class="di-form"><div class="di-reasons"></div>' +
      '<div class="di-followup" hidden></div>' +
      '<div class="di-recovery" hidden></div>' +
      '<div class="di-actions">' +
      '<button type="button" class="di-btn di-btn-ghost di-cancel"></button>' +
      '<button type="submit" class="di-btn di-btn-primary di-submit"></button>' +
      "</div></form>";

    modal.querySelector(".di-title").textContent = i18n.title;
    modal.querySelector(".di-subtitle").textContent = i18n.subtitle;
    modal.querySelector(".di-cancel").textContent = i18n.cancel;
    modal.querySelector(".di-submit").textContent = i18n.skip; // becomes "Deactivate" once a reason is chosen

    // Reason radios.
    var reasonsWrap = modal.querySelector(".di-reasons");
    cfg.reasons.forEach(function (r, idx) {
      var id = "di-reason-" + idx;
      var label = el("label", "di-reason");
      var input = document.createElement("input");
      input.type = "radio";
      input.name = "di-reason";
      input.value = r.id;
      input.id = id;
      input.setAttribute("data-followup", r.followup || "");
      var span = document.createElement("span");
      span.textContent = r.label;
      label.appendChild(input);
      label.appendChild(span);
      reasonsWrap.appendChild(label);
    });

    var followup = modal.querySelector(".di-followup");
    var recovery = modal.querySelector(".di-recovery");
    var submitBtn = modal.querySelector(".di-submit");
    var selectedReason = null;

    reasonsWrap.addEventListener("change", function (e) {
      if (e.target.name !== "di-reason") return;
      selectedReason = e.target.value;
      submitBtn.textContent = i18n.submit;
      renderFollowup(e.target.value, e.target.getAttribute("data-followup"));
      renderRecovery(e.target.value);
    });

    function renderFollowup(reason, kind) {
      followup.innerHTML = "";
      if (!kind) {
        followup.hidden = true;
        return;
      }
      followup.hidden = false;

      var qMap = {
        feature: i18n.featureQ,
        broken: i18n.brokenQ,
        conflict: i18n.conflictQ,
        difficult: i18n.difficultQ,
        alternative: i18n.alternativeQ,
        other: i18n.otherQ,
      };
      var lbl = el("label", "di-field-label");
      lbl.textContent = qMap[kind] || i18n.otherQ;
      lbl.setAttribute("for", "di-message");
      followup.appendChild(lbl);

      var ta = document.createElement("textarea");
      ta.className = "di-textarea";
      ta.id = "di-message";
      ta.rows = 2;
      ta.placeholder = i18n.optional;
      followup.appendChild(ta);

      // "Notify me" is opt-in and stores no email (backend records intent only).
      if (kind === "feature") {
        var notifyWrap = el("label", "di-notify");
        var chk = document.createElement("input");
        chk.type = "checkbox";
        chk.id = "di-notify";
        var t = document.createElement("span");
        t.textContent = i18n.notify;
        notifyWrap.appendChild(chk);
        notifyWrap.appendChild(t);
        followup.appendChild(notifyWrap);
      }
    }

    function renderRecovery(reason) {
      recovery.innerHTML = "";
      var actions = (cfg.recoveryActions && (cfg.recoveryActions[reason] || cfg.recoveryActions["*"])) || [];
      if (!actions.length) {
        recovery.hidden = true;
        return;
      }
      recovery.hidden = false;

      // Recovery was offered for this reason -> record it (best effort).
      send(cfg, cfg.endpoints.recoveryEvent, { reason: reason, stage: "offered" });

      actions.forEach(function (a) {
        var link = document.createElement("a");
        link.className = "di-recovery-action";
        // Only allow http(s) URLs; reject javascript:/data: etc. defensively.
        link.href = safeUrl(a.url);
        link.target = "_blank";
        link.rel = "noopener";
        link.textContent = a.label;
        link.addEventListener("click", function () {
          send(cfg, cfg.endpoints.recoveryEvent, {
            reason: reason,
            stage: "clicked",
            action_type: a.type,
            recovery_action_id: a.id,
          });
        });
        recovery.appendChild(link);
      });
    }

    /* ---- open ---- */
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    trapFocus(modal);
    modal.querySelector('input[name="di-reason"]').focus();

    // Record that the modal opened. Environment (wp/php/locale) rides here as
    // event_data - the only endpoint whose schema accepts it - so the backend
    // can attach it to this installation.
    send(cfg, cfg.endpoints.events, {
      event_type: "deactivation_modal_opened",
      event_data: cfg.environment && Object.keys(cfg.environment).length ? cfg.environment : undefined,
    });

    function close() {
      if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
      document.removeEventListener("keydown", onKey);
      if (lastFocused && lastFocused.focus) lastFocused.focus();
    }

    function onKey(e) {
      if (e.key === "Escape") {
        cancel();
      }
    }
    document.addEventListener("keydown", onKey);

    // Cancel = the user chose to stay. If recovery was offered, this is the
    // recovery signal (a real cancellation, not a mere click).
    function cancel() {
      if (selectedReason && !recovery.hidden) {
        send(cfg, cfg.endpoints.recoveryEvent, { reason: selectedReason, stage: "deactivation_cancelled" });
      }
      send(cfg, cfg.endpoints.events, { event_type: "deactivation_cancelled" });
      close();
    }

    modal.querySelector(".di-cancel").addEventListener("click", cancel);
    overlay.addEventListener("click", function (e) {
      if (e.target === overlay) cancel();
    });

    // Submit = proceed with deactivation. Send feedback best-effort, then GO.
    modal.querySelector(".di-form").addEventListener("submit", function (e) {
      e.preventDefault();
      proceed();
    });

    function proceed() {
      var messageEl = modal.querySelector("#di-message");
      var notifyEl = modal.querySelector("#di-notify");
      var message = messageEl ? messageEl.value.trim() : "";

      if (selectedReason) {
        send(cfg, cfg.endpoints.deactivations, {
          reason: selectedReason,
          message: message || undefined,
          alternative_plugin: selectedReason === "alternative" ? message || undefined : undefined,
          recovery_offered: !recovery.hidden,
        });

        // A missing-feature message becomes a first-class feature request.
        if (selectedReason === "missing_feature" && message) {
          send(cfg, cfg.endpoints.featureRequest, {
            title: message,
            description: notifyEl && notifyEl.checked ? "User asked to be notified" : undefined,
            notify: !!(notifyEl && notifyEl.checked),
          });
        }
      } else {
        // Skipped without choosing a reason - still record the deactivation.
        send(cfg, cfg.endpoints.deactivations, {});
      }

      // ALWAYS deactivate. Do not await anything above.
      close();
      window.location.href = deactivateUrl;
    }
  }

  /* ----------------------------------------------------------------------- */
  /* Networking - best effort, never blocking                                */
  /* ----------------------------------------------------------------------- */

  function send(cfg, url, extra) {
    try {
      var payload = Object.assign(
        {
          product: cfg.product,
          tier: cfg.tier,
          version: cfg.version,
          installation_id: cfg.installationId,
        },
        extra || {}
      );
      var body = JSON.stringify(payload);

      // Send as text/plain, NOT application/json. A JSON content-type is not
      // CORS-safelisted, so cross-origin it forces a preflight (OPTIONS) that
      // frequently does not finish before the page navigates to the deactivate
      // URL, silently dropping the request. text/plain is a "simple" request:
      // no preflight, and it survives navigation. The API reads the raw body as
      // JSON regardless of content-type.
      if (navigator.sendBeacon) {
        var blob = new Blob([body], { type: "text/plain;charset=UTF-8" });
        if (navigator.sendBeacon(url, blob)) return;
      }
      // Fallback: fetch with keepalive + a short timeout. No JSON content-type
      // header, for the same no-preflight reason. Errors are ignored.
      if (window.fetch) {
        var controller = typeof AbortController !== "undefined" ? new AbortController() : null;
        if (controller) setTimeout(function () { controller.abort(); }, 1500);
        fetch(url, {
          method: "POST",
          body: body,
          keepalive: true,
          signal: controller ? controller.signal : undefined,
        }).catch(function () {});
      }
    } catch (err) {
      // Never throw from telemetry.
    }
  }

  /* ----------------------------------------------------------------------- */
  /* Small DOM helpers                                                       */
  /* ----------------------------------------------------------------------- */

  function el(tag, className) {
    var node = document.createElement(tag);
    node.className = className;
    return node;
  }

  function cssEscape(value) {
    return String(value).replace(/["\\]/g, "\\$&");
  }

  function safeUrl(url) {
    if (!url) return "#";
    try {
      var parsed = new URL(url, window.location.origin);
      return parsed.protocol === "http:" || parsed.protocol === "https:" ? parsed.href : "#";
    } catch (err) {
      return "#";
    }
  }

  function trapFocus(modal) {
    modal.addEventListener("keydown", function (e) {
      if (e.key !== "Tab") return;
      var focusables = modal.querySelectorAll('a[href], button, textarea, input, [tabindex]:not([tabindex="-1"])');
      if (!focusables.length) return;
      var first = focusables[0];
      var last = focusables[focusables.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    });
  }
})();
