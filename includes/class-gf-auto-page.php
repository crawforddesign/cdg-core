<?php
/**
 * Gravity Forms Auto Page Generator
 *
 * Registers the `cdg_form` custom post type and handles auto-generating a
 * published Divi 5 form page in two flows:
 *
 * 1. New form modal (gf_new_form): JS injects a checkbox + slug field into
 *    the flyout panel, stores values in sessionStorage, then fires a WP AJAX
 *    call on the form editor page to create the cdg_form post.
 *
 * 2. Form lifecycle: gform_after_delete_form trashes the associated post and
 *    cleans up the options key.
 *
 * 3. Form schedule: when a form has "Schedule form" enabled with an end date,
 *    a single cron event switches the form page to Draft at that time. If the
 *    end date is later moved forward or scheduling is turned off, a page that
 *    was drafted by the schedule is published again.
 *
 * @package CDG_Core
 * @since 1.5.0
 */

declare(strict_types=1);

class CDG_Core_GF_Auto_Page
{
    private const POST_TYPE     = 'cdg_form';
    private const OPTION_PREFIX = 'cdg_form_page_map_';
    private const AJAX_ACTION   = 'cdg_create_form_page';
    private const DRAFTED_META  = '_cdg_drafted_by_schedule';

    public const CRON_HOOK = 'cdg_form_page_schedule_end';

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
        add_action('gform_post_update_form_meta', [$this, 'handle_form_meta_updated'], 10, 3);
        add_action(self::CRON_HOOK, [$this, 'handle_schedule_end']);
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

        $view_url     = '';
        $default_slug = '';
        $form_id      = absint($_GET['id'] ?? 0);

        if ($form_id) {
            $post_id = absint(get_option(self::OPTION_PREFIX . $form_id, 0));
            if ($post_id && get_post($post_id)) {
                $view_url = get_permalink($post_id);
            }

            if (!$view_url && class_exists('GFAPI')) {
                $form         = GFAPI::get_form($form_id);
                $default_slug = sanitize_title($form['title'] ?? '');
            }
        }

        wp_localize_script('cdg-gf-auto-page', 'cdgAutoPage', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::AJAX_ACTION),
            'viewUrl' => $view_url,
            'defaultSlug' => $default_slug,
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

        if (!empty($form)) {
            $this->sync_schedule($form_id, $form);
        }

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
        wp_clear_scheduled_hook(self::CRON_HOOK, [$form_id]);
    }

    /**
     * Re-sync the page's schedule whenever the form's display meta is saved
     * (form editor and Form Settings both go through this).
     *
     * @param mixed  $form_meta
     * @param int    $form_id
     * @param string $meta_name
     * @return void
     */
    public function handle_form_meta_updated($form_meta, $form_id, $meta_name): void
    {
        if ($meta_name !== 'display_meta') {
            return;
        }

        $form_id = absint($form_id);

        if (!$form_id || !get_option(self::OPTION_PREFIX . $form_id)) {
            return;
        }

        if (!is_array($form_meta)) {
            $form_meta = class_exists('GFAPI') ? GFAPI::get_form($form_id) : [];
        }

        if (is_array($form_meta)) {
            $this->sync_schedule($form_id, $form_meta);
        }
    }

    /**
     * Cron callback: draft the form page once the form's schedule has ended.
     *
     * The form is re-read so a stale event (schedule changed after it was
     * queued) does nothing.
     *
     * @param int $form_id
     * @return void
     */
    public function handle_schedule_end($form_id): void
    {
        $form_id = absint($form_id);
        $post_id = absint(get_option(self::OPTION_PREFIX . $form_id, 0));
        $form    = class_exists('GFAPI') ? GFAPI::get_form($form_id) : false;

        if (!$post_id || !is_array($form)) {
            return;
        }

        $end = $this->get_schedule_end_timestamp($form);

        if ($end === null || $end > time()) {
            return;
        }

        if (get_post_status($post_id) !== 'publish') {
            return;
        }

        $result = wp_update_post(['ID' => $post_id, 'post_status' => 'draft'], true);

        if (!is_wp_error($result)) {
            update_post_meta($post_id, self::DRAFTED_META, 1);
        }
    }

    /**
     * Queue (or clear) the schedule-end event for a form, and republish the
     * page if the schedule no longer applies.
     *
     * @param int   $form_id
     * @param array $form
     * @return void
     */
    private function sync_schedule(int $form_id, array $form): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK, [$form_id]);

        $end = $this->get_schedule_end_timestamp($form);

        if ($end !== null) {
            // A past end date still queues: cron runs it on the next request.
            wp_schedule_single_event(max($end, time()), self::CRON_HOOK, [$form_id]);

            if ($end <= time()) {
                return;
            }
        }

        $post_id = absint(get_option(self::OPTION_PREFIX . $form_id, 0));

        if (
            $post_id
            && get_post_meta($post_id, self::DRAFTED_META, true)
            && get_post_status($post_id) === 'draft'
        ) {
            wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);
            delete_post_meta($post_id, self::DRAFTED_META);
        }
    }

    /**
     * Get the form's schedule end as a Unix timestamp.
     *
     * Gravity Forms stores the end as a m/d/Y date plus 12-hour time parts in
     * the site's timezone.
     *
     * @param array $form
     * @return int|null Null when scheduling is off or there is no end date.
     */
    private function get_schedule_end_timestamp(array $form): ?int
    {
        if (empty($form['scheduleForm']) || empty($form['scheduleEnd'])) {
            return null;
        }

        $local = sprintf(
            '%s %02d:%02d %s',
            $form['scheduleEnd'],
            (int) ($form['scheduleEndHour'] ?? 0),
            (int) ($form['scheduleEndMinute'] ?? 0),
            $form['scheduleEndAmpm'] ?? ''
        );

        try {
            return (new DateTimeImmutable(trim($local), wp_timezone()))->getTimestamp();
        } catch (Exception $e) {
            return null;
        }
    }

/**
     * Create the published cdg_form post for the given form.
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
     * Build the Divi 5 block markup for the native Divi Gravity Forms module.
     *
     * @param int $form_id
     * @return string Divi 5 block markup for post_content.
     */
    private function get_page_content(int $form_id): string
    {
        $id = (string) $form_id;

        $module_attrs = wp_json_encode(
            [
                'gravityForm'    => [
                    'innerContent' => [
                        'desktop' => ['value' => ['formId' => $id]],
                    ],
                ],
                'builderVersion' => '5.1.0',
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
            "<!-- wp:divi/gravity-forms {$module_attrs} /-->",
            '<!-- /wp:divi/column -->',
            '<!-- /wp:divi/row -->',
            '<!-- /wp:divi/section --><!-- /wp:divi/placeholder -->',
        ]);
    }
}
