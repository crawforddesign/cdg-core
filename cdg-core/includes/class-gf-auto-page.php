<?php
/**
 * Gravity Forms Auto Page Generator
 *
 * Registers the `cdg_form` custom post type and handles auto-generating a
 * draft Divi 5 form page in two flows:
 *
 * 1. New form modal (gf_new_form): JS injects a checkbox + slug field into
 *    the flyout panel, stores values in sessionStorage, then fires a WP AJAX
 *    call on the form editor page to create the cdg_form post.
 *
 * 2. Form lifecycle: gform_after_delete_form trashes the associated post and
 *    cleans up the options key.
 *
 * @package CDG_Core
 * @since 1.5.0
 */

declare(strict_types=1);

class CDG_Core_GF_Auto_Page
{
    private const POST_TYPE     = 'cdg_form';
    private const OPTION_PREFIX = 'cdg_form_page_map_';
    private const PRESET_ID     = 'a2d02oayef';
    private const AJAX_ACTION   = 'cdg_create_form_page';

    /**
     * @var CDG_Core
     */
    private CDG_Core $plugin;

    /**
     * @param CDG_Core $plugin
     */
    public function __construct(CDG_Core $plugin)
    {
        $this->plugin = $plugin;
        $this->setup_hooks();
    }

    /**
     * Register all hooks.
     *
     * @return void
     */
    private function setup_hooks(): void
    {
        add_action('init', [$this, 'register_post_type']);

        if (!class_exists('GFForms')) {
            return;
        }

        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handle_create_page_ajax']);
        add_action('gform_after_delete_form', [$this, 'handle_form_deleted']);
    }

