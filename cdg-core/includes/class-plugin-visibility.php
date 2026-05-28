<?php
/**
 * Plugin Visibility & Sidebar Manager
 *
 * Renames and hides top-level sidebar menu entries per-user, injects custom
 * menu links, and reorders the admin sidebar per-user via the admin_menu hook.
 *
 * @package CDG_Core
 * @since 1.5.0
 */

declare(strict_types=1);

class CDG_Core_Plugin_Visibility
{
    /** @var CDG_Core */
    private CDG_Core $plugin;

    public function __construct(CDG_Core $plugin)
    {
        $this->plugin = $plugin;

        // Capture the full menu before any removals.
        add_action('admin_menu', [$this, 'capture_sidebar_menu'], 100);

        // Apply customisations at 999 (after all plugins have registered menus).
        add_action('admin_menu', [$this, 'apply_sidebar_changes'], 999);
        add_action('admin_menu', [$this, 'apply_custom_links'], 999);

        // Order runs last so it covers both built-in and custom-link entries.
        add_action('admin_menu', [$this, 'apply_menu_order'], 1000);

        // Inject a tiny inline script that sets target="_blank" on custom links.
        add_action('admin_footer', [$this, 'open_new_tab_links']);

        // Invalidate cache when the active plugin set changes.
        add_action('activated_plugin',   [self::class, 'invalidate_menu_cache']);
        add_action('deactivated_plugin', [self::class, 'invalidate_menu_cache']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Capture
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Persist all top-level admin menu items (including separators) into a
     * transient so the settings UI always has the full, unmodified list.
     */
    public function capture_sidebar_menu(): void
    {
        global $menu;
        if (!is_array($menu)) {
            return;
        }

        $items = [];

        foreach ($menu as $item) {
            $slug = $item[2] ?? '';
            if ($slug === '') {
                continue;
            }

            $is_separator = str_starts_with($slug, 'separator');
            $title        = $is_separator
                ? ''
                : trim(wp_strip_all_tags($item[0] ?? $slug));

            if ($title === '' && !$is_separator) {
                $title = $slug;
            }

            // Normalise icon: store the dashicons class-name suffix or the
            // raw value (could be an image URL or 'div' for CSS-sprites).
            $icon_raw = $item[6] ?? '';
            $icon     = preg_replace('/^dashicons-/', '', $icon_raw);

            $items[$slug] = [
                'slug'      => $slug,
                'title'     => $title,
                'icon'      => $icon,
                'separator' => $is_separator,
            ];
        }

        $existing = get_transient('cdg_sidebar_menu_items');
        if ($existing !== $items) {
            set_transient('cdg_sidebar_menu_items', $items, DAY_IN_SECONDS);
        }
    }

    /** @return array<string, array{slug:string,title:string,icon:string,separator:bool}> */
    public static function get_captured_menu_items(): array
    {
        $items = get_transient('cdg_sidebar_menu_items');
        return is_array($items) ? $items : [];
    }

    public static function invalidate_menu_cache(): void
    {
        delete_transient('cdg_sidebar_menu_items');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Rename / hide existing entries
    // ─────────────────────────────────────────────────────────────────────────

    public function apply_sidebar_changes(): void
    {
        global $menu;
        if (!is_array($menu)) {
            return;
        }

        $user_id = get_current_user_id();
        $names   = (array) $this->plugin->get_setting('sidebar_entry_names');
        $hidden  = (array) $this->plugin->get_setting('sidebar_entry_hidden');

        if (empty($names) && empty($hidden)) {
            return;
        }

        foreach ($menu as $pos => $item) {
            $slug = $item[2] ?? '';
            if ($slug === '') {
                continue;
            }

            // Rename (all users).
            if (isset($names[$slug]) && $names[$slug] !== '') {
                $menu[$pos][0] = esc_html($names[$slug]);
            }

            // Hide (per-user).
            if (isset($hidden[$slug])) {
                $uids = array_map('absint', (array) $hidden[$slug]);
                if (in_array($user_id, $uids, true)) {
                    remove_menu_page($slug);
                }
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Custom menu links
    // ─────────────────────────────────────────────────────────────────────────

    public function apply_custom_links(): void
    {
        global $menu;

        $links = (array) $this->plugin->get_setting('custom_menu_links');
        if (empty($links)) {
            return;
        }

        $user_id = get_current_user_id();
        $max_pos = !empty($menu) ? max(array_keys($menu)) : 80;

        foreach ($links as $index => $link) {
            if (!is_array($link)) {
                continue;
            }

            $id    = sanitize_key($link['id'] ?? '');
            $title = sanitize_text_field($link['title'] ?? '');
            if ($id === '' || $title === '') {
                continue;
            }

            // Visibility check.
            $hidden_for = array_map('absint', (array) ($link['hidden_for'] ?? []));
            if (in_array($user_id, $hidden_for, true)) {
                continue;
            }

            $icon   = sanitize_text_field($link['icon'] ?? 'admin-generic');
            $url    = $link['link'] ?? '#';
            $target = ($link['target'] ?? '_self') === '_blank' ? '_blank' : '_self';

            // Ensure the URL is treated as-is (not wrapped in admin_url).
            if ($url !== '#' && !preg_match('#^https?://#', $url)) {
                $url = admin_url(ltrim($url, '/'));
            }

            $slug = 'cdg_link_' . $id;
            $css  = 'menu-top toplevel_page_' . $slug .
                    ($target === '_blank' ? ' cdg-link-newtab' : '');

            $pos         = $max_pos + 2 + ($index * 2);
            $menu[$pos]  = [
                esc_html($title),
                'read',
                esc_url_raw($url),
                esc_html($title),
                $css,
                'toplevel_page_' . $slug,
                'dashicons-' . preg_replace('/^dashicons-/', '', $icon),
            ];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Per-user menu ordering
    // ─────────────────────────────────────────────────────────────────────────

    public function apply_menu_order(): void
    {
        global $menu;
        if (!is_array($menu)) {
            return;
        }

        $user_id = get_current_user_id();
        $orders  = (array) $this->plugin->get_setting('sidebar_menu_order');
        $raw     = $orders[$user_id] ?? '';
        $order   = is_array($raw)
            ? $raw
            : (array) json_decode((string) $raw, true);

        if (empty($order)) {
            return;
        }

        // Index menu by slug.
        $by_slug = [];
        foreach ($menu as $pos => $item) {
            $slug = $item[2] ?? '';
            if ($slug !== '') {
                $by_slug[$slug] = $item;
            }
        }

        $new_menu = [];
        $pos      = 2;

        // Items in the saved order first.
        foreach ($order as $slug) {
            if (isset($by_slug[$slug])) {
                $new_menu[$pos] = $by_slug[$slug];
                unset($by_slug[$slug]);
                $pos += 2;
            }
        }

        // Remaining items (not yet in saved order) appended.
        foreach ($by_slug as $item) {
            $new_menu[$pos] = $item;
            $pos += 2;
        }

        $menu = $new_menu;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // New-tab support for custom links
    // ─────────────────────────────────────────────────────────────────────────

    public function open_new_tab_links(): void
    {
        ?>
<script>
(function(){
  document.querySelectorAll('#adminmenu .cdg-link-newtab > a').forEach(function(a){
    a.target = '_blank';
    a.rel    = 'noopener noreferrer';
  });
})();
</script>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Utilities
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return all installed plugins (for use in other parts of the plugin if
     * needed — no longer used for list-page management).
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
