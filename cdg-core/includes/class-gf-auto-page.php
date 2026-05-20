<?php
/**
 * Gravity Forms Auto Page Generator
 *
 * Registers the `cdg_form` custom post type and, when a new form is saved
 * with "Auto-Generate Page" checked, creates a draft cdg_form post containing
 * a Divi 5 block layout with the Divi Essentials Gravity Forms Styler module
 * pre-configured for the form and styled via the default module preset.
 *
 * Theme Builder targeting: Divi 5 → Theme Builder → target "All CDG Forms"
 * (the cdg_form post type).
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

        add_filter('gform_form_settings', [$this, 'add_form_settings_field'], 10, 2);
        add_filter('gform_pre_form_settings_save', [$this, 'save_form_setting']);
        add_action('gform_after_save_form', [$this, 'maybe_create_page'], 10, 2);
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
            'public'        => true,
            'has_archive'   => false,
            'show_in_rest'  => true,
            'show_in_menu'  => true,
            'menu_icon'     => 'dashicons-feedback',
            'supports'      => ['title', 'editor', 'custom-fields'],
            'rewrite'       => ['slug' => 'forms'],
        ]);
    }

    /**
     * Inject the "Auto-Generate Page" field into GF Form Settings.
     *
     * @param array $settings Existing settings sections.
     * @param array $form     Current form array.
     * @return array
     */
    public function add_form_settings_field(array $settings, array $form): array
    {
        $form_id     = absint($form['id'] ?? 0);
        $checked     = !empty($form['cdg_auto_generate_page']);
        $page_id     = $form_id ? absint(get_option(self::OPTION_PREFIX . $form_id, 0)) : 0;
        $page_exists = $page_id && get_post($page_id);

        ob_start();
        ?>
        <tr>
            <th><?php esc_html_e('Auto-Generate Page', 'cdg-core'); ?></th>
            <td>
                <?php if ($page_exists) : ?>
                    <input type="checkbox" name="cdg_auto_generate_page" id="cdg_auto_generate_page" value="1"
                        <?php checked($checked); ?> disabled />
                    <label for="cdg_auto_generate_page">
                        <?php esc_html_e('Page already generated', 'cdg-core'); ?>
                    </label>
                    <p class="description" style="margin-top:6px;">
                        <a href="<?php echo esc_url(get_edit_post_link($page_id)); ?>" target="_blank">
                            <?php esc_html_e('Edit Page', 'cdg-core'); ?>
                        </a>
                        &nbsp;&bull;&nbsp;
                        <a href="<?php echo esc_url(get_permalink($page_id)); ?>" target="_blank">
                            <?php esc_html_e('View Page', 'cdg-core'); ?>
                        </a>
                    </p>
                <?php else : ?>
                    <input type="checkbox" name="cdg_auto_generate_page" id="cdg_auto_generate_page" value="1"
                        <?php checked($checked); ?> />
                    <label for="cdg_auto_generate_page">
                        <?php esc_html_e('Create a draft form page on first save', 'cdg-core'); ?>
                    </label>
                <?php endif; ?>
            </td>
        </tr>
        <?php
        $html = ob_get_clean();

        $settings['CDG Core']['cdg_auto_generate_page'] = $html;

        return $settings;
    }

    /**
     * Persist the checkbox value onto the form array (runs before GF saves it).
     *
     * @param array $form
     * @return array
     */
    public function save_form_setting(array $form): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- GF handles nonce for this request.
        $form['cdg_auto_generate_page'] = isset($_POST['cdg_auto_generate_page']) ? 1 : 0;
        return $form;
    }

    /**
     * Create the auto-page when a form is saved with the option enabled.
     *
     * Fires on both new and existing forms — the duplicate guard (option key)
     * prevents re-creation if a post has already been generated.
     *
     * @param array $form   Saved form array.
     * @param bool  $is_new Unused — creation is gated by the option key instead.
     * @return void
     */
    public function maybe_create_page(array $form, bool $is_new): void
    {
        if (empty($form['cdg_auto_generate_page'])) {
            return;
        }

        $form_id = absint($form['id'] ?? 0);

        if (!$form_id) {
            return;
        }

        if (get_option(self::OPTION_PREFIX . $form_id)) {
            return;
        }

        $page_id = $this->create_form_page($form_id, sanitize_text_field($form['title'] ?? ''));

        if ($page_id) {
            update_option(self::OPTION_PREFIX . $form_id, $page_id, false);
        }
    }

    /**
     * Create the draft cdg_form post for the given form.
     *
     * @param int    $form_id
     * @param string $form_title
     * @return int Post ID on success, 0 on failure.
     */
    private function create_form_page(int $form_id, string $form_title): int
    {
        $post_args = [
            'post_title'   => $form_title ?: sprintf('Form %d', $form_id),
            'post_content' => $this->get_page_content($form_id),
            'post_status'  => 'draft',
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
     * Produces a Section > Row > Column layout containing a single
     * dnxte/gravity-forms module referencing the given form ID and
     * the site's default GF Styler module preset.
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
