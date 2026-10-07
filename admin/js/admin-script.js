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

    // Image optimization
    bindToggle("enable_webp", "cdg-webp-sub-settings");
    bindToggle("webp_resize", "cdg-webp-resize-row");

    // Feature toggles → sub-settings groups
    bindToggle("enable_documentation", "cdg-doc-sub-settings");
    bindToggle("enable_cpt_widgets",   "cdg-cpt-sub-settings");
    bindToggle("enable_custom_login",  "cdg-login-sub-settings");
    bindToggle("enable_custom_roles",  "cdg-roles-sub-settings");

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
        var phpNote     = row.querySelector(".cdg-snippet-php-note");

        if (typeSelect && locationRow) {
          function syncLocation() {
            var t = typeSelect.value;
            locationRow.style.display = (t === "css" || t === "js" || t === "html") ? "" : "none";
            if (phpNote) {
              phpNote.style.display = (t === "php") ? "" : "none";
            }
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

    // ── Sidebar tab: submenu expand/collapse ──
    document.querySelectorAll(".cdg-si-toggle").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var parent   = btn.dataset.parent;
        var expanded = btn.getAttribute("aria-expanded") === "true";

        btn.setAttribute("aria-expanded", expanded ? "false" : "true");
        btn.closest(".cdg-si-row").classList.toggle("cdg-si-parent-open", !expanded);

        document.querySelectorAll('.cdg-si-child[data-parent="' + parent + '"]').forEach(function (row) {
          row.classList.toggle("cdg-si-open", !expanded);
        });
      });
    });

    // ── Sidebar tab: menu items search, "customized only" filter, expand/collapse all ──
    var siList = document.querySelector(".cdg-si-list");
    if (siList) {
      var siSearch        = document.getElementById("cdg-si-search");
      var siCustomizedBtn = document.getElementById("cdg-si-customized-toggle");
      var siExpandAllBtn  = document.getElementById("cdg-si-expand-all");
      var siEmpty         = document.getElementById("cdg-si-empty");
      var siParentRows    = Array.prototype.slice.call(siList.querySelectorAll(".cdg-si-parent"));
      var siCustomizedOnly = false;

      function applySiFilter() {
        var q = (siSearch ? siSearch.value : "").trim().toLowerCase();
        var anyVisible = false;

        siParentRows.forEach(function (row) {
          var title         = row.dataset.title || "";
          var isCustomized  = row.dataset.customized === "true";
          var matchesText   = !q || title.indexOf(q) !== -1;
          var matchesFilter = !siCustomizedOnly || isCustomized;
          var visible       = matchesText && matchesFilter;

          row.style.display = visible ? "" : "none";
          if (visible) anyVisible = true;

          var slug = row.dataset.slug;
          siList.querySelectorAll('.cdg-si-child[data-parent="' + slug + '"]').forEach(function (child) {
            child.style.display = visible ? "" : "none";
          });
        });

        if (siEmpty) siEmpty.style.display = anyVisible ? "none" : "";
      }

      if (siSearch) siSearch.addEventListener("input", applySiFilter);

      // Rename inputs also count toward "customized" — re-sync on typing so
      // the dot and the Customized-only filter stay honest.
      siList.querySelectorAll('input[name^="sidebar_entry_names["]').forEach(function (inp) {
        var row = inp.closest(".cdg-si-parent");
        if (row) inp.addEventListener("input", function () { syncSiRowCustomized(row); applySiFilter(); });
      });
      siList.querySelectorAll('input[name^="sidebar_submenu_names["]').forEach(function (inp) {
        var child = inp.closest(".cdg-si-child");
        if (!child) return;
        var parentSlug = child.dataset.parent;
        var pRow = parentSlug ? document.querySelector('.cdg-si-parent[data-slug="' + parentSlug + '"]') : null;
        if (pRow) inp.addEventListener("input", function () { syncSiRowCustomized(pRow); applySiFilter(); });
      });

      if (siCustomizedBtn) {
        siCustomizedBtn.addEventListener("click", function () {
          siCustomizedOnly = !siCustomizedOnly;
          siCustomizedBtn.setAttribute("aria-pressed", siCustomizedOnly ? "true" : "false");
          applySiFilter();
        });
      }

      if (siExpandAllBtn) {
        siExpandAllBtn.addEventListener("click", function () {
          var anyCollapsed = siParentRows.some(function (row) {
            var toggle = row.querySelector(".cdg-si-toggle");
            return toggle && toggle.getAttribute("aria-expanded") !== "true";
          });

          siParentRows.forEach(function (row) {
            var toggle = row.querySelector(".cdg-si-toggle");
            if (!toggle) return;
            var slug = row.dataset.slug;

            toggle.setAttribute("aria-expanded", anyCollapsed ? "true" : "false");
            row.classList.toggle("cdg-si-parent-open", anyCollapsed);
            siList.querySelectorAll('.cdg-si-child[data-parent="' + slug + '"]').forEach(function (child) {
              child.classList.toggle("cdg-si-open", anyCollapsed);
            });
          });

          siExpandAllBtn.innerHTML = anyCollapsed
            ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><polyline points="18 15 12 9 6 15"/></svg>Collapse all'
            : '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><polyline points="6 9 12 15 18 9"/></svg>Expand all';
        });
      }
    }

    // ── Sidebar tab: Plugin Visibility search ──
    var pvList = document.querySelector(".cdg-pv-list");
    if (pvList) {
      var pvSearch = document.getElementById("cdg-pv-search");
      var pvEmpty  = document.getElementById("cdg-pv-empty");
      var pvRows   = Array.prototype.slice.call(pvList.querySelectorAll(".cdg-pv-row:not(.cdg-pv-row-head)"));

      function applyPvFilter() {
        var q = (pvSearch ? pvSearch.value : "").trim().toLowerCase();
        var anyVisible = false;

        pvRows.forEach(function (row) {
          var title   = row.dataset.title || "";
          var visible = !q || title.indexOf(q) !== -1;
          row.style.display = visible ? "" : "none";
          if (visible) anyVisible = true;
        });

        if (pvEmpty) pvEmpty.style.display = anyVisible ? "none" : "";
      }

      if (pvSearch) pvSearch.addEventListener("input", applyPvFilter);
    }

    // ── Sidebar tab: Hidden For Rules dropdown ──
    // A compact multi-select: clicking the trigger opens a panel of checkboxes;
    // toggling any checkbox rewrites the chip strip on the trigger to match.
    // Selected values are the checkbox values themselves — hidden inputs work
    // even if this JS never boots (the form still submits sensibly).
    var openRulesDropdown = null;
    function initRulesDropdown(dd) {
      if (!dd || dd.dataset.initialized === "1") return;
      dd.dataset.initialized = "1";

      var trigger = dd.querySelector(".cdg-rules-trigger");
      var panel   = dd.querySelector(".cdg-rules-panel");
      var chips   = dd.querySelector(".cdg-rules-chips");
      if (!trigger || !panel || !chips) return;

      function renderChips() {
        var checked = panel.querySelectorAll('input[type="checkbox"]:checked');
        chips.innerHTML = "";
        if (!checked.length) {
          var ph = document.createElement("span");
          ph.className   = "cdg-rules-placeholder";
          ph.textContent = "Select…";
          chips.appendChild(ph);
          return;
        }
        checked.forEach(function (cb) {
          var chip = document.createElement("span");
          chip.className   = "cdg-rules-chip";
          chip.textContent = cb.parentNode.querySelector("span:last-child").textContent;
          chips.appendChild(chip);
        });
      }

      // Position the panel as fixed relative to the trigger, so it can
      // escape ancestor `overflow: hidden` (the .cdg-card) and the vertical
      // clamp of .cdg-scroll-region-tall. Flip upward if there is not
      // enough room below the trigger.
      function positionPanel() {
        var rect      = trigger.getBoundingClientRect();
        var viewportH = window.innerHeight || document.documentElement.clientHeight;
        var spaceBelow = viewportH - rect.bottom - 8;
        var spaceAbove = rect.top - 8;
        var flipUp     = spaceBelow < 180 && spaceAbove > spaceBelow;
        var maxH       = Math.max(120, Math.min(240, flipUp ? spaceAbove : spaceBelow));

        panel.style.position  = "fixed";
        panel.style.left      = rect.left + "px";
        panel.style.width     = rect.width + "px";
        panel.style.right     = "auto";
        panel.style.maxHeight = maxH + "px";
        if (flipUp) {
          panel.style.top    = "auto";
          panel.style.bottom = (viewportH - rect.top + 4) + "px";
        } else {
          panel.style.top    = (rect.bottom + 4) + "px";
          panel.style.bottom = "auto";
        }
      }

      function resetPanelPosition() {
        panel.style.position  = "";
        panel.style.top       = "";
        panel.style.left      = "";
        panel.style.right     = "";
        panel.style.bottom    = "";
        panel.style.width     = "";
        panel.style.maxHeight = "";
      }

      trigger.addEventListener("click", function (e) {
        e.stopPropagation();
        if (openRulesDropdown && openRulesDropdown !== dd) {
          openRulesDropdown.classList.remove("cdg-rules-open");
          var otherPanel = openRulesDropdown.querySelector(".cdg-rules-panel");
          if (otherPanel) {
            otherPanel.setAttribute("hidden", "");
            if (openRulesDropdown._cdgReset) openRulesDropdown._cdgReset();
          }
        }
        var open = dd.classList.toggle("cdg-rules-open");
        panel.toggleAttribute("hidden", !open);
        if (open) {
          positionPanel();
          window.addEventListener("scroll", positionPanel, true);
          window.addEventListener("resize", positionPanel);
        } else {
          window.removeEventListener("scroll", positionPanel, true);
          window.removeEventListener("resize", positionPanel);
          resetPanelPosition();
        }
        openRulesDropdown = open ? dd : null;
      });

      // Expose a reset hook so another dropdown opening (or the
      // outside-click handler) can undo this one's inline positioning.
      dd._cdgReset = function () {
        window.removeEventListener("scroll", positionPanel, true);
        window.removeEventListener("resize", positionPanel);
        resetPanelPosition();
      };

      panel.addEventListener("change", function (e) {
        if (e.target && e.target.matches('input[type="checkbox"]')) {
          renderChips();
          // Update the "customized" state of the enclosing Sidebar Menu
          // Items row (parent or child), so the dot indicator and the
          // "Customized only" filter stay in sync with what's actually
          // selected — same behavior the pre-1.10.0 per-role checkboxes had.
          var parentRow = dd.closest(".cdg-si-parent");
          if (parentRow) syncSiRowCustomized(parentRow);
          var childRow = dd.closest(".cdg-si-child");
          if (childRow) {
            var parentSlug = childRow.dataset.parent;
            var pRow = parentSlug ? document.querySelector('.cdg-si-parent[data-slug="' + parentSlug + '"]') : null;
            if (pRow) syncSiRowCustomized(pRow);
          }
        }
      });
    }

    // Recompute a Sidebar Menu Items parent row's data-customized attribute
    // and dot indicator based on the current form state (rename input +
    // rules dropdown selections in the row itself and all its submenu
    // children). Called on every rules-dropdown change.
    function syncSiRowCustomized(row) {
      var slug   = row.dataset.slug;
      var custom = false;

      var rename = row.querySelector('input[name^="sidebar_entry_names["]');
      if (rename && rename.value.trim() !== "") custom = true;

      if (!custom) {
        var ownRuleCbs = row.querySelectorAll('.cdg-rules-panel input[type="checkbox"]:checked');
        if (ownRuleCbs.length) custom = true;
      }

      if (!custom && slug) {
        document.querySelectorAll('.cdg-si-child[data-parent="' + slug + '"]').forEach(function (child) {
          var sr = child.querySelector('input[name^="sidebar_submenu_names["]');
          if (sr && sr.value.trim() !== "") custom = true;
          var scb = child.querySelectorAll('.cdg-rules-panel input[type="checkbox"]:checked');
          if (scb.length) custom = true;
        });
      }

      row.dataset.customized = custom ? "true" : "false";
      var dot = row.querySelector(".cdg-si-customized-dot");
      if (custom && !dot) {
        dot = document.createElement("span");
        dot.className = "cdg-si-customized-dot";
        dot.setAttribute("aria-hidden", "true");
        var titleEl = row.querySelector(".cdg-si-title");
        if (titleEl) titleEl.after(dot);
      } else if (!custom && dot) {
        dot.remove();
      }
    }

    document.querySelectorAll(".cdg-rules-dropdown").forEach(initRulesDropdown);

    document.addEventListener("click", function (e) {
      if (openRulesDropdown && !openRulesDropdown.contains(e.target)) {
        openRulesDropdown.classList.remove("cdg-rules-open");
        var p = openRulesDropdown.querySelector(".cdg-rules-panel");
        if (p) p.setAttribute("hidden", "");
        if (openRulesDropdown._cdgReset) openRulesDropdown._cdgReset();
        openRulesDropdown = null;
      }
    });

    // ── Sidebar tab: Visibility Rules repeater ──
    var rulesList    = document.getElementById("cdg-rules-list");
    var ruleTemplate = document.getElementById("cdg-rule-template");
    var ruleAddBtn   = document.getElementById("cdg-rule-add");
    var rulesEmpty   = document.getElementById("cdg-rules-empty");

    if (rulesList && ruleTemplate && ruleAddBtn) {
      var ruleCounter = parseInt(rulesList.dataset.count || "0", 10);

      function syncRulesEmpty() {
        if (rulesEmpty) {
          rulesEmpty.style.display = rulesList.children.length === 0 ? "" : "none";
        }
      }

      function generateHex(len) {
        var hex = "";
        for (var i = 0; i < len; i++) {
          hex += Math.floor(Math.random() * 16).toString(16);
        }
        return hex;
      }

      function initRuleRow(row) {
        var toggle = row.querySelector(".cdg-rule-toggle");
        if (toggle) {
          toggle.addEventListener("click", function () {
            row.classList.toggle("cdg-rule-collapsed");
          });
        }
        var removeBtn = row.querySelector(".cdg-rule-remove");
        if (removeBtn) {
          removeBtn.addEventListener("click", function () {
            if (window.confirm("Remove this rule?")) {
              row.remove();
              syncRulesEmpty();
            }
          });
        }
        initUserPicker(row.querySelector(".cdg-user-picker"));
      }

      rulesList.querySelectorAll(".cdg-rule-item").forEach(initRuleRow);

      ruleAddBtn.addEventListener("click", function (e) {
        e.preventDefault();
        var html = ruleTemplate.innerHTML.replace(/__INDEX__/g, String(ruleCounter));
        ruleCounter++;

        var tmp = document.createElement("div");
        tmp.innerHTML = html;
        var row = tmp.firstElementChild;

        var idField = row.querySelector(".cdg-rule-id");
        if (idField) idField.value = generateHex(8);

        row.classList.remove("cdg-rule-collapsed");
        rulesList.appendChild(row);
        initRuleRow(row);
        syncRulesEmpty();

        var nameInput = row.querySelector(".cdg-rule-name");
        if (nameInput) nameInput.focus();
      });

      syncRulesEmpty();
    }

    // ── Sidebar tab: user picker (Visibility Rules · Assign to Users) ──
    // Uses admin-ajax with a nonce; server-side sanitizer double-checks
    // that IDs correspond to real users. See CDG_Core_Admin::ajax_user_search().
    function initUserPicker(picker) {
      if (!picker || picker.dataset.initialized === "1") return;
      picker.dataset.initialized = "1";

      var name        = picker.dataset.name || "";
      var nonce       = picker.dataset.nonce || "";
      var chipsWrap   = picker.querySelector(".cdg-user-chips");
      var search      = picker.querySelector(".cdg-user-search");
      var suggestions = picker.querySelector(".cdg-user-suggestions");
      if (!chipsWrap || !search || !suggestions) return;

      function selectedIds() {
        return Array.prototype.slice.call(chipsWrap.querySelectorAll(".cdg-rules-user-chip"))
          .map(function (c) { return c.dataset.id; });
      }

      function addChip(user) {
        if (selectedIds().indexOf(String(user.id)) !== -1) return;
        var chip = document.createElement("span");
        chip.className = "cdg-rules-user-chip";
        chip.dataset.id = String(user.id);
        chip.innerHTML =
          '<span></span>' +
          '<button type="button" class="cdg-rules-user-remove" title="Remove" aria-label="Remove">&times;</button>' +
          '<input type="hidden" value="' + user.id + '">';
        chip.querySelector("span").textContent = user.label;
        chip.querySelector("input").name = name + "[]";
        chip.querySelector(".cdg-rules-user-remove").addEventListener("click", function () {
          chip.remove();
        });
        chipsWrap.appendChild(chip);
      }

      // Bind existing chips' remove buttons.
      chipsWrap.querySelectorAll(".cdg-rules-user-remove").forEach(function (btn) {
        btn.addEventListener("click", function () {
          btn.closest(".cdg-rules-user-chip").remove();
        });
      });

      function closeSuggestions() {
        suggestions.innerHTML = "";
        suggestions.setAttribute("hidden", "");
      }

      function renderResults(rows) {
        suggestions.innerHTML = "";
        if (!rows.length) {
          var empty = document.createElement("div");
          empty.className   = "cdg-user-suggestion-empty";
          empty.textContent = "No matches.";
          suggestions.appendChild(empty);
        } else {
          rows.forEach(function (u) {
            var b = document.createElement("button");
            b.type        = "button";
            b.className   = "cdg-user-suggestion";
            b.textContent = u.label;
            b.addEventListener("click", function () {
              addChip(u);
              search.value = "";
              closeSuggestions();
              search.focus();
            });
            suggestions.appendChild(b);
          });
        }
        suggestions.removeAttribute("hidden");
      }

      var searchTimer = null;
      search.addEventListener("input", function () {
        clearTimeout(searchTimer);
        var q = search.value.trim();
        if (q.length < 2) {
          closeSuggestions();
          return;
        }
        searchTimer = setTimeout(function () {
          var url = (window.ajaxurl || "/wp-admin/admin-ajax.php") +
            "?action=cdg_core_user_search&nonce=" + encodeURIComponent(nonce) +
            "&q=" + encodeURIComponent(q);
          fetch(url, { credentials: "same-origin" })
            .then(function (r) { return r.json(); })
            .then(function (json) {
              if (json && json.success) renderResults(json.data || []);
            })
            .catch(function () {});
        }, 200);
      });

      document.addEventListener("click", function (e) {
        if (!picker.contains(e.target)) closeSuggestions();
      });
    }

    // Rules card's rules are initialized inside initRuleRow above, but any
    // pickers rendered elsewhere (e.g. a future card that reuses the helper)
    // still need to boot.
    document.querySelectorAll(".cdg-user-picker").forEach(initUserPicker);

    // ── Sidebar tab: dashicon picker ──
    var CDG_ICONS = [
      "admin-appearance","admin-comments","admin-generic","admin-home",
      "admin-links","admin-media","admin-multisite","admin-network",
      "admin-page","admin-plugins","admin-post","admin-settings",
      "admin-site","admin-tools","admin-users","analytics",
      "art","awards","building","businessperson",
      "calendar-alt","camera","cart","category",
      "chart-area","chart-bar","chart-line","chart-pie",
      "clipboard","cloud","code-standards","dashboard",
      "database","desktop","editor-code","email",
      "email-alt2","external","filter","flag",
      "format-gallery","format-video","groups","hammer",
      "heart","id","images-alt","info",
      "layout","list-view","location","lock",
      "megaphone","menu","migrate","performance",
      "phone","portfolio","products","randomize",
      "saved","search","share","shield",
      "slides","star-filled","store","superhero",
      "tag","testimonial","text","tickets-alt",
      "update","video-alt3","visibility","warning"
    ];

    var activePickerBtn = null;

    function buildIconPanel() {
      var panel = document.createElement("div");
      panel.className = "cdg-icon-panel";

      var search = document.createElement("input");
      search.type        = "text";
      search.className   = "cdg-icon-search";
      search.placeholder = "Search icons…";
      panel.appendChild(search);

      var grid = document.createElement("div");
      grid.className = "cdg-icon-grid";
      panel.appendChild(grid);

      function renderGrid(filter) {
        grid.innerHTML = "";
        CDG_ICONS.forEach(function (icon) {
          if (filter && icon.indexOf(filter) === -1) return;
          var btn = document.createElement("button");
          btn.type      = "button";
          btn.title     = icon;
          btn.dataset.icon = icon;
          btn.innerHTML = '<span class="dashicons dashicons-' + icon + '"></span>';
          grid.appendChild(btn);
        });
      }

      renderGrid("");

      search.addEventListener("input", function () {
        renderGrid(search.value.trim().toLowerCase());
      });

      grid.addEventListener("click", function (e) {
        var btn = e.target.closest("[data-icon]");
        if (!btn || !activePickerBtn) return;

        var icon      = btn.dataset.icon;
        var container = activePickerBtn.closest(".cdg-icon-field");
        if (container) {
          var iconSpan = activePickerBtn.querySelector(".dashicons");
          var hiddenInput = container.querySelector(".cdg-icon-value");
          if (iconSpan) {
            iconSpan.className = "dashicons dashicons-" + icon;
          }
          if (hiddenInput) {
            hiddenInput.value = icon;
          }
        }
        closeIconPanel();
      });

      return panel;
    }

    var iconPanel = null;

    function openIconPanel(triggerBtn) {
      closeIconPanel();
      activePickerBtn = triggerBtn;
      iconPanel = buildIconPanel();
      document.body.appendChild(iconPanel);

      // Highlight current icon.
      var currentIcon = triggerBtn.querySelector(".dashicons");
      if (currentIcon) {
        var curClass = currentIcon.className.replace("dashicons dashicons-", "").trim();
        iconPanel.querySelectorAll("[data-icon]").forEach(function (b) {
          b.classList.toggle("cdg-icon-active", b.dataset.icon === curClass);
        });
      }

      // Position below the button.
      var rect = triggerBtn.getBoundingClientRect();
      iconPanel.style.position = "fixed";
      iconPanel.style.top      = (rect.bottom + window.scrollY + 4) + "px";
      iconPanel.style.left     = rect.left + "px";

      setTimeout(function () {
        iconPanel.querySelector(".cdg-icon-search").focus();
      }, 10);
    }

    function closeIconPanel() {
      if (iconPanel && iconPanel.parentNode) {
        iconPanel.parentNode.removeChild(iconPanel);
      }
      iconPanel = null;
      activePickerBtn = null;
    }

    document.addEventListener("click", function (e) {
      if (iconPanel && !iconPanel.contains(e.target) && e.target !== activePickerBtn && !activePickerBtn.contains(e.target)) {
        closeIconPanel();
      }
    });

    function bindIconPickerBtn(btn) {
      btn.addEventListener("click", function (e) {
        e.stopPropagation();
        if (iconPanel && activePickerBtn === btn) {
          closeIconPanel();
        } else {
          openIconPanel(btn);
        }
      });
    }

    document.querySelectorAll(".cdg-icon-picker-btn").forEach(bindIconPickerBtn);

    // ── Sidebar tab: custom link repeater ──
    var linksList    = document.getElementById("cdg-links-list");
    var linkTemplate = document.getElementById("cdg-link-template");
    var linkAddBtn   = document.getElementById("cdg-link-add");
    var linksEmpty   = document.getElementById("cdg-links-empty");

    if (linksList && linkTemplate && linkAddBtn) {
      var linkCounter = parseInt(linksList.dataset.count || "0", 10);

      function syncLinksEmpty() {
        if (!linksEmpty) return;
        linksEmpty.style.display = linksList.children.length === 0 ? "" : "none";
      }

      function generateLinkId() {
        var hex = "";
        for (var i = 0; i < 8; i++) {
          hex += Math.floor(Math.random() * 16).toString(16);
        }
        return hex;
      }

      function initLinkRow(row) {
        // Bind icon picker.
        var pickerBtn = row.querySelector(".cdg-icon-picker-btn");
        if (pickerBtn) bindIconPickerBtn(pickerBtn);

        // Bind collapse/expand toggle.
        var cliToggle = row.querySelector(".cdg-cli-toggle");
        if (cliToggle) {
          cliToggle.addEventListener("click", function () {
            row.classList.toggle("cdg-cli-collapsed");
          });
        }

        // Bind remove button.
        var removeBtn = row.querySelector(".cdg-custom-link-remove");
        if (removeBtn) {
          removeBtn.addEventListener("click", function () {
            if (window.confirm("Remove this link?")) {
              row.remove();
              syncLinksEmpty();
            }
          });
        }

        // Rules dropdown inside the link row (Hidden For Rules).
        row.querySelectorAll(".cdg-rules-dropdown").forEach(initRulesDropdown);
      }

      // Init existing rows (server-rendered).
      linksList.querySelectorAll(".cdg-custom-link-item").forEach(function (row) {
        initLinkRow(row);
      });

      linkAddBtn.addEventListener("click", function (e) {
        e.preventDefault();
        var html = linkTemplate.innerHTML
          .replace(/__INDEX__/g, String(linkCounter));
        linkCounter++;

        var tmp = document.createElement("div");
        tmp.innerHTML = html;
        var row = tmp.firstElementChild;

        // Inject a fresh random id before init.
        var idField = row.querySelector('[name$="[id]"]');
        if (idField) idField.value = generateLinkId();

        linksList.appendChild(row);
        initLinkRow(row);
        syncLinksEmpty();

        var firstInput = row.querySelector(".cdg-input");
        if (firstInput) firstInput.focus();
      });

      syncLinksEmpty();
    }

    // ── Image optimization: bulk tools (convert / backup / replace / restore) ──
    (function () {
      var root = document.getElementById("cdg-webp-tools");
      if (!root) return;

      var ajaxUrl = root.getAttribute("data-ajax");
      var nonce = root.getAttribute("data-nonce");
      var busy = false;
      var info = null; // last payload from cdg_webp_status

      var $ = function (sel) { return root.querySelector(sel); };
      var $$ = function (sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); };
      var btn = function (act) { return $('[data-act="' + act + '"]'); };

      function post(action) {
        var fd = new FormData();
        fd.append("action", "cdg_webp_" + action);
        fd.append("nonce", nonce);
        return fetch(ajaxUrl, { method: "POST", credentials: "same-origin", body: fd })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (!j || !j.success) {
              throw new Error((j && j.data && j.data.message) || "The request failed.");
            }
            return j.data;
          }, function () {
            throw new Error("Unexpected response from the server. Check the PHP error log.");
          });
      }

      function msg(key, text, kind, onlyIfEmpty) {
        var el = $('[data-msg="' + key + '"]');
        if (!el) return;
        // Refreshing page state must never wipe a job result or error that
        // the user hasn't read yet.
        if (onlyIfEmpty && el.textContent) return;
        el.textContent = text || "";
        el.className = "cdg-webp-msg" + (kind ? " is-" + kind : "");
      }

      function bar(key, cur, total) {
        var wrap = $('[data-progress="' + key + '"]');
        if (!wrap) return;
        wrap.hidden = cur === null;
        if (cur === null) return;
        var pct = total > 0 ? Math.min(100, Math.round((cur / total) * 100)) : 0;
        wrap.firstElementChild.style.width = pct + "%";
      }

      function when(ts) { return ts ? new Date(ts * 1000).toLocaleString() : ""; }
      function mb(bytes) {
        if (bytes < 1048576) return Math.max(0, Math.round(bytes / 1024)) + " KB";
        return (bytes / 1048576).toFixed(bytes > 10485760 ? 0 : 1) + " MB";
      }
      function plural(n, word, many) { return n + " " + (n === 1 ? word : (many || word + "s")); }

      function setBusy(on, running) {
        busy = on;
        $$("[data-act]").forEach(function (b) {
          if (b.getAttribute("data-act") === "cancel") return;
          b.disabled = on;
        });
        btn("cancel").hidden = !(on && running === "bulk");
        if (!on) syncButtons();
      }

      var MODE_LABEL = {
        convert: "Convert existing images",
        compress: "Compress existing images",
        both: "Convert + compress existing images"
      };

      function syncButtons() {
        if (!info) return;
        var st = info.status || {};
        var bulkRunning = st.phase === "converting" && !st.done;
        var replRunning = st.phase === "replacing" && !st.done;

        btn("bulk").textContent = bulkRunning ? "Resume" : (MODE_LABEL[info.mode] || MODE_LABEL.convert);
        btn("replace").textContent = replRunning ? "Resume replace" : "Replace originals";

        // Replace only makes sense when WebP files exist (convert / both).
        $("#cdg-webp-replace-block").hidden = info.mode === "compress";
        $("#cdg-webp-unsupported").hidden = !(info.mode !== "compress" && !info.converter);

        btn("bulk").disabled = !info.enabled || busy;
        btn("backup").disabled = !info.enabled || busy;
        btn("replace").disabled = busy || !info.enabled || (!info.fresh_backup && !replRunning);
        btn("restore").disabled = busy || !info.backups.length;

        ["convert", "replace", "cleanup"].forEach(function (k) {
          var pb = btn("preview-" + k);
          if (pb) pb.disabled = busy || !info.enabled;
        });

        var cleaning = st.phase === "cleaning" && !st.done;
        btn("cleanup").textContent = cleaning ? "Resume cleanup" : "Delete leftover originals";
        btn("cleanup").disabled = busy || !info.enabled ||
          (!cleaning && (!info.fresh_backup || !info.leftover_originals));
      }

      function render() {
        var st = info.status || {};
        var parts = [];
        if (!info.enabled) {
          parts.push("Turn on Optimize Images on Upload and save to use these tools.");
        } else {
          parts.push(plural(info.candidates, "JPG/PNG image") + " in the Media Library" +
            (info.webp_attachments ? " (" + info.webp_attachments + " already WebP)" : "") + ".");
          if (info.last_run && info.last_run.finished_at) {
            parts.push("Last run " + when(info.last_run.finished_at) + ": " +
              info.last_run.converted + " done, " + info.last_run.skipped + " skipped, " +
              info.last_run.failed + " failed.");
          }
        }
        $("#cdg-webp-summary").textContent = parts.join(" ");

        var latest = info.backups[0];
        if (latest) {
          msg("backup", "Latest backup: " + when(latest.created_at) + " — " +
            plural(latest.files, "file") + ", " + mb(latest.bytes) + ". " +
            (info.fresh_backup ? "Ready to use." : "Out of date (over 24 hours old, or images were added since) — create a new one before replacing.") +
            (latest.expires_at ? " Kept until " + when(latest.expires_at) + "." : ""),
            info.fresh_backup ? "ok" : "");
        } else {
          msg("backup", "No backup yet.", "");
        }

        var lr = info.last_replace;
        if (lr && !(st.phase === "replacing" && !st.done)) {
          if (lr.restored_at) {
            msg("replace", "Restored " + when(lr.restored_at) + ": " + plural(lr.files_restored || 0, "file") +
              " and " + plural(lr.attachments_restored || 0, "image") + " put back.", "", true);
          } else if (lr.finished_at) {
            msg("replace", "Last replace " + when(lr.finished_at) + ": " + plural(lr.posts_modified || 0, "post") +
              " updated, " + plural(lr.attachments_switched || 0, "image") + " switched, " +
              plural(lr.files_deleted || 0, "file") + " deleted" +
              (lr.attachments_skipped ? ", " + lr.attachments_skipped + " skipped" : "") + ".", "", true);
          }
        }
        msg("cleanup", info.leftover_originals
          ? plural(info.leftover_originals, "image") + " still " + (info.leftover_originals === 1 ? "has" : "have") + " a leftover original."
          : "No leftover originals found.", "", true);
        if (st.phase === "cleaning" && !st.done) {
          msg("cleanup", "A cleanup was interrupted. Click Resume cleanup to finish it.", "error", true);
        }
        if (st.phase === "converting" && !st.done) {
          msg("bulk", "A job was interrupted at " + (st.cursor || 0) + " of " + (st.total || 0) + ". Click Resume to continue.", "", true);
        }
        if (st.phase === "replacing" && !st.done) {
          msg("replace", "A replace job was interrupted. Click Resume replace to finish it.", "error", true);
        }
        syncButtons();
      }

      function refresh() {
        return post("status").then(function (d) { info = d; render(); }, function (e) {
          $("#cdg-webp-summary").textContent = e.message;
        });
      }

      function bulkLine(st) {
        return "Done: " + (st.converted || 0) + ", skipped: " + (st.skipped || 0) + ", failed: " + (st.failed || 0);
      }

      function runBulk(resume) {
        setBusy(true, "bulk");
        msg("bulk", "", "");
        bar("bulk", 0, 1);
        var step = function (st) {
          bar("bulk", st.cursor || 0, st.total || 0);
          msg("bulk", "Working… " + (st.cursor || 0) + " of " + (st.total || 0) + ". " + bulkLine(st), "");
          if (st.error) throw new Error(st.error);
          return st;
        };
        return post(resume ? "tick_bulk" : "start_bulk").then(step).then(function loop(st) {
          if (st.done) return st;
          return post("tick_bulk").then(step).then(loop);
        }).then(function (st) {
          bar("bulk", st.total || 1, st.total || 1);
          msg("bulk", (st.phase === "cancelled" ? "Cancelled. " : "Finished. ") + bulkLine(st) + ".",
            st.failed ? "error" : "ok");
        }).catch(function (e) {
          msg("bulk", e.message, "error");
        }).then(function () { setBusy(false); return refresh(); });
      }

      function replaceLine(st) {
        if (st.step === "meta") {
          return "Updating settings and custom fields (" + (st.meta_modified || 0) + " changed)";
        }
        if (st.step === "attachments") {
          return "Switching images: " + (st.cursor || 0) + " of " + (st.total || 0) + " (" + (st.files_deleted || 0) + " files deleted)";
        }
        return "Updating content: " + (st.cursor || 0) + " of " + (st.total || 0) + " posts (" + (st.posts_modified || 0) + " changed)";
      }

      function runReplace(resume) {
        setBusy(true, "replace");
        msg("replace", "", "");
        bar("replace", 0, 1);
        var step = function (st) {
          bar("replace", st.cursor || 0, st.total || 0);
          msg("replace", "Working… " + replaceLine(st), "");
          if (st.error) throw new Error(st.error);
          return st;
        };
        return post(resume ? "tick_replace" : "start_replace").then(step).then(function loop(st) {
          if (st.done) return st;
          return post("tick_replace").then(step).then(loop);
        }).then(function (st) {
          bar("replace", 1, 1);
          msg("replace", "Finished. " + plural(st.posts_modified || 0, "page or layout", "pages or layouts") + " updated, " +
            plural(st.meta_modified || 0, "setting or custom field", "settings or custom fields") + " changed, " +
            plural(st.attachments_switched || 0, "image") + " switched, " +
            plural(st.files_deleted || 0, "file") + " deleted" +
            (st.attachments_skipped ? ", " + st.attachments_skipped + " skipped (no WebP for every size)" : "") +
            ". Purge your page cache (SpinupWP or any caching plugin) so visitors don\u2019t get old pages.", "ok");
        }).catch(function (e) {
          msg("replace", e.message, "error");
        }).then(function () { setBusy(false); return refresh(); });
      }

      // ── Dry run: same scan as the real job, nothing is changed ──
      function describePreview(kind, d) {
        var a = d.acc || {};
        var n = function (k) { return a[k] || 0; };
        if (kind === "convert") {
          var parts = [];
          var converts = d.mode !== "compress";
          var compresses = d.mode !== "convert";
          var text = "Dry run: found " + plural(n("images"), "image") + " (" + plural(n("files"), "file") +
            " including resized copies, " + mb(n("bytes")) + ").";
          if (converts) parts.push(plural(n("to_convert"), "image") + " need" + (n("to_convert") === 1 ? "s" : "") + " a WebP copy (" + plural(n("webp_files"), "file") + " to create)");
          if (compresses) parts.push(plural(n("to_compress"), "image") + " would be compressed");
          parts.push(n("up_to_date") + " already up to date");
          if (n("missing")) parts.push(plural(n("missing"), "image") + " skipped because the file is missing");
          return text + " " + parts.join("; ") + ". Nothing was changed.";
        }
        if (kind === "replace") {
          var t = "Dry run: " + plural(n("posts"), "page or layout", "pages or layouts") + " would be updated (" + n("posts_scanned") + " scanned), " +
            plural(n("values"), "setting or custom field", "settings or custom fields") + " would change, and " +
            plural(n("switch"), "image") + " would switch to WebP, deleting " + plural(n("files"), "file") + " and freeing " + mb(n("bytes")) + ".";
          if (n("skipped")) t += " " + plural(n("skipped"), "image") + " would be skipped because some size has no WebP file yet. Run step 1 first.";
          t += d.backup_ok ? " Your backup is current." : " You need a new backup (step 2) before you can run it.";
          return t + " Nothing was changed.";
        }
        var c = "Dry run: " + plural(n("deletable"), "leftover original") + " would be deleted (" + mb(n("bytes")) + " freed)";
        if (n("in_use")) c += ", " + n("in_use") + " would be kept because something still uses " + (n("in_use") === 1 ? "it" : "them");
        return c + ". Nothing was changed.";
      }

      function runPreview(kind) {
        var key = kind === "convert" ? "bulk" : kind;
        setBusy(true, "preview");
        msg(key, "Scanning\u2026", "");
        bar(key, 0, 1);
        var send = function (step, cursor, acc) {
          var fd = new FormData();
          fd.append("action", "cdg_webp_preview");
          fd.append("nonce", nonce);
          fd.append("kind", kind);
          fd.append("step", step);
          fd.append("cursor", cursor);
          fd.append("acc", JSON.stringify(acc));
          return fetch(ajaxUrl, { method: "POST", credentials: "same-origin", body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
              if (!j || !j.success) throw new Error((j && j.data && j.data.message) || "The request failed.");
              return j.data;
            }, function () { throw new Error("Unexpected response from the server. Check the PHP error log."); });
        };
        var STEP_LABEL = { content: "Scanning content", options: "Scanning settings", postmeta: "Scanning custom fields", termmeta: "Scanning term fields", attachments: "Scanning images", convert: "Scanning images", cleanup: "Scanning images" };
        return send("", 0, {}).then(function loop(d) {
          if (d.total) bar(key, d.cursor, d.total);
          if (d.done) return d;
          msg(key, (STEP_LABEL[d.step] || "Scanning") + "\u2026 " + (d.total ? d.cursor + " of " + d.total : ""), "");
          return send(d.step, d.cursor, d.acc).then(loop);
        }).then(function (d) {
          bar(key, 1, 1);
          msg(key, describePreview(kind, d), "ok");
        }).catch(function (e) {
          bar(key, null);
          msg(key, e.message, "error");
        }).then(function () { setBusy(false); });
      }

      function cleanupLine(st) {
        return (st.deleted || 0) + " deleted (" + mb(st.bytes_freed || 0) + " freed), " + (st.in_use || 0) + " kept because they are still in use";
      }

      function runCleanup(resume) {
        setBusy(true, "cleanup");
        msg("cleanup", "", "");
        bar("cleanup", 0, 1);
        var step = function (st) {
          bar("cleanup", st.seen || 0, st.total || 0);
          msg("cleanup", "Working\u2026 " + (st.seen || 0) + " of " + (st.total || 0) + ". " + cleanupLine(st), "");
          if (st.error) throw new Error(st.error);
          return st;
        };
        return post(resume ? "tick_cleanup" : "start_cleanup").then(step).then(function loop(st) {
          if (st.done) return st;
          return post("tick_cleanup").then(step).then(loop);
        }).then(function (st) {
          bar("cleanup", 1, 1);
          msg("cleanup", "Finished. " + cleanupLine(st) + ". Purge your page cache if you use one.", "ok");
        }).catch(function (e) {
          msg("cleanup", e.message, "error");
        }).then(function () { setBusy(false); return refresh(); });
      }

      root.addEventListener("click", function (ev) {
        var target = ev.target.closest("[data-act]");
        if (!target || target.disabled) return;
        var act = target.getAttribute("data-act");
        var st = (info && info.status) || {};

        if (act.indexOf("preview-") === 0) {
          runPreview(act.slice(8));
        } else if (act === "bulk") {
          runBulk(st.phase === "converting" && !st.done);
        } else if (act === "cancel") {
          post("cancel_bulk").catch(function () {});
        } else if (act === "backup") {
          setBusy(true);
          msg("backup", "Creating backup… this can take a while on large libraries.", "");
          post("create_backup").then(function (b) {
            msg("backup", "Backup created: " + plural(b.files, "file") + ", " + mb(b.bytes) + ".", "ok");
          }).catch(function (e) {
            msg("backup", e.message, "error");
          }).then(function () { setBusy(false); return refresh(); });
        } else if (act === "replace") {
          var resume = st.phase === "replacing" && !st.done;
          if (resume || window.confirm(
            "Replace originals with WebP?\n\n" +
            "This rewrites image links across your content and PERMANENTLY DELETES the original JPG/PNG files. " +
            "You can undo it with Restore only while the backup exists.\n\nContinue?")) {
            runReplace(resume);
          }
        } else if (act === "cleanup") {
          var cresume = st.phase === "cleaning" && !st.done;
          if (cresume || window.confirm(
            "Delete leftover originals?\n\nOnly originals that nothing on your site links to are removed, and your backup can bring them back.\n\nContinue?")) {
            runCleanup(cresume);
          }
        } else if (act === "restore") {
          if (window.confirm("Restore originals from the latest backup?\n\nOriginal files are put back and image links are switched back to them.")) {
            setBusy(true);
            msg("replace", "Restoring… do not close this page.", "");
            post("start_restore").then(function (r) {
              msg("replace", "Restored " + plural(r.files_restored, "file") + ", " +
                plural(r.attachments_restored, "image") + " and " + plural(r.posts_modified, "post") +
                ". Purge your page cache so visitors get the restored pages.", "ok");
            }).catch(function (e) {
              msg("replace", e.message, "error");
            }).then(function () { setBusy(false); return refresh(); });
          }
        }
      });

      refresh();
    })();

  });
})();
