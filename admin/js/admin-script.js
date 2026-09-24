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

      trigger.addEventListener("click", function (e) {
        e.stopPropagation();
        if (openRulesDropdown && openRulesDropdown !== dd) {
          openRulesDropdown.classList.remove("cdg-rules-open");
          var otherPanel = openRulesDropdown.querySelector(".cdg-rules-panel");
          if (otherPanel) otherPanel.setAttribute("hidden", "");
        }
        var open = dd.classList.toggle("cdg-rules-open");
        panel.toggleAttribute("hidden", !open);
        openRulesDropdown = open ? dd : null;
      });

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

  });
})();
