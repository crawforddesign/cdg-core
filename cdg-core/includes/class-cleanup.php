<?php
/**
 * WordPress Cleanup Class
 *
 * Handles WordPress head cleanup, emoji removal, and dashboard widget removal.
 *
 * @package CDG_Core
 * @since 1.0.0
 */

declare(strict_types=1);

class CDG_Core_Cleanup
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
        // WordPress head cleanup
        add_action('init', [$this, 'cleanup_wp_head']);

        // Emoji removal
        if ($this->plugin->get_setting('disable_emojis')) {
            add_action('init', [$this, 'disable_emojis']);
        }

        // PHP Nag removal - must happen BEFORE wp_dashboard_setup runs the nag
        if ($this->plugin->get_setting('remove_php_nag')) {
            add_action('admin_init', [$this, 'remove_php_nag_early']);
        }

        // Dashboard widget removal - high priority to run after widgets are registered
        add_action('wp_dashboard_setup', [$this, 'remove_dashboard_widgets'], 999);

        // Capture available widgets for admin UI (runs after all widgets registered)
        add_action('wp_dashboard_setup', [$this, 'capture_dashboard_widgets'], 9999);

        // Invalidate widget cache when plugins are activated/deactivated
        add_action('activated_plugin', [__CLASS__, 'invalidate_widget_cache']);
        add_action('deactivated_plugin', [__CLASS__, 'invalidate_widget_cache']);

        // Heartbeat control
        add_action('init', [$this, 'control_heartbeat_frontend']);
        add_action('admin_init', [$this, 'control_heartbeat_admin']);
    }

    /**
     * Invalidate the dashboard widgets transient cache
     *
     * @return void
     */
    public static function invalidate_widget_cache(): void
    {
        delete_transient('cdg_dashboard_widgets');
    }

    /**
     * Capture all registered dashboard widgets for admin UI
     *
     * @return void
     */
    public function capture_dashboard_widgets(): void
    {
        global $wp_meta_boxes;

        if (
            !isset($wp_meta_boxes['dashboard']) ||
            !is_array($wp_meta_boxes['dashboard'])
        ) {
            return;
        }

        $widgets = [];

        foreach ($wp_meta_boxes['dashboard'] as $context => $priorities) {
            if (!is_array($priorities)) {
                continue;
            }

            foreach ($priorities as $priority => $boxes) {
                if (!is_array($boxes)) {
                    continue;
                }

                foreach ($boxes as $id => $widget) {
                    if (empty($widget) || !is_array($widget)) {
                        continue;
                    }

                    // Skip our own widgets
                    if (strpos((string) $id, 'cdg_') === 0) {
                        continue;
                    }

                    $widgets[$id] = [
                        'id' => $id,
                        'title' => wp_strip_all_tags($widget['title'] ?? $id),
                        'context' => $context,
                        'priority' => $priority,
                    ];
                }
            }
        }

        // Store for admin UI
        set_transient('cdg_dashboard_widgets', $widgets, HOUR_IN_SECONDS);
    }

    /**
     * Get all available dashboard widgets
     *
     * @return array
     */
    public static function get_available_widgets(): array
    {
        $widgets = get_transient('cdg_dashboard_widgets');

        if (!is_array($widgets)) {
            return [];
        }

        return $widgets;
    }

    /**
     * Remove PHP nag early before it gets added
     *
     * @return void
     */
    public function remove_php_nag_early(): void
    {
        remove_action('wp_dashboard_setup', 'wp_dashboard_php_nag');
    }

    /**
     * Cleanup WordPress head
     *
     * @return void
     */
    public function cleanup_wp_head(): void
    {
        if ($this->plugin->get_setting('remove_wp_version')) {
            remove_action('wp_head', 'wp_generator');
            add_filter('the_generator', '__return_empty_string');
        }

        if ($this->plugin->get_setting('remove_wlw_manifest')) {
            remove_action('wp_head', 'wlwmanifest_link');
        }

        if ($this->plugin->get_setting('remove_rsd_link')) {
            remove_action('wp_head', 'rsd_link');
        }

        if ($this->plugin->get_setting('remove_shortlink')) {
            remove_action('wp_head', 'wp_shortlink_wp_head');
            remove_action('template_redirect', 'wp_shortlink_header', 11);
        }

        if ($this->plugin->get_setting('remove_adjacent_posts')) {
            remove_action('wp_head', 'adjacent_posts_rel_link_wp_head');
        }

        if ($this->plugin->get_setting('remove_oembed_links')) {
            remove_action('wp_head', 'wp_oembed_add_discovery_links');
            remove_action('wp_head', 'wp_oembed_add_host_js');
        }

        if ($this->plugin->get_setting('remove_rest_api_link')) {
            remove_action('wp_head', 'rest_output_link_wp_head');
            remove_action('template_redirect', 'rest_output_link_header', 11);
        }
    }

    /**
     * Disable WordPress emojis
     *
     * Note: DNS prefetch removal for s.w.org is handled by CDG_Core_Performance
     * via the 'remove_dns_prefetch' setting to avoid duplicate filters.
     *
     * @return void
     */
    public function disable_emojis(): void
    {
        // Remove emoji scripts
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');

        // Remove emoji styles
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');

        // Remove emoji from feeds
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');

        // Remove emoji from emails
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');

        // Remove from TinyMCE
        add_filter('tiny_mce_plugins', function ($plugins) {
            if (is_array($plugins)) {
                return array_diff($plugins, ['wpemoji']);
            }
            return [];
        });

        // Disable emoji SVG URL
        add_filter('emoji_svg_url', '__return_false');
    }

    /**
     * Remove dashboard widgets
     *
     * @return void
     */
    public function remove_dashboard_widgets(): void
    {
        if ($this->plugin->get_setting('remove_quick_draft')) {
            remove_meta_box('dashboard_quick_press', 'dashboard', 'side');
        }

        if ($this->plugin->get_setting('remove_wp_news')) {
            remove_meta_box('dashboard_primary', 'dashboard', 'side');
            remove_meta_box('dashboard_primary', 'dashboard', 'normal');
            remove_meta_box('dashboard_secondary', 'dashboard', 'side');
            remove_meta_box('dashboard_secondary', 'dashboard', 'normal');
        }

        if ($this->plugin->get_setting('remove_php_nag')) {
            remove_meta_box('dashboard_php_nag', 'dashboard', 'normal');
        }

        if ($this->plugin->get_setting('remove_browser_nag')) {
            remove_meta_box('dashboard_browser_nag', 'dashboard', 'normal');
        }

        if ($this->plugin->get_setting('remove_site_health')) {
            remove_meta_box('dashboard_site_health', 'dashboard', 'normal');
        }

        if ($this->plugin->get_setting('remove_welcome_panel')) {
            remove_action('welcome_panel', 'wp_welcome_panel');
        }

        if ($this->plugin->get_setting('remove_activity')) {
            remove_meta_box('dashboard_activity', 'dashboard', 'normal');
        }

        if ($this->plugin->get_setting('remove_at_a_glance')) {
            remove_meta_box('dashboard_right_now', 'dashboard', 'normal');
        }

        // Remove custom/plugin widgets selected by user
        $hidden_widgets = $this->plugin->get_setting('hidden_dashboard_widgets');
        if (is_array($hidden_widgets) && !empty($hidden_widgets)) {
            $available = self::get_available_widgets();

            foreach ($hidden_widgets as $widget_id) {
                if (isset($available[$widget_id])) {
                    $context = $available[$widget_id]['context'];
                    remove_meta_box($widget_id, 'dashboard', $context);
                } else {
                    // Fallback: try all contexts
                    remove_meta_box($widget_id, 'dashboard', 'normal');
                    remove_meta_box($widget_id, 'dashboard', 'side');
                    remove_meta_box($widget_id, 'dashboard', 'column3');
                    remove_meta_box($widget_id, 'dashboard', 'column4');
                }
            }
        }
    }

    /**
     * Control heartbeat in admin
     *
     * @return void
     */
    public function control_heartbeat_admin(): void
    {
        if ($this->plugin->get_setting('heartbeat_exception_builder')) {
            if ($this->is_divi_builder_active()) {
                return;
            }
        }

        $admin_setting = $this->plugin->get_setting('heartbeat_admin');

        if ($admin_setting === 'disable') {
            wp_deregister_script('heartbeat');
        } elseif (is_numeric($admin_setting)) {
            add_filter('heartbeat_settings', function ($settings) use ($admin_setting) {
                $settings['interval'] = (int) $admin_setting;
                return $settings;
            });
        }
    }

    /**
     * Control heartbeat on frontend
     *
     * Runs on 'init' hook for reliable script deregistration before wp_enqueue_scripts.
     *
     * @return void
     */
    public function control_heartbeat_frontend(): void
    {
        if (is_admin()) {
            return;
        }

        if ($this->plugin->get_setting('heartbeat_exception_builder')) {
            if ($this->is_divi_builder_active()) {
                return;
            }
        }

        $frontend_setting = $this->plugin->get_setting('heartbeat_frontend');

        if ($frontend_setting === 'disable') {
            wp_deregister_script('heartbeat');
        } elseif (is_numeric($frontend_setting)) {
            add_filter('heartbeat_settings', function ($settings) use ($frontend_setting) {
                $settings['interval'] = (int) $frontend_setting;
                return $settings;
            });
        }
    }

    /**
     * Check if Divi builder is active
     *
     * @return bool
     */
    private function is_divi_builder_active(): bool
    {
        if (
            function_exists('et_builder_is_frontend_editor') &&
            et_builder_is_frontend_editor()
        ) {
            return true;
        }

        if (function_exists('et_core_is_fb_enabled') && et_core_is_fb_enabled()) {
            return true;
        }

        if (isset($_GET['et_fb'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return sanitize_text_field(wp_unslash($_GET['et_fb'])) === '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        return false;
    }
}
