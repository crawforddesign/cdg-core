/**
 * CDG Core Admin — Vanilla JS interactions
 */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {

    // ── Generic: toggle cdg-disabled on a target element based on a checkbox ──
    function bindToggle(triggerName, targetId) {
      var trigger = document.querySelector('[name="' + triggerName + '"]');
      var target  = document.getElementById(targetId);
      if (!trigger || !target) return;

      function sync() {
        target.classList.toggle("cdg-disabled", !trigger.checked);
      }

      trigger.addEventListener("change", sync);
      sync();
    }

    // Upload type → restrict-to-admins rows
    bindToggle("enable_svg_uploads",    "cdg-svg-admin-row");
    bindToggle("enable_font_uploads",   "cdg-font-admin-row");
    bindToggle("enable_lottie_uploads", "cdg-lottie-admin-row");

    // Feature toggles → sub-settings groups
    bindToggle("enable_documentation", "cdg-doc-sub-settings");
    bindToggle("enable_cpt_widgets",   "cdg-cpt-sub-settings");
    bindToggle("enable_custom_login",  "cdg-login-sub-settings");

    // ── Post revisions: disable limit input unless "limited" is selected ──
    var revisionInputs = document.querySelectorAll('[name="post_revisions_mode"]');
    var limitInput     = document.querySelector('[name="post_revisions_limit"]');

    if (revisionInputs.length && limitInput) {
      function syncRevisions() {
        var checked = document.querySelector('[name="post_revisions_mode"]:checked');
        limitInput.disabled = !checked || checked.value !== "limited";
      }
      revisionInputs.forEach(function (r) { r.addEventListener("change", syncRevisions); });
      syncRevisions();
    }

    // ── Theme color mode → custom color row ──
    var colorModes     = document.querySelectorAll('[name="theme_color_mode"]');
    var customColorRow = document.getElementById("cdg-custom-color-row");

    if (colorModes.length && customColorRow) {
      function syncColorMode() {
        var checked = document.querySelector('[name="theme_color_mode"]:checked');
        customColorRow.classList.toggle("cdg-disabled", !checked || checked.value !== "custom");
      }
      colorModes.forEach(function (r) { r.addEventListener("change", syncColorMode); });
      syncColorMode();
    }

    // ── Color hex input → swatch preview ──
    var colorHexInput = document.querySelector('[name="theme_color_hex"]');
    var colorSwatch   = document.getElementById("cdg-color-swatch");

    if (colorHexInput && colorSwatch) {
      function syncSwatch() {
        var hex = colorHexInput.value.trim();
        if (/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/.test(hex)) {
          colorSwatch.style.backgroundColor = hex;
        }
      }
      colorHexInput.addEventListener("input", syncSwatch);
    }

  });
})();
