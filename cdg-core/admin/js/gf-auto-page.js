/**
 * GF Auto Page — New Form Modal Integration
 *
 * Injects an "Auto-Generate Form Page" checkbox + slug field into the GF
 * template library flyout. On "Create Blank Form" click the values are stored
 * in sessionStorage. When GF redirects to the form editor a WP AJAX call
 * creates the cdg_form post and injects a "View Form Page" button next to
 * the Save Form button.
 */
(function ($) {
  "use strict";

  var STORAGE_KEY = "cdg_auto_generate_page";
  var SLUG_KEY = "cdg_auto_page_slug";

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  function slugify(text) {
    return text
      .toLowerCase()
      .trim()
      .replace(/[^a-z0-9\s-]/g, "")
      .replace(/\s+/g, "-")
      .replace(/-+/g, "-");
  }

  // ---------------------------------------------------------------------------
  // View Form Page button
  // ---------------------------------------------------------------------------

  function injectViewButton(viewUrl) {
    if (!viewUrl || document.getElementById("cdg-view-form-page-btn")) {
      return;
    }

    // Try known GF save button selectors, fall back to text search.
    var saveBtn =
      document.querySelector("#gform-save-form-button") ||
      document.querySelector('[data-js="save-form"]') ||
      document.querySelector(".gform-toolbar__save-button") ||
      Array.prototype.find.call(document.querySelectorAll("button"), function (
        btn
      ) {
        return btn.textContent.trim() === "Save Form";
      });

    if (!saveBtn) {
      return;
    }

    var btn = document.createElement("a");
    btn.id = "cdg-view-form-page-btn";
    btn.href = viewUrl;
    btn.target = "_blank";
    btn.rel = "noopener";
    btn.className =
      "gform-button gform-button--white gform-button--size-r gform-button--width-auto";
    btn.style.marginLeft = "8px";
    btn.textContent = "View Form Page";

    saveBtn.parentNode.insertBefore(btn, saveBtn.nextSibling);
  }

  // Retry injecting the button until the save button appears in the DOM.
  function waitForSaveButton(viewUrl) {
    if (document.getElementById("cdg-view-form-page-btn")) {
      return;
    }

    var target =
      document.querySelector("#gform-save-form-button") ||
      document.querySelector('[data-js="save-form"]') ||
      Array.prototype.find.call(document.querySelectorAll("button"), function (
        btn
      ) {
        return btn.textContent.trim() === "Save Form";
      });

    if (target) {
      injectViewButton(viewUrl);
      return;
    }

    var btnObserver = new MutationObserver(function () {
      injectViewButton(viewUrl);
      if (document.getElementById("cdg-view-form-page-btn")) {
        btnObserver.disconnect();
      }
    });

    btnObserver.observe(document.body, { childList: true, subtree: true });
  }

  // ---------------------------------------------------------------------------
  // Flyout checkbox + slug field injection
  // ---------------------------------------------------------------------------

  function injectCheckbox(flyout) {
    if (!flyout || flyout.querySelector(".cdg-auto-page-field")) {
      return;
    }

    var bodyInner = flyout.querySelector(".gform-flyout__body-inner");
    if (!bodyInner) {
      return;
    }

    var field = document.createElement("div");
    field.className =
      "gform-box gform-spacing gform-spacing--bottom-6 cdg-auto-page-field";
    field.style.display = "block";
    field.innerHTML =
      // Checkbox row
      '<div style="display:flex;align-items:center;gap:8px;">' +
      '<input type="checkbox" id="cdg-auto-generate-page" value="1" style="width:auto;margin:0;" />' +
      '<label class="gform-label gform-typography--size-text-sm gform-typography--weight-medium" ' +
      'for="cdg-auto-generate-page" style="margin:0;cursor:pointer;">' +
      "Auto-Generate Form Page" +
      "</label>" +
      "</div>" +
      '<p style="margin-top:4px;font-size:11px;color:#6b7280;">' +
      "Creates a draft Divi page for this form automatically based on the layout in the " +
      "<a href='/wp-admin/admin.php?page=et_theme_builder'>Divi Theme Builder</a>." +
      "</p>" +
      // Slug field (hidden until checkbox checked)
      '<div id="cdg-slug-wrapper" style="display:none;margin-top:12px;">' +
      '<div class="gform-input-wrapper gform-input-wrapper--theme-cosmos gform-input-wrapper--input gform-input-wrapper--border-default">' +
      '<label class="gform-label gform-typography--size-text-sm gform-typography--weight-medium" for="cdg-page-slug">Page Slug</label>' +
      '<input class="gform-input gform-typography--size-text-sm gform-input--size-l gform-input--text" ' +
      'id="cdg-page-slug" type="text" placeholder="page-slug" />' +
      "</div>" +
      '<p style="margin-top:4px;font-size:11px;color:#6b7280;">' +
      "/forms/<span id=\"cdg-slug-preview\"></span>/" +
      "</p>" +
      "</div>";

    bodyInner.appendChild(field);

    var checkbox = document.getElementById("cdg-auto-generate-page");
    var slugWrapper = document.getElementById("cdg-slug-wrapper");
    var slugInput = document.getElementById("cdg-page-slug");
    var slugPreview = document.getElementById("cdg-slug-preview");
    var titleInput = document.getElementById("template-library-form-title-input");

    function updateSlug() {
      var title = titleInput ? titleInput.value : "";
      var slug = slugify(title) || "form";
      slugInput.value = slug;
      slugPreview.textContent = slug;
    }

    // Show/hide slug field when checkbox is toggled.
    checkbox.addEventListener("change", function () {
      if (this.checked) {
        updateSlug();
        slugWrapper.style.display = "block";
      } else {
        slugWrapper.style.display = "none";
      }
    });

    // Keep slug in sync with title while checkbox is on.
    if (titleInput) {
      titleInput.addEventListener("input", function () {
        if (checkbox.checked) {
          updateSlug();
        }
      });
    }

    // Allow manual slug edits to update the preview.
    slugInput.addEventListener("input", function () {
      var sanitized = slugify(this.value);
      slugPreview.textContent = sanitized;
    });

    // Save values to sessionStorage when Create is clicked.
    var createBtn = flyout.querySelector(".gform-flyout__footer-primary-button");
    if (createBtn) {
      createBtn.addEventListener("click", function () {
        if (checkbox && checkbox.checked) {
          sessionStorage.setItem(STORAGE_KEY, "1");
          sessionStorage.setItem(
            SLUG_KEY,
            slugify(slugInput.value) || slugify(titleInput ? titleInput.value : "") || "form"
          );
        } else {
          sessionStorage.removeItem(STORAGE_KEY);
          sessionStorage.removeItem(SLUG_KEY);
        }
      });
    }
  }

  // ---------------------------------------------------------------------------
  // MutationObserver — watch for flyout appearing
  // ---------------------------------------------------------------------------

  var observer = new MutationObserver(function (mutations) {
    mutations.forEach(function (mutation) {
      if (mutation.type === "childList") {
        mutation.addedNodes.forEach(function (node) {
          if (node.nodeType !== 1) {
            return;
          }
          if (node.classList.contains("gform-template-library__flyout")) {
            injectCheckbox(node);
          }
          if (node.querySelectorAll) {
            node
              .querySelectorAll(".gform-template-library__flyout")
              .forEach(injectCheckbox);
          }
        });
      }

      // Flyout becomes visible via class change.
      if (
        mutation.type === "attributes" &&
        mutation.target.classList.contains("gform-template-library__flyout") &&
        mutation.target.classList.contains("gform-flyout--anim-in-active")
      ) {
        injectCheckbox(mutation.target);
      }
    });
  });

  observer.observe(document.body, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ["class"],
  });

  // Handle any flyout already in the DOM on page load.
  document
    .querySelectorAll(".gform-template-library__flyout")
    .forEach(injectCheckbox);

  // ---------------------------------------------------------------------------
  // Form editor page — AJAX create + button injection
  // ---------------------------------------------------------------------------

  var params = new URLSearchParams(window.location.search);
  var isEditor = params.get("page") === "gf_edit_forms" && params.get("id");

  // Inject button immediately if the page already has a form page.
  if (isEditor && cdgAutoPage.viewUrl) {
    waitForSaveButton(cdgAutoPage.viewUrl);
  }

  // New form flow: sessionStorage flag set by the flyout checkbox.
  if (isEditor && sessionStorage.getItem(STORAGE_KEY) === "1") {
    var slug = sessionStorage.getItem(SLUG_KEY) || "";
    sessionStorage.removeItem(STORAGE_KEY);
    sessionStorage.removeItem(SLUG_KEY);

    $.post(
      cdgAutoPage.ajaxUrl,
      {
        action: "cdg_create_form_page",
        form_id: params.get("id"),
        post_slug: slug,
        nonce: cdgAutoPage.nonce,
      },
      function (response) {
        if (response.success && response.data.view_url) {
          waitForSaveButton(response.data.view_url);
        }
      }
    );
  }
})(jQuery);
