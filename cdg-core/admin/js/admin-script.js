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

    // ── Login logo media picker ──
    var logoUploadBtn = document.getElementById("cdg-login-logo-upload");
    if (logoUploadBtn && window.wp && wp.media) {
      var loginMediaFrame;

      logoUploadBtn.addEventListener("click", function (e) {
        e.preventDefault();

        if (loginMediaFrame) {
          loginMediaFrame.open();
          return;
        }

        loginMediaFrame = wp.media({
          title: "Select Login Logo",
          button: { text: "Use this image" },
          multiple: false,
          library: { type: "image" },
        });

        loginMediaFrame.on("select", function () {
          var attachment = loginMediaFrame
            .state()
            .get("selection")
            .first()
            .toJSON();

          document.getElementById("cdg-login-logo-id").value = attachment.id;

          var img = document.getElementById("cdg-login-logo-img");
          if (img) {
            img.src = attachment.url;
          }

          var preview = document.getElementById("cdg-login-logo-preview");
          if (preview) preview.style.display = "block";

          logoUploadBtn.textContent = "Change Logo";

          var removeBtn = document.getElementById("cdg-login-logo-remove");
          if (removeBtn) removeBtn.style.display = "inline-flex";
        });

        loginMediaFrame.open();
      });

      var logoRemoveBtn = document.getElementById("cdg-login-logo-remove");
      if (logoRemoveBtn) {
        logoRemoveBtn.addEventListener("click", function (e) {
          e.preventDefault();
          document.getElementById("cdg-login-logo-id").value = "";
          var preview = document.getElementById("cdg-login-logo-preview");
          if (preview) preview.style.display = "none";
          logoUploadBtn.textContent = "Select Logo";
          this.style.display = "none";
        });
      }
    }

    // ── Code Snippets repeater ──
    var snippetsList    = document.getElementById("cdg-snippets-list");
    var snippetTemplate = document.getElementById("cdg-snippet-template");
    var snippetAddBtn   = document.getElementById("cdg-snippet-add");
    var snippetsEmpty   = document.getElementById("cdg-snippets-empty");

    if (snippetsList && snippetTemplate && snippetAddBtn) {
      var snippetCounter = parseInt(snippetsList.dataset.count || "0", 10);

      function syncSnippetsEmpty() {
        if (!snippetsEmpty) return;
        snippetsEmpty.style.display = snippetsList.children.length === 0 ? "" : "none";
      }

      function initSnippetRow(row) {
        var typeSelect  = row.querySelector(".cdg-snippet-type");
        var locationRow = row.querySelector(".cdg-snippet-location-row");

        if (typeSelect && locationRow) {
          function syncLocation() {
            var t = typeSelect.value;
            locationRow.style.display = (t === "js" || t === "html") ? "" : "none";
          }
          typeSelect.addEventListener("change", syncLocation);
          syncLocation();
        }

        var removeBtn = row.querySelector(".cdg-snippet-remove");
        if (removeBtn) {
          removeBtn.addEventListener("click", function (e) {
            e.preventDefault();
            if (window.confirm("Remove this snippet?")) {
              row.remove();
              syncSnippetsEmpty();
            }
          });
        }
      }

      snippetsList.querySelectorAll(".cdg-snippet-item").forEach(initSnippetRow);

      snippetAddBtn.addEventListener("click", function (e) {
        e.preventDefault();
        var html = snippetTemplate.innerHTML.replace(/__INDEX__/g, String(snippetCounter));
        snippetCounter++;
        var tmp = document.createElement("div");
        tmp.innerHTML = html;
        var row = tmp.firstElementChild;
        snippetsList.appendChild(row);
        initSnippetRow(row);
        syncSnippetsEmpty();
        var firstInput = row.querySelector(".cdg-input");
        if (firstInput) firstInput.focus();
      });

      syncSnippetsEmpty();
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
