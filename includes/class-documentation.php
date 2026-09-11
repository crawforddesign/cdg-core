<?php
/**
 * Documentation Class
 *
 * Provides an internal documentation system for client sites.
 *
 * @package CDG_Core
 * @since 1.0.0
 */

declare(strict_types=1);

class CDG_Core_Documentation
{
    /**
     * Post type name
     */
    public const POST_TYPE = 'cdg_documentation';

    /**
     * Taxonomy name
     */
    public const TAXONOMY = 'cdg_doc_category';

    /**
     * Default categories
     */
    public const DEFAULT_CATEGORIES = [
        'getting-started' => 'Getting Started',
        'advanced' => 'Advanced',
        'troubleshooting' => 'Troubleshooting',
    ];

    /**
     * Default dashboard column for each documentation category's widget
     * (matched by term slug). 'normal' = column 1 (main), 'side' = column 2,
     * 'column3' = column 3. Any category not listed here falls back to
     * 'normal'. Note: a 3rd/4th column only renders for users who have set
     * "Number of Columns" to 3+ under Screen Options on the Dashboard page —
     * that's a per-user WordPress preference this plugin doesn't override.
     */
    public const CATEGORY_DASHBOARD_COLUMNS = [
        'getting-started' => 'normal',
        'advanced'        => 'side',
        'troubleshooting' => 'column3',
    ];

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
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_taxonomy']);
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widgets']);
        add_action('admin_menu', [$this, 'add_viewer_pages']);
    }

    /**
     * Register documentation post type
     *
     * @return void
     */
    public function register_post_type(): void
    {
        $labels = [
            'name' => _x('Documentation', 'Post Type General Name', 'cdg-core'),
            'singular_name' => _x('Documentation', 'Post Type Singular Name', 'cdg-core'),
            'menu_name' => __('Documentation', 'cdg-core'),
            'name_admin_bar' => __('Documentation', 'cdg-core'),
            'archives' => __('Documentation Archives', 'cdg-core'),
            'all_items' => __('All Documentation', 'cdg-core'),
            'add_new_item' => __('Add New Documentation', 'cdg-core'),
            'add_new' => __('Add New', 'cdg-core'),
            'new_item' => __('New Documentation', 'cdg-core'),
            'edit_item' => __('Edit Documentation', 'cdg-core'),
            'view_item' => __('View Documentation', 'cdg-core'),
            'search_items' => __('Search Documentation', 'cdg-core'),
        ];

        $args = [
            'label' => __('Documentation', 'cdg-core'),
            'labels' => $labels,
            'supports' => ['title', 'editor', 'excerpt', 'revisions'],
            'taxonomies' => [self::TAXONOMY],
            'hierarchical' => false,
            'public' => false,
            'show_ui' => true,
            // No menu entry of its own. Articles and categories are managed
            // from the Documentation tab of CDG Core Settings, which links
            // out to these native screens — show_ui stays true so post.php /
            // post-new.php / edit.php keep working for those links.
            'show_in_menu' => false,
            'show_in_admin_bar' => true,
            'can_export' => true,
            'has_archive' => false,
            'exclude_from_search' => true,
            'publicly_queryable' => false,
            'capability_type' => 'post',
            'show_in_rest' => false,
        ];

        register_post_type(self::POST_TYPE, $args);
    }

    /**
     * Register documentation taxonomy
     *
     * @return void
     */
    public function register_taxonomy(): void
    {
        $labels = [
            'name' => _x('Doc Categories', 'Taxonomy General Name', 'cdg-core'),
            'singular_name' => _x('Doc Category', 'Taxonomy Singular Name', 'cdg-core'),
            'menu_name' => __('Categories', 'cdg-core'),
            'all_items' => __('All Categories', 'cdg-core'),
            'add_new_item' => __('Add New Category', 'cdg-core'),
            'edit_item' => __('Edit Category', 'cdg-core'),
            'search_items' => __('Search Categories', 'cdg-core'),
        ];

        $args = [
            'labels' => $labels,
            'hierarchical' => false,
            'public' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'show_in_rest' => false,
            // No menu entry, matching the post type above: categories are
            // managed from the Documentation tab of CDG Core Settings, which
            // links out to edit-tags.php. show_ui stays true so that screen
            // still renders. (This would be false in practice regardless —
            // WP core only reads a taxonomy's show_in_menu from the
            // per-post-type loop in wp-admin/menu.php, which runs only for
            // post types holding their OWN top-level menu.)
            'show_in_menu' => false,
        ];

        register_taxonomy(self::TAXONOMY, [self::POST_TYPE], $args);
    }

    /**
     * Create default categories
     *
     * @return void
     */
    public function create_default_categories(): void
    {
        // Only create if no categories exist
        $existing = get_terms([
            'taxonomy' => self::TAXONOMY,
            'hide_empty' => false,
            'number' => 1,
        ]);

        if (!is_wp_error($existing) && !empty($existing)) {
            return;
        }

        foreach (self::DEFAULT_CATEGORIES as $slug => $name) {
            if (!term_exists($slug, self::TAXONOMY)) {
                wp_insert_term($name, self::TAXONOMY, ['slug' => $slug]);
            }
        }
    }

    /**
     * Add dashboard widgets
     *
     * @return void
     */
    public function add_dashboard_widgets(): void
    {
        if (!current_user_can('edit_posts')) {
            return;
        }

        if (!$this->plugin->get_setting('show_documentation_widgets')) {
            return;
        }

        $style = $this->plugin->get_setting('documentation_module_style');

        if ($style === 'minimal') {
            wp_add_dashboard_widget(
                'cdg_documentation_minimal',
                __('Quick Documentation', 'cdg-core'),
                [$this, 'render_minimal_widget']
            );
        } else {
            // Add one widget per category
            $categories = get_terms([
                'taxonomy' => self::TAXONOMY,
                'hide_empty' => false,
            ]);

            if (!is_wp_error($categories)) {
                foreach ($categories as $category) {
                    $context = self::CATEGORY_DASHBOARD_COLUMNS[$category->slug] ?? 'normal';

                    wp_add_dashboard_widget(
                        'cdg_doc_' . $category->slug,
                        sprintf(__('Docs: %s', 'cdg-core'), $category->name),
                        [$this, 'render_category_widget'],
                        null,
                        ['category' => $category],
                        $context
                    );
                }
            }
        }
    }

    /**
     * Render minimal dashboard widget
     *
     * @return void
     */
    public function render_minimal_widget(): void
    {
        $categories = get_terms([
            'taxonomy' => self::TAXONOMY,
            'hide_empty' => false,
        ]);

        if (is_wp_error($categories) || empty($categories)) {
            echo '<p>' . esc_html__('No documentation categories found.', 'cdg-core') . '</p>';
            return;
        }

        echo '<div class="cdg-doc-buttons" style="display: flex; flex-direction: column; gap: 8px;">';
        
        foreach ($categories as $category) {
            $url = admin_url('admin.php?page=cdg-doc-category&category=' . $category->slug);
            printf(
                '<a href="%s" class="button button-primary" style="text-align: center;">%s</a>',
                esc_url($url),
                esc_html($category->name)
            );
        }
        
        echo '</div>';
    }

    /**
     * Render category dashboard widget
     *
     * @param mixed $post Post object (unused)
     * @param array $args Widget arguments
     * @return void
     */
    public function render_category_widget($post, array $args): void
    {
        $category = $args['args']['category'] ?? null;
        
        if (!$category) {
            return;
        }

        $limit = $this->plugin->get_setting('documentation_widget_limit');

        $docs = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => $limit,
            'orderby' => 'menu_order title',
            'order' => 'ASC',
            'tax_query' => [
                [
                    'taxonomy' => self::TAXONOMY,
                    'field' => 'term_id',
                    'terms' => $category->term_id,
                ],
            ],
        ]);

        if (empty($docs)) {
            printf(
                '<p>%s</p>',
                esc_html__('No documentation in this category.', 'cdg-core')
            );
            return;
        }

        echo '<ul style="margin: 0;">';
        
        foreach ($docs as $doc) {
            // The viewer is a submenu of Tools, so its hook is tools_page_* and
            // admin.php?page= won't resolve it — only admin-page-parented
            // screens (like cdg-doc-category below) work that way.
            $view_url = add_query_arg(
                ['page' => 'cdg-view-doc', 'post_id' => $doc->ID],
                admin_url('tools.php')
            );
            printf(
                '<li style="margin-bottom: 8px;"><a href="%s" class="button" style="width: 100%%; text-align: left;">%s</a></li>',
                esc_url($view_url),
                esc_html($doc->post_title)
            );
        }
        
        echo '</ul>';
    }

    /**
     * Add viewer pages
     *
     * @return void
     */
    public function add_viewer_pages(): void
    {
        // The viewer is the one documentation screen that stays in the Tools
        // menu — it's a reading surface for the whole site, not an editing
        // one, so it doesn't belong behind Settings with the rest. The list
        // and category screens moved to the Documentation tab of CDG Core
        // Settings (CDG_Core_Admin::tab_documentation()).
        add_submenu_page(
            'tools.php',
            __('View Documentation', 'cdg-core'),
            __('Documentation', 'cdg-core'),
            'edit_posts',
            'cdg-view-doc',
            [$this, 'render_viewer']
        );

        // Hidden category archive page
        add_submenu_page(
            null,
            __('Documentation Category', 'cdg-core'),
            __('Category', 'cdg-core'),
            'edit_posts',
            'cdg-doc-category',
            [$this, 'render_category_archive']
        );
    }

    /**
     * Render documentation viewer
     *
     * @return void
     */
    public function render_viewer(): void
    {
        if (!isset($_GET['post_id'])) {
            $this->render_viewer_notice(__('No documentation specified.', 'cdg-core'));
            return;
        }

        $post_id = absint($_GET['post_id']);
        $post = get_post($post_id);

        // read_post is a meta capability, so map_meta_cap resolves it against
        // the post's status and author — published docs stay readable by
        // anyone with dashboard access, drafts and private docs don't. The
        // message is deliberately identical to the wrong-post-type case so a
        // guessed ID can't confirm that a doc exists.
        if (
            !$post ||
            $post->post_type !== self::POST_TYPE ||
            !current_user_can('read_post', $post_id)
        ) {
            $this->render_viewer_notice(__('Documentation not found.', 'cdg-core'));
            return;
        }

        $terms = get_the_terms($post->ID, self::TAXONOMY);
        ?>
        <div class="wrap cdg-v2 cdg-doc-viewer">

            <a class="cdg-doc-viewer-back" href="<?php echo esc_url(admin_url()); ?>">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                <?php esc_html_e('Back to Dashboard', 'cdg-core'); ?>
            </a>

            <div class="cdg-page-header">
                <h1 class="cdg-doc-viewer-title"><?php echo esc_html($post->post_title); ?></h1>
                <div class="cdg-doc-viewer-meta">
                    <?php if (is_array($terms) && !empty($terms)): ?>
                        <?php foreach ($terms as $term): ?>
                            <span class="cdg-doc-status"><?php echo esc_html($term->name); ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <span>
                        <?php echo esc_html(
                            sprintf(
                                /* translators: %s: date the article was last modified */
                                __('Updated %s', 'cdg-core'),
                                get_the_modified_date(get_option('date_format'), $post)
                            )
                        ); ?>
                    </span>
                    <?php if (current_user_can('edit_post', $post->ID)): ?>
                        <span>
                            <a href="<?php echo esc_url((string) get_edit_post_link($post->ID, 'raw')); ?>">
                                <?php esc_html_e('Edit article', 'cdg-core'); ?>
                            </a>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="cdg-card">
                <div class="cdg-card-body">
                    <div class="cdg-doc-prose">
                        <?php echo wp_kses_post(apply_filters('the_content', $post->post_content)); ?>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }

    /**
     * Empty/error state for the viewer, in the same visual language as the
     * article view rather than a bare paragraph.
     *
     * @param string $message Already-translated message text.
     * @return void
     */
    private function render_viewer_notice(string $message): void
    {
        ?>
        <div class="wrap cdg-v2 cdg-doc-viewer">
            <div class="cdg-card">
                <div class="cdg-card-body">
                    <div class="cdg-snippets-empty"><?php echo esc_html($message); ?></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render category archive
     *
     * @return void
     */
    public function render_category_archive(): void
    {
        if (!isset($_GET['category'])) {
            $this->render_viewer_notice(__('No category specified.', 'cdg-core'));
            return;
        }

        $category_slug = sanitize_text_field(wp_unslash($_GET['category']));
        $category = get_term_by('slug', $category_slug, self::TAXONOMY);

        if (!$category) {
            $this->render_viewer_notice(__('Category not found.', 'cdg-core'));
            return;
        }

        $docs = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'menu_order title',
            'order' => 'ASC',
            'tax_query' => [
                [
                    'taxonomy' => self::TAXONOMY,
                    'field' => 'term_id',
                    'terms' => $category->term_id,
                ],
            ],
        ]);
        ?>
        <div class="wrap cdg-v2 cdg-doc-viewer">

            <a class="cdg-doc-viewer-back" href="<?php echo esc_url(admin_url()); ?>">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                <?php esc_html_e('Back to Dashboard', 'cdg-core'); ?>
            </a>

            <div class="cdg-page-header">
                <h1 class="cdg-doc-viewer-title"><?php echo esc_html($category->name); ?></h1>
                <div class="cdg-doc-viewer-meta">
                    <span><?php echo esc_html(
                        sprintf(
                            /* translators: %d: number of articles in the category */
                            _n('%d article', '%d articles', count($docs), 'cdg-core'),
                            count($docs)
                        )
                    ); ?></span>
                </div>
            </div>

            <?php if (empty($docs)): ?>
                <div class="cdg-card">
                    <div class="cdg-card-body">
                        <div class="cdg-snippets-empty">
                            <?php esc_html_e('No documentation found in this category.', 'cdg-core'); ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="cdg-doc-grid">
                    <?php foreach ($docs as $doc): ?>
                        <div class="cdg-card">
                            <div class="cdg-card-header">
                                <div class="cdg-card-title"><?php echo esc_html($doc->post_title); ?></div>
                                <?php if ($doc->post_excerpt): ?>
                                    <p class="cdg-card-desc"><?php echo esc_html($doc->post_excerpt); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="cdg-card-body">
                                <a href="<?php echo esc_url(
                                    add_query_arg(
                                        ['page' => 'cdg-view-doc', 'post_id' => $doc->ID],
                                        admin_url('tools.php')
                                    )
                                ); ?>" class="cdg-btn cdg-btn-primary cdg-btn-sm">
                                    <?php esc_html_e('Read', 'cdg-core'); ?>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>
        <?php
    }
}
