<?php
/**
 * Defaults Class
 *
 * Handles default WordPress/Divi modifications including:
 * - Disabling Comments
 * - Hiding Divi Projects
 * - Hiding the built-in Posts type
 *
 * @package CDG_Core
 * @since 1.2.0
 */

declare(strict_types=1);

class CDG_Core_Defaults
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
        // Comments
        if ($this->plugin->get_setting('disable_comments')) {
            $this->setup_disable_comments();
        }

        // Divi Projects
        if ($this->plugin->get_setting('hide_divi_projects')) {
            $this->setup_hide_divi_projects();
        }

        // Built-in Posts
        if ($this->plugin->get_setting('hide_default_posts')) {
            $this->setup_hide_default_posts();
        }
    }

    /**
     * Setup hooks to disable comments completely
     *
     * @return void
     */
    private function setup_disable_comments(): void
    {
        // Remove comment support from all post types
        add_action('init', [$this, 'remove_comment_support'], 100);

        // Close comments on frontend
        add_filter('comments_open', '__return_false', 20, 2);
        add_filter('pings_open', '__return_false', 20, 2);

        // Hide existing comments
        add_filter('comments_array', '__return_empty_array', 10, 2);
        add_filter('get_comments_number', '__return_zero');

        // Remove comments from admin menu
        add_action('admin_menu', [$this, 'remove_comments_admin_menu'], 999);

        // Remove comments meta boxes
        add_action('admin_init', [$this, 'remove_comments_meta_boxes']);

        // Remove comments from admin bar
        add_action('wp_before_admin_bar_render', [$this, 'remove_comments_admin_bar']);

        // Remove Recent Comments dashboard widget
        add_action('wp_dashboard_setup', [$this, 'remove_comments_dashboard_widget'], 999);

        // Disable comments REST API
        add_filter('rest_endpoints', [$this, 'disable_comments_rest_api']);

        // Redirect comments pages
        add_action('admin_init', [$this, 'redirect_comments_admin_pages']);

        // Remove comment-related items from admin bar on frontend
        add_action('admin_bar_menu', [$this, 'remove_comments_admin_bar_menu'], 999);

        // Disable comment feeds
        add_action('do_feed_rss2_comments', [$this, 'disable_comment_feeds'], 1);
        add_action('do_feed_atom_comments', [$this, 'disable_comment_feeds'], 1);

        // Remove X-Pingback header
        add_filter('wp_headers', [$this, 'remove_pingback_header']);

        // Remove comment rewrite rules
        add_filter('rewrite_rules_array', [$this, 'remove_comment_rewrite_rules']);
    }

    /**
     * Remove comment support from all post types
     *
     * @return void
     */
    public function remove_comment_support(): void
    {
        $post_types = get_post_types(['public' => true], 'names');

        foreach ($post_types as $post_type) {
            if (post_type_supports($post_type, 'comments')) {
                remove_post_type_support($post_type, 'comments');
                remove_post_type_support($post_type, 'trackbacks');
            }
        }
    }

    /**
     * Remove comments from admin menu
     *
     * @return void
     */
    public function remove_comments_admin_menu(): void
    {
        remove_menu_page('edit-comments.php');
        remove_submenu_page('options-general.php', 'options-discussion.php');
    }

    /**
     * Remove comments meta boxes from all post types
     *
     * @return void
     */
    public function remove_comments_meta_boxes(): void
    {
        $post_types = get_post_types(['public' => true], 'names');

        foreach ($post_types as $post_type) {
            remove_meta_box('commentstatusdiv', $post_type, 'normal');
            remove_meta_box('commentsdiv', $post_type, 'normal');
            remove_meta_box('trackbacksdiv', $post_type, 'normal');
        }
    }

    /**
     * Remove comments from admin bar
     *
     * @return void
     */
    public function remove_comments_admin_bar(): void
    {
        global $wp_admin_bar;

        if ($wp_admin_bar) {
            $wp_admin_bar->remove_menu('comments');
        }
    }

    /**
     * Remove comments admin bar menu items
     *
     * @param WP_Admin_Bar $wp_admin_bar Admin bar instance
     * @return void
     */
    public function remove_comments_admin_bar_menu($wp_admin_bar): void
    {
        $wp_admin_bar->remove_node('comments');
    }

    /**
     * Remove Recent Comments dashboard widget
     *
     * @return void
     */
    public function remove_comments_dashboard_widget(): void
    {
        remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');
    }

    /**
     * Disable comments REST API endpoints
     *
     * @param array<string, mixed> $endpoints REST API endpoints
     * @return array<string, mixed>
     */
    public function disable_comments_rest_api(array $endpoints): array
    {
        unset($endpoints['/wp/v2/comments']);
        unset($endpoints['/wp/v2/comments/(?P<id>[\d]+)']);

        return $endpoints;
    }

    /**
     * Redirect any direct access to comments admin pages
     *
     * @return void
     */
    public function redirect_comments_admin_pages(): void
    {
        global $pagenow;

        if (!$pagenow) {
            return;
        }

        $blocked_pages = [
            'edit-comments.php',
            'options-discussion.php',
        ];

        if (in_array($pagenow, $blocked_pages, true)) {
            wp_safe_redirect(admin_url());
            exit;
        }
    }

    /**
     * Disable comment feeds
     *
     * @return void
     */
    public function disable_comment_feeds(): void
    {
        wp_safe_redirect(home_url(), 301);
        exit;
    }

    /**
     * Remove X-Pingback header
     *
     * @param array<string, string> $headers HTTP headers
     * @return array<string, string>
     */
    public function remove_pingback_header(array $headers): array
    {
        unset($headers['X-Pingback']);

        return $headers;
    }

    /**
     * Remove comment-related rewrite rules
     *
     * @param array<string, string> $rules Rewrite rules
     * @return array<string, string>
     */
    public function remove_comment_rewrite_rules(array $rules): array
    {
        foreach ($rules as $rule => $rewrite) {
            if (preg_match('/comment|comments/', $rule)) {
                unset($rules[$rule]);
            }
        }

        return $rules;
    }

    /**
     * Setup hooks to hide Divi Projects
     *
     * @return void
     */
    private function setup_hide_divi_projects(): void
    {
        // Unregister the project post type
        add_action('init', [$this, 'unregister_divi_projects'], 100);

        // Remove from admin menu as backup
        add_action('admin_menu', [$this, 'remove_projects_admin_menu'], 999);

        // Redirect any direct access to projects admin pages
        add_action('admin_init', [$this, 'redirect_projects_admin_pages']);
    }

    /**
     * Unregister Divi Projects post type and taxonomies
     *
     * @return void
     */
    public function unregister_divi_projects(): void
    {
        if (post_type_exists('project')) {
            unregister_post_type('project');
        }

        if (taxonomy_exists('project_category')) {
            unregister_taxonomy('project_category');
        }

        if (taxonomy_exists('project_tag')) {
            unregister_taxonomy('project_tag');
        }
    }

    /**
     * Remove Projects from admin menu
     *
     * @return void
     */
    public function remove_projects_admin_menu(): void
    {
        remove_menu_page('edit.php?post_type=project');
    }

    /**
     * Redirect any direct access to projects admin pages
     *
     * @return void
     */
    public function redirect_projects_admin_pages(): void
    {
        global $pagenow;

        if (!$pagenow) {
            return;
        }

        $is_project_page = false;

        if (in_array($pagenow, ['edit.php', 'post-new.php', 'post.php'], true)) {
            $post_type = isset($_GET['post_type']) ? sanitize_text_field(wp_unslash($_GET['post_type'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

            if ($post_type === 'project') {
                $is_project_page = true;
            }

            // Check for editing existing project
            if ($pagenow === 'post.php' && isset($_GET['post'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $post_id = absint($_GET['post']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $post = get_post($post_id);

                if ($post && $post->post_type === 'project') {
                    $is_project_page = true;
                }
            }
        }

        // Check taxonomy pages
        if ($pagenow === 'edit-tags.php') {
            $taxonomy = isset($_GET['taxonomy']) ? sanitize_text_field(wp_unslash($_GET['taxonomy'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

            if (in_array($taxonomy, ['project_category', 'project_tag'], true)) {
                $is_project_page = true;
            }
        }

        if ($is_project_page) {
            wp_safe_redirect(admin_url());
            exit;
        }
    }

    /**
     * Setup hooks to hide the built-in Posts type
     *
     * WordPress refuses to unregister built-in post types and taxonomies, so
     * unlike Divi Projects this switches 'post' off through its registration
     * args and blocks what those args don't cover. Existing posts stay in the
     * database and come back when the setting is turned off.
     *
     * @return void
     */
    private function setup_hide_default_posts(): void
    {
        // Priority 20: after the labels set by CDG_Core_Post_Rename
        add_filter('register_post_type_args', [$this, 'deactivate_post_type_args'], 20, 2);
        add_filter('register_taxonomy_args', [$this, 'hide_post_taxonomies_from_menus'], 20, 2);

        // Admin
        add_action('admin_menu', [$this, 'remove_posts_admin_menu'], 999);
        add_action('admin_init', [$this, 'redirect_posts_admin_pages']);
        add_action('wp_dashboard_setup', [$this, 'remove_quick_draft_widget'], 999);
        add_filter('dashboard_recent_posts_query_args', [$this, 'empty_dashboard_recent_posts']);
        add_action('admin_head-index.php', [$this, 'hide_at_a_glance_post_count']);

        // Front end. Priority 1: before redirect_canonical()
        add_action('template_redirect', [$this, 'block_posts_front_end'], 1);
        add_filter('feed_links_show_posts_feed', '__return_false');
        add_filter('wp_sitemaps_taxonomies', [$this, 'remove_post_taxonomies_from_sitemap']);
        add_filter('wp_sitemaps_add_provider', [$this, 'remove_users_sitemap_provider'], 10, 2);
    }

    /**
     * Switch off the built-in Posts type
     *
     * With public off, WP_Post_Type also defaults show_ui, show_in_menu,
     * show_in_admin_bar, show_in_nav_menus, publicly_queryable and
     * exclude_from_search accordingly, so Posts drop out of search, nav menus,
     * the admin bar's New menu and the post type sitemap. show_in_rest is set
     * explicitly by core, so it has to be cleared here.
     *
     * @param mixed  $args      Post type registration args
     * @param string $post_type Post type name
     * @return array<string, mixed>
     */
    public function deactivate_post_type_args($args, $post_type): array
    {
        $args = is_array($args) ? $args : [];

        if ($post_type === 'post') {
            $args['public'] = false;
            $args['show_in_rest'] = false;
        }

        return $args;
    }

    /**
     * Remove the Categories and Tags boxes from Appearance > Menus
     *
     * @param mixed  $args     Taxonomy registration args
     * @param string $taxonomy Taxonomy name
     * @return array<string, mixed>
     */
    public function hide_post_taxonomies_from_menus($args, $taxonomy): array
    {
        $args = is_array($args) ? $args : [];

        if (in_array($taxonomy, ['category', 'post_tag'], true)) {
            $args['show_in_nav_menus'] = false;
        }

        return $args;
    }

    /**
     * Remove Posts from admin menu
     *
     * wp-admin/menu.php hardcodes this entry, so show_ui alone doesn't remove it.
     *
     * @return void
     */
    public function remove_posts_admin_menu(): void
    {
        remove_menu_page('edit.php');
    }

    /**
     * Redirect any direct access to Posts admin pages
     *
     * @return void
     */
    public function redirect_posts_admin_pages(): void
    {
        global $pagenow;

        if (!$pagenow) {
            return;
        }

        $post_type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $targets_posts = in_array($post_type, ['', 'post'], true); // No post_type means Posts on these screens.
        $is_posts_page = false;

        if (in_array($pagenow, ['edit.php', 'post-new.php'], true)) {
            $is_posts_page = $targets_posts;
        } elseif ($pagenow === 'post.php' && isset($_GET['post'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $is_posts_page = get_post_type(absint($_GET['post'])) === 'post'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        } elseif (in_array($pagenow, ['edit-tags.php', 'term.php'], true)) {
            $taxonomy = isset($_GET['taxonomy']) ? sanitize_key(wp_unslash($_GET['taxonomy'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $is_posts_page = $targets_posts && in_array($taxonomy, ['category', 'post_tag'], true);
        }

        if ($is_posts_page) {
            wp_safe_redirect(admin_url());
            exit;
        }
    }

    /**
     * Remove the Quick Draft dashboard widget, which always creates a Post
     *
     * @return void
     */
    public function remove_quick_draft_widget(): void
    {
        remove_meta_box('dashboard_quick_press', 'dashboard', 'side');
    }

    /**
     * Keep Posts out of the dashboard Activity widget
     *
     * @param array<string, mixed> $query_args Activity widget query args
     * @return array<string, mixed>
     */
    public function empty_dashboard_recent_posts(array $query_args): array
    {
        $query_args['post__in'] = [0];

        return $query_args;
    }

    /**
     * Hide the Posts count in the At a Glance dashboard widget
     *
     * Core lists 'post' unconditionally and offers no filter to remove it.
     *
     * @return void
     */
    public function hide_at_a_glance_post_count(): void
    {
        echo '<style>#dashboard_right_now .post-count{display:none}</style>';
    }

    /**
     * Serve a 404 for Posts and the archives that only list Posts
     *
     * A front page set to "Your latest posts" is left alone so that the
     * homepage of a site still configured that way doesn't become a 404.
     *
     * @return void
     */
    public function block_posts_front_end(): void
    {
        global $wp_query;

        $is_blocked = is_singular('post') || (is_home() && !is_front_page());

        if (!$is_blocked && !get_query_var('post_type')) {
            $is_blocked = is_date()
                || is_author()
                || (is_category() && $this->is_post_only_taxonomy('category'))
                || (is_tag() && $this->is_post_only_taxonomy('post_tag'))
                || (is_feed() && !is_comment_feed() && !is_archive() && !is_search());
        }

        if ($is_blocked) {
            $wp_query->set_404();
            status_header(404);
            nocache_headers();
        }
    }

    /**
     * Remove taxonomies that only belong to Posts from the sitemap
     *
     * @param array<string, WP_Taxonomy> $taxonomies Sitemap taxonomies
     * @return array<string, WP_Taxonomy>
     */
    public function remove_post_taxonomies_from_sitemap(array $taxonomies): array
    {
        foreach (['category', 'post_tag'] as $taxonomy) {
            if ($this->is_post_only_taxonomy($taxonomy)) {
                unset($taxonomies[$taxonomy]);
            }
        }

        return $taxonomies;
    }

    /**
     * Remove the users sitemap, since author archives now return a 404
     *
     * @param WP_Sitemaps_Provider|false $provider Sitemap provider
     * @param string                     $name     Provider name
     * @return WP_Sitemaps_Provider|false
     */
    public function remove_users_sitemap_provider($provider, $name)
    {
        return $name === 'users' ? false : $provider;
    }

    /**
     * Whether a taxonomy is attached to Posts and nothing else
     *
     * Categories or Tags shared with a custom post type stay available for it.
     *
     * @param string $taxonomy Taxonomy name
     * @return bool
     */
    private function is_post_only_taxonomy(string $taxonomy): bool
    {
        $object = get_taxonomy($taxonomy);

        return $object && array_diff($object->object_type, ['post']) === [];
    }
}
