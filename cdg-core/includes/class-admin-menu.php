<?php
/**
 * Admin Menu Visibility
 *
 * Hides specified admin sidebar menu items on a per-user basis.
 * Menu items are captured into a transient before any removal so the
 * settings UI always shows the full list.
 *
 * @package CDG_Core
 * @since 1.6.0
 */

declare(strict_types=1);

class CDG_Core_Admin_Menu
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

        // Capture before removal so the full list is persisted.
        add_action('admin_menu', [$this, 'capture_admin_menu'], 100);
        add_action('admin_menu', [$this, 'remove_admin_menu_items'], 999);

        // Invalidate cache when the plugin set changes.
        add_action('activated_plugin', [__CLASS__, 'invalidate_menu_cache']);
        add_action('deactivated_plugin', [__CLASS__, 'invalidate_menu_cache']);
    }

    /**
     * Persist all top-level admin menu slugs into a transient.
     *
     * @return void
     */
    public function capture_admin_menu(): void
    {
        global $menu;

        if (!is_array($menu)) {
            return;
        }

        $items = [];

        foreach ($menu as $item) {
            $slug = $item[2] ?? '';

            // Skip separators and empty slugs.
            if (empty($slug) || str_starts_with($slug, 'separator')) {
                continue;
            }

            // Strip HTML (notification bubbles, etc.) to get a clean title.
            $raw_title = $item[0] ?? $slug;
            $title     = trim(wp_strip_all_tags($raw_title));

            // Fall back to slug when stripping empties the title.
            if ($title === '') {
                $title = $slug;
            }

            $items[$slug] = [
                'slug'  => $slug,
                'title' => $title,
            ];
        }

        $existing = get_transient('cdg_admin_menu_items');
        if ($existing !== $items) {
            set_transient('cdg_admin_menu_items', $items, DAY_IN_SECONDS);
        }
    }

    /**
     * Remove menu items configured as hidden for the current user.
     *
     * @return void
     */
    public function remove_admin_menu_items(): void
    {
        $user_id  = get_current_user_id();
        $per_user = (array) $this->plugin->get_setting('hidden_menu_items_per_user');
        $hidden   = $per_user[$user_id] ?? [];

        foreach ($hidden as $slug) {
            remove_menu_page($slug);
        }
    }

    /**
     * Delete the admin menu transient so it is rebuilt on next load.
     *
     * @return void
     */
    public static function invalidate_menu_cache(): void
    {
        delete_transient('cdg_admin_menu_items');
    }

    /**
     * Return captured admin menu items for use in the settings UI.
     *
     * @return array<string, array{slug: string, title: string}>
     */
    public static function get_available_menu_items(): array
    {
        $items = get_transient('cdg_admin_menu_items');
        return is_array($items) ? $items : [];
    }
}