    /**
     * Register the cdg_form custom post type.
     *
     * @return void
     */
    public function register_post_type(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name'               => __('Forms', 'cdg-core'),
                'singular_name'      => __('Form', 'cdg-core'),
                'add_new_item'       => __('Add New Form Page', 'cdg-core'),
                'edit_item'          => __('Edit Form Page', 'cdg-core'),
                'view_item'          => __('View Form Page', 'cdg-core'),
                'search_items'       => __('Search Form Pages', 'cdg-core'),
                'not_found'          => __('No form pages found.', 'cdg-core'),
                'not_found_in_trash' => __('No form pages found in trash.', 'cdg-core'),
            ],
            'public'            => true,
            'has_archive'       => false,
            'show_in_rest'      => true,
            'show_in_menu'      => false,
            'show_in_admin_bar' => false,
            'supports'          => ['title', 'editor', 'custom-fields'],
            'rewrite'           => ['slug' => 'forms'],
        ]);
    }

    /**
     * Enqueue admin JS on GF pages only.
     *
     * Passes the existing form page view URL (if any) so the "View Form Page"
     * button can be injected immediately on the form editor page.
     *
     * @return void
     */
    public function enqueue_admin_scripts(): void
    {
        $page = sanitize_text_field($_GET['page'] ?? '');

        if (strpos($page, 'gf_') !== 0) {
            return;
        }

        wp_enqueue_style(
            'cdg-gf-auto-page',
            CDG_CORE_URL . 'admin/css/gf-auto-page.css',
            [],
            CDG_CORE_VERSION
        );

        wp_enqueue_script(
            'cdg-gf-auto-page',
            CDG_CORE_URL . 'admin/js/gf-auto-page.js',
            ['jquery'],
            CDG_CORE_VERSION,
            true
        );

        $view_url = '';
        $form_id  = absint($_GET['id'] ?? 0);

        if ($form_id) {
            $post_id = absint(get_option(self::OPTION_PREFIX . $form_id, 0));
            if ($post_id && get_post($post_id)) {
                $view_url = get_permalink($post_id);
            }
        }

        wp_localize_script('cdg-gf-auto-page', 'cdgAutoPage', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::AJAX_ACTION),
            'viewUrl' => $view_url,
        ]);
    }

    /**
     * AJAX handler: create the form page from the new form modal flow.
     *
     * @return void
     */
    public function handle_create_page_ajax(): void
    {
        check_ajax_referer(self::AJAX_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $form_id = absint($_POST['form_id'] ?? 0);

        if (!$form_id) {
            wp_send_json_error(['message' => 'Invalid form ID']);
        }

        if (get_option(self::OPTION_PREFIX . $form_id)) {
            wp_send_json_error(['message' => 'Page already exists for this form']);
        }

        $form      = class_exists('GFAPI') ? GFAPI::get_form($form_id) : [];
        $form_title = sanitize_text_field($form['title'] ?? '');
        $post_slug  = sanitize_title($_POST['post_slug'] ?? $form_title);

        $post_id = $this->create_form_page($form_id, $form_title, $post_slug);

        if (!$post_id) {
            wp_send_json_error(['message' => 'Failed to create form page']);
        }

        update_option(self::OPTION_PREFIX . $form_id, $post_id, false);

        wp_send_json_success([
            'post_id'  => $post_id,
            'edit_url' => get_edit_post_link($post_id, 'raw'),
            'view_url' => get_permalink($post_id),
        ]);
    }

    /**
     * Trash the associated cdg_form post when a GF form is deleted.
     *
     * @param int $form_id
     * @return void
     */
    public function handle_form_deleted(int $form_id): void
    {
        $option_key = self::OPTION_PREFIX . $form_id;
        $post_id    = absint(get_option($option_key, 0));

        if ($post_id && get_post($post_id)) {
            wp_trash_post($post_id);
        }

        delete_option($option_key);
    }

/**
     * Create the draft cdg_form post for the given form.
     *
     * @param int    $form_id
     * @param string $form_title
     * @param string $post_slug
     * @return int Post ID on success, 0 on failure.
     */
    private function create_form_page(int $form_id, string $form_title, string $post_slug = ''): int
    {
        $post_args = [
            'post_title'   => $form_title ?: sprintf('Form %d', $form_id),
            'post_name'    => $post_slug ?: sanitize_title($form_title ?: sprintf('form-%d', $form_id)),
            'post_content' => $this->get_page_content($form_id),
            'post_status'  => 'publish',
            'post_type'    => self::POST_TYPE,
        ];

        $post_id = wp_insert_post($post_args, true);

        if (is_wp_error($post_id)) {
            return 0;
        }

        update_post_meta($post_id, '_et_pb_use_builder', 'on');
        update_post_meta($post_id, '_et_pb_built_for_post_type', self::POST_TYPE);

        return $post_id;
    }

    /**
     * Build the Divi 5 block markup for the GF Styler module.
     *
     * @param int $form_id
     * @return string Divi 5 block markup for post_content.
     */
    private function get_page_content(int $form_id): string
    {
        $id = (string) $form_id;

        $module_attrs = wp_json_encode(
            [
                'formId'         => [
                    'innerContent' => [
                        'widescreen' => ['value' => ['id' => $id]],
                        'desktop'    => ['value' => ['id' => $id]],
                    ],
                ],
                'builderVersion' => '5.1.0',
                'modulePreset'   => [self::PRESET_ID],
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $section_attrs = '{"builderVersion":"5.1.0"}';

        $row_attrs = '{"module":{"advanced":{"flexColumnStructure":{"desktop":{"value":"equal-columns_1"}}},'
            . '"decoration":{"layout":{"desktop":{"value":{"flexWrap":"nowrap"}}},'
            . '"sizing":{"widescreen":{"value":{"maxWidth":"2560px"}}}}},'
            . '"builderVersion":"5.1.0"}';

        $col_attrs = '{"module":{"decoration":{"sizing":{"desktop":{"value":{"flexType":"24_24"}}}}},'
            . '"builderVersion":"5.1.0"}';

        return implode("\r\n", [
            "<!-- wp:divi/placeholder --><!-- wp:divi/section {$section_attrs} -->",
            "<!-- wp:divi/row {$row_attrs} -->",
            "<!-- wp:divi/column {$col_attrs} -->",
            "<!-- wp:dnxte/gravity-forms {$module_attrs} /-->",
            '<!-- /wp:divi/column -->',
            '<!-- /wp:divi/row -->',
            '<!-- /wp:divi/section --><!-- /wp:divi/placeholder -->',
        ]);
    }
}
