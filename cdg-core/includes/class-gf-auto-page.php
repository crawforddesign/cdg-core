<?php
/**
 * Gravity Forms Auto Page Generator
 *
 * When a new form is saved with "Auto-Generate Page" checked, creates a draft
 * WordPress page containing the [gravityforms] shortcode, tags it with
 * `auto-form-page` (for Divi Theme Builder targeting), and applies the Divi
 * blank canvas template.
 *
 * @package CDG_Core
 * @since 1.5.0
 */

declare(strict_types=1);

class CDG_Core_GF_Auto_Page
{
    private const TAG_SLUG      = 'auto-form-page';
    private const TEMPLATE_FILE = 'page-template-blank.php';
    private const OPTION_PREFIX = 'cdg_form_page_map_';

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
        // Ensure post_tag is available on pages.
        add_action('init', [$this, 'register_tag_taxonomy_for_pages']);

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
     * Allow post_tag taxonomy on pages (required for Divi Theme Builder targeting).
     *
     * @return void
     */
    public function register_tag_taxonomy_for_pages(): void
    {
        register_taxonomy_for_object_type('post_tag', 'page');
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
        $form_id   = absint($form['id'] ?? 0);
        $checked   = !empty($form['cdg_auto_generate_page']);
        $page_id   = $form_id ? absint(get_option(self::OPTION_PREFIX . $form_id, 0)) : 0;
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
                        <?php esc_html_e('Create a draft page with this form\'s shortcode on first save', 'cdg-core'); ?>
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
     * Create the auto-page when a new form is saved with the option enabled.
     *
     * @param array $form     Saved form array.
     * @param bool  $is_new   True only on initial form creation.
     * @return void
     */
    public function maybe_create_page(array $form, bool $is_new): void
    {
        if (!$is_new) {
            return;
        }

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
     * @return int   Page ID on success, 0 on failure.
     */
    private function create_form_page(int $form_id, string $form_title): int
    {
        $page_args = [
            'post_title'   => $form_title ?: sprintf('Form %d', $form_id),
            'post_content' => sprintf('[gravityforms id="%d"]', $form_id),
            'post_status'  => 'draft',
            'post_type'    => 'page',
            'tags_input'   => [self::TAG_SLUG],
        ];

        $page_id = wp_insert_post($page_args, true);

        if (is_wp_error($page_id)) {
            return 0;
        }

        // Apply Divi blank canvas template.
        update_post_meta($page_id, '_wp_page_template', self::TEMPLATE_FILE);

        return $page_id;
    }
}
