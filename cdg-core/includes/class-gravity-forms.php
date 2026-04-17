<?php
/**
 * Gravity Forms Compatibility Class
 *
 * Fixes conflicts between Gravity Forms and Divi.
 *
 * @package CDG_Core
 * @since 1.0.0
 */

declare(strict_types=1);

class CDG_Core_Gravity_Forms
{
    /**
     * Plugin instance
     *
     * @var CDG_Core
     */
    private CDG_Core $plugin;

    /**
     * Constructor
     *
     * @param CDG_Core $plugin Plugin instance
     */
    public function __construct(CDG_Core $plugin)
    {
        $this->plugin = $plugin;
        $this->setup_hooks();
    }

    /**
     * Setup hooks
     *
     * @return void
     */
    private function setup_hooks(): void
    {
        // Force gf_global creation
        add_action('wp_enqueue_scripts', [$this, 'force_gf_global'], 20);

        // Backup: inject in footer
        add_action('wp_footer', [$this, 'inject_gf_global_fallback'], 5);

        // Ensure scripts load in footer
        add_filter('gform_init_scripts_footer', '__return_true');

        // Disable Divi optimizations on GF pages
        add_filter('et_builder_enable_dynamic_assets', [$this, 'disable_divi_optimization']);
        add_filter('et_builder_defer_jquery', [$this, 'disable_jquery_defer']);
    }

    /**
     * Check if fixes should be applied
     *
     * @return bool
     */
    private function should_apply_fixes(): bool
    {
        if (is_admin()) {
            return false;
        }

        $mode = $this->plugin->get_setting('gf_detection_mode');

        if ($mode === 'manual') {
            $pages = $this->plugin->get_setting('gf_manual_pages');
            if (!is_array($pages)) {
                $pages = [];
            }
            return $this->is_on_specified_pages($pages);
        }

        // Auto-detect mode
        return $this->page_has_gravity_form();
    }

    /**
     * Check if on specified pages
     *
     * @param array $pages Page slugs
     * @return bool
     */
    private function is_on_specified_pages(array $pages): bool
    {
        if (empty($pages)) {
            return false;
        }

        $pages = array_map('trim', $pages);
        $pages = array_filter($pages);

        return is_page($pages);
    }

    /**
     * Check if current page has Gravity Form
     *
     * @return bool
     */
    private function page_has_gravity_form(): bool
    {
        global $post;

        if (!$post || !class_exists('GFForms')) {
            return false;
        }

        // Check for shortcode in content
        if (has_shortcode($post->post_content, 'gravityform')) {
            return true;
        }

        // Check for GF block
        if (function_exists('has_block') && has_block('gravityforms/form', $post)) {
            return true;
        }

        // Check in Divi content (shortcode might be inside Divi modules)
        if (strpos((string) $post->post_content, '[gravityform') !== false) {
            return true;
        }

        // Also check manual pages as additions
        $manual_pages = $this->plugin->get_setting('gf_manual_pages');
        if (!empty($manual_pages) && is_array($manual_pages)) {
            if ($this->is_on_specified_pages($manual_pages)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the gf_global configuration array
     *
     * Centralizes the gf_global data structure to avoid duplication
     * between force_gf_global() and inject_gf_global_fallback().
     *
     * @return array<string, mixed>|null Configuration array, or null if GF classes unavailable
     */
    private function build_gf_global_config(): ?array
    {
        if (!class_exists('RGCurrency') || !class_exists('GFCommon')) {
            return null;
        }

        $currency = new \RGCurrency(\GFCommon::get_currency());

        return [
            'gf_currency_config' => [
                'currency' => $currency->code ?? 'USD',
                'currency_symbol' => ($currency->symbol_left ?? '$') . ($currency->symbol_right ?? ''),
                'currency_symbol_left' => $currency->symbol_left ?? '$',
                'currency_symbol_right' => $currency->symbol_right ?? '',
                'currency_symbol_padding' => $currency->symbol_padding ?? '',
                'decimals' => $currency->decimals ?? 2,
                'decimal_separator' => $currency->decimal_separator ?? '.',
                'thousand_separator' => $currency->thousand_separator ?? ',',
            ],
            'base_url' => \GFCommon::get_base_url(),
            'number_formats' => [],
            'spinnerUrl' => \GFCommon::get_base_url() . '/images/spinner.svg',
            'version_hash' => \GFForms::$version ?? '1.0',
            'strings' => [
                'newRowAdded' => esc_html__('New row added.', 'gravityforms'),
                'rowRemoved' => esc_html__('Row removed', 'gravityforms'),
                'formSaved' => esc_html__('The form has been saved.', 'gravityforms'),
            ],
        ];
    }

    /**
     * Force gf_global object creation
     *
     * @return void
     */
    public function force_gf_global(): void
    {
        if (!$this->should_apply_fixes()) {
            return;
        }

        $gf_global_data = $this->build_gf_global_config();

        if ($gf_global_data === null) {
            return;
        }

        // Enqueue Gravity Forms scripts
        wp_enqueue_script('gform_gravityforms');

        wp_localize_script('gform_gravityforms', 'gf_global', $gf_global_data);
    }

    /**
     * Inject gf_global fallback in footer
     *
     * @return void
     */
    public function inject_gf_global_fallback(): void
    {
        if (!$this->should_apply_fixes()) {
            return;
        }

        $gf_global_config = $this->build_gf_global_config();

        if ($gf_global_config === null) {
            return;
        }

        ?>
        <script type="text/javascript">
        if (typeof gf_global === 'undefined') {
            var gf_global = <?php echo wp_json_encode($gf_global_config, JSON_UNESCAPED_SLASHES); ?>;
        }
        </script>
        <?php
    }

    /**
     * Disable Divi dynamic asset optimization on GF pages
     *
     * @param mixed $enabled Current setting
     * @return bool
     */
    public function disable_divi_optimization($enabled): bool
    {
        if ($this->should_apply_fixes()) {
            return true;
        }

        return (bool) $enabled;
    }

    /**
     * Disable jQuery defer on GF pages
     *
     * @param mixed $defer Current setting
     * @return bool
     */
    public function disable_jquery_defer($defer): bool
    {
        if ($this->should_apply_fixes()) {
            return false;
        }

        return (bool) $defer;
    }
}
