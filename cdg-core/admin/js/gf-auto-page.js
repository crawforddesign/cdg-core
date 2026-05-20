/**
 * GF Auto Page — New Form Modal Integration
 *
 * Injects an "Auto-Generate Form Page" checkbox into the GF template library
 * flyout panel. On "Create Blank Form" click the value is stored in
 * sessionStorage. When GF redirects to the form editor a WP AJAX call creates
 * the cdg_form post immediately, before the user touches anything.
 */
(function ($) {
    'use strict';

    var STORAGE_KEY = 'cdg_auto_generate_page';

    /**
     * Inject the checkbox into a flyout panel if not already present.
     *
     * @param {Element} flyout
     */
    function injectCheckbox(flyout) {
        if (!flyout || flyout.querySelector('.cdg-auto-page-field')) {
            return;
        }

        var bodyInner = flyout.querySelector('.gform-flyout__body-inner');
        if (!bodyInner) {
            return;
        }

        var field = document.createElement('div');
        field.className = 'gform-box gform-spacing gform-spacing--bottom-6 cdg-auto-page-field';
        field.style.display = 'block';
        field.innerHTML =
            '<div style="display:flex;align-items:center;gap:8px;">' +
                '<input type="checkbox" id="cdg-auto-generate-page" value="1" style="width:auto;margin:0;" />' +
                '<label class="gform-label gform-typography--size-text-sm gform-typography--weight-medium" ' +
                    'for="cdg-auto-generate-page" style="margin:0;cursor:pointer;">' +
                    'Auto-Generate Form Page' +
                '</label>' +
            '</div>' +
            '<p style="margin-top:4px;font-size:11px;color:#6b7280;">' +
                'Creates a draft Divi page for this form automatically.' +
            '</p>';

        bodyInner.appendChild(field);

        // Save checkbox state to sessionStorage when the create button is clicked.
        var createBtn = flyout.querySelector('.gform-flyout__footer-primary-button');
        if (createBtn) {
            createBtn.addEventListener('click', function () {
                var cb = document.getElementById('cdg-auto-generate-page');
                if (cb && cb.checked) {
                    sessionStorage.setItem(STORAGE_KEY, '1');
                } else {
                    sessionStorage.removeItem(STORAGE_KEY);
                }
            });
        }
    }

    // Watch for the flyout being added to the DOM or its classes changing.
    var observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            if (mutation.type === 'childList') {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType !== 1) {
                        return;
                    }
                    if (node.classList.contains('gform-template-library__flyout')) {
                        injectCheckbox(node);
                    }
                    if (node.querySelectorAll) {
                        node.querySelectorAll('.gform-template-library__flyout').forEach(injectCheckbox);
                    }
                });
            }

            // Flyout becomes visible via class change.
            if (
                mutation.type === 'attributes' &&
                mutation.target.classList.contains('gform-template-library__flyout') &&
                mutation.target.classList.contains('gform-flyout--anim-in-active')
            ) {
                injectCheckbox(mutation.target);
            }
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['class'],
    });

    // Handle any flyout already in the DOM on page load.
    document.querySelectorAll('.gform-template-library__flyout').forEach(injectCheckbox);

    // On the form editor page: fire AJAX if the sessionStorage flag is set.
    var params  = new URLSearchParams(window.location.search);
    var isEditor = params.get('page') === 'gf_edit_forms' && params.get('id');

    if (isEditor && sessionStorage.getItem(STORAGE_KEY) === '1') {
        sessionStorage.removeItem(STORAGE_KEY);

        $.post(cdgAutoPage.ajaxUrl, {
            action:  'cdg_create_form_page',
            form_id: params.get('id'),
            nonce:   cdgAutoPage.nonce,
        });
    }

}(jQuery));
