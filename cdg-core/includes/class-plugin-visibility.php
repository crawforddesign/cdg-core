<?php
/**
 * Plugin Visibility
 *
 * Hides specified plugins from the WordPress admin Plugins page for all
 * users who do not have the manage_options capability. Administrators
 * always see every plugin regardless of this setting.
 *
 * @package CDG_Core
 * @since 1.5.0
 */

declare(strict_types=1);

class CDG_Core_Plugin_Visibility
{
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
        add_filter('all_plugins', [$this, 'filter_plugins_list']);
    }

    /**
     * Remove hidden plugins from the list for non-administrators.
     *
     * @param array<string, array<string, string>> $plugins Installed plugins keyed by plugin file.
     * @return array<string, array<string, string>>
     */
    public function filter_plugins_list(array $plugins): array
    {
        // Administrators always see everything.
        if (current_user_can('manage_options')) {
            return $plugins;
        }

        $hidden = (array) $this->plugin->get_setting('hidden_plugins');

        foreach ($hidden as $plugin_file) {
            unset($plugins[$plugin_file]);
        }

        return $plugins;
    }

    /**
     * Return all installed plugins for use in the settings UI.
     *
     * Loads the plugin.php helper if it hasn't been included yet (safe to
     * call from any admin context).
     *
     * @return array<string, array<string, string>>
     */
    public static function get_all_plugins(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return get_plugins();
    }
}
