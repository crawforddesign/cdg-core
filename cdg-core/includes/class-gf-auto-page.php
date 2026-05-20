<?php
/**
 * Gravity Forms Auto Page Generator
 *
 * When a new form is saved with "Auto-Generate Page" checked, creates a draft
 * WordPress page containing a Divi 5 block layout with the Divi Essentials
 * Gravity Forms Styler module pre-configured for the form and styled via the
 * default module preset.
 *
 * @package CDG_Core
 * @since 1.5.0
 */

declare(strict_types=1);

class CDG_Core_GF_Auto_Page
{
    private const TEMPLATE_FILE = 'page-template-blank.php';
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
        if (!class_exists('GFForms')) {
            return;
        }

        // Form Settings UI.
        add_filter('gform_form_settings', [$this, 'add_form_settings_field'], 10, 2);

        // Persist the checkbox value onto the form array before GF saves it.
        add_filter('gform_pre_form_settings_save', [$this, 'save_form_setting']);

        // After a form is saved, maybe create the page.
        add_action('gform_after_save_form', [$this, 'maybe_create_page'], 10, 2);
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
                        <?php esc_html_e('Create a draft page with this form on first save', 'cdg-core'); ?>
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
     * prevents re-creation if a page has already been generated.
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

        // Duplicate guard.
        if (get_option(self::OPTION_PREFIX . $form_id)) {
            return;
        }

        $page_id = $this->create_form_page($form_id, sanitize_text_field($form['title'] ?? ''));

        if ($page_id) {
            update_option(self::OPTION_PREFIX . $form_id, $page_id, false);
        }
    }

    /**
     * Create the draft page for the given form.
     *
     * @param int    $form_id
     * @param string $form_title
     * @return int Page ID on success, 0 on failure.
     */
    private function create_form_page(int $form_id, string $form_title): int
    {
        $page_args = [
            'post_title'   => $form_title ?: sprintf('Form %d', $form_id),
            'post_content' => $this->get_page_content($form_id),
            'post_status'  => 'draft',
            'post_type'    => 'page',
        ];

        $page_id = wp_insert_post($page_args, true);

        if (is_wp_error($page_id)) {
            return 0;
        }

        update_post_meta($page_id, '_wp_page_template', self::TEMPLATE_FILE);

        return $page_id;
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
