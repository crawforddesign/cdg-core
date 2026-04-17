<?php
/**
 * Admin Class
 *
 * Handles the settings page and admin functionality.
 *
 * @package CDG_Core
 * @since 1.0.0
 */

declare(strict_types=1);

class CDG_Core_Admin
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
    add_action("admin_menu", [$this, "add_admin_menu"]);
    add_action("admin_enqueue_scripts", [$this, "enqueue_assets"]);
    add_action("admin_init", [$this, "handle_form_submission"]);
  }

  /**
   * Add admin menu
   *
   * @return void
   */
  public function add_admin_menu(): void
  {
    add_options_page(
      __("CDG Core Settings", "cdg-core"),
      __("CDG Core", "cdg-core"),
      "manage_options",
      "cdg-core-settings",
      [$this, "render_settings_page"]
    );
  }

  /**
   * Enqueue admin assets
   *
   * @param string $hook Current admin page hook
   * @return void
   */
  public function enqueue_assets(string $hook): void
  {
    if ($hook !== "settings_page_cdg-core-settings") {
      return;
    }

    wp_enqueue_style(
      "cdg-core-admin",
      CDG_CORE_URL . "admin/css/admin-style.css",
      [],
      CDG_CORE_VERSION
    );
    wp_enqueue_script(
      "cdg-core-admin",
      CDG_CORE_URL . "admin/js/admin-script.js",
      ["jquery"],
      CDG_CORE_VERSION,
      true
    );
  }

  /**
   * Handle form submission
   *
   * @return void
   */
  public function handle_form_submission(): void
  {
    if (!isset($_POST["cdg_core_save_settings"])) {
      return;
    }

    if (
      !isset($_POST["cdg_core_nonce"]) ||
      !wp_verify_nonce($_POST["cdg_core_nonce"], "cdg_core_settings")
    ) {
      wp_die(__("Security check failed.", "cdg-core"));
    }

    if (!current_user_can("manage_options")) {
      wp_die(__("Permission denied.", "cdg-core"));
    }

    $settings = $this->sanitize_settings($_POST);
    $this->plugin->update_settings($settings);
    flush_rewrite_rules();

    // Redirect back with success message
    $redirect_url = admin_url("options-general.php?page=cdg-core-settings");
    if (isset($_POST["cdg_core_tab"])) {
      $redirect_url = add_query_arg(
        "tab",
        sanitize_text_field($_POST["cdg_core_tab"]),
        $redirect_url
      );
    }
    $redirect_url = add_query_arg("settings-updated", "true", $redirect_url);

    wp_safe_redirect($redirect_url);
    exit();
  }

  /**
   * Sanitize settings from form input
   *
   * @param array $input Form input data
   * @return array Sanitized settings
   */
  private function sanitize_settings(array $input): array
  {
    $s = $this->plugin->get_settings();
    $tab = sanitize_text_field($input["cdg_core_tab"] ?? "features");

    switch ($tab) {
      case "features":
        // Documentation
        $s["enable_documentation"] = !empty($input["enable_documentation"]);
        $s["show_documentation_widgets"] = !empty(
          $input["show_documentation_widgets"]
        );
        $s["documentation_module_style"] = sanitize_text_field(
          $input["documentation_module_style"] ?? "informative"
        );
        $s["documentation_widget_limit"] = absint(
          $input["documentation_widget_limit"] ?? 5
        );

        // CPT Widgets
        $s["enable_cpt_widgets"] = !empty($input["enable_cpt_widgets"]);
        $s["show_cpt_widgets"] = !empty($input["show_cpt_widgets"]);
        $s["cpt_module_style"] = sanitize_text_field(
          $input["cpt_module_style"] ?? "informative"
        );
        $s["selected_cpts"] = array_map(
          "sanitize_text_field",
          (array) ($input["selected_cpts"] ?? [])
        );
        $s["show_recent_posts"] = !empty($input["show_recent_posts"]);
        $s["recent_posts_limit"] = absint($input["recent_posts_limit"] ?? 3);
        break;

      case "defaults":
        $s["disable_comments"] = !empty($input["disable_comments"]);
        $s["hide_divi_projects"] = !empty($input["hide_divi_projects"]);
        break;

      case "cleanup":
        // WordPress Head Cleanup
        $s["remove_wp_version"] = !empty($input["remove_wp_version"]);
        $s["remove_wlw_manifest"] = !empty($input["remove_wlw_manifest"]);
        $s["remove_rsd_link"] = !empty($input["remove_rsd_link"]);
        $s["remove_shortlink"] = !empty($input["remove_shortlink"]);
        $s["remove_adjacent_posts"] = !empty($input["remove_adjacent_posts"]);
        $s["remove_oembed_links"] = !empty($input["remove_oembed_links"]);
        $s["remove_rest_api_link"] = !empty($input["remove_rest_api_link"]);
        $s["disable_emojis"] = !empty($input["disable_emojis"]);

        // Dashboard Widgets
        $s["remove_quick_draft"] = !empty($input["remove_quick_draft"]);
        $s["remove_wp_news"] = !empty($input["remove_wp_news"]);
        $s["remove_php_nag"] = !empty($input["remove_php_nag"]);
        $s["remove_browser_nag"] = !empty($input["remove_browser_nag"]);
        $s["remove_site_health"] = !empty($input["remove_site_health"]);
        $s["remove_welcome_panel"] = !empty($input["remove_welcome_panel"]);
        $s["remove_activity"] = !empty($input["remove_activity"]);
        $s["remove_at_a_glance"] = !empty($input["remove_at_a_glance"]);
        $s["hidden_dashboard_widgets"] = array_map(
          "sanitize_text_field",
          (array) ($input["hidden_dashboard_widgets"] ?? [])
        );

        // Heartbeat
        $s["heartbeat_admin"] = sanitize_text_field(
          $input["heartbeat_admin"] ?? "default"
        );
        $s["heartbeat_frontend"] = sanitize_text_field(
          $input["heartbeat_frontend"] ?? "disable"
        );
        $s["heartbeat_exception_builder"] = !empty(
          $input["heartbeat_exception_builder"]
        );
        break;

      case "security":
        $s["disable_xmlrpc"] = !empty($input["disable_xmlrpc"]);
        $s["block_dangerous_uploads"] = !empty(
          $input["block_dangerous_uploads"]
        );
        $s["remove_powered_by"] = !empty($input["remove_powered_by"]);
        $s["disable_code_editor"] = !empty($input["disable_code_editor"]);
        $s["enable_svg_uploads"] = !empty($input["enable_svg_uploads"]);
        $s["svg_admin_only"] = !empty($input["svg_admin_only"]);
        $s["enable_font_uploads"] = !empty($input["enable_font_uploads"]);
        $s["font_admin_only"] = !empty($input["font_admin_only"]);
        $s["enable_lottie_uploads"] = !empty($input["enable_lottie_uploads"]);
        $s["lottie_admin_only"] = !empty($input["lottie_admin_only"]);
        break;

      case "performance":
        $s["gutenberg_mode"] = sanitize_text_field(
          $input["gutenberg_mode"] ?? "optimize"
        );
        $s["optimize_search"] = !empty($input["optimize_search"]);
        $s["optimize_archives"] = !empty($input["optimize_archives"]);
        $s["enable_lazy_loading"] = !empty($input["enable_lazy_loading"]);
        $s["disabled_image_sizes"] = array_map(
          "sanitize_text_field",
          (array) ($input["disabled_image_sizes"] ?? [])
        );
        $s["remove_medium_large"] = !empty($input["remove_medium_large"]);
        $s["post_revisions_mode"] = sanitize_text_field(
          $input["post_revisions_mode"] ?? "limited"
        );
        $s["post_revisions_limit"] = absint(
          $input["post_revisions_limit"] ?? 5
        );
        $s["remove_dns_prefetch"] = !empty($input["remove_dns_prefetch"]);
        break;

      case "gravity-forms":
        $s["enable_gf_fixes"] = !empty($input["enable_gf_fixes"]);
        $s["gf_detection_mode"] = sanitize_text_field(
          $input["gf_detection_mode"] ?? "auto"
        );
        $manual_pages = sanitize_textarea_field(
          $input["gf_manual_pages"] ?? ""
        );
        $s["gf_manual_pages"] = array_filter(
          array_map("trim", explode("\n", $manual_pages))
        );
        break;

      case "admin":
        $s["enable_admin_branding"] = !empty($input["enable_admin_branding"]);
        $s["admin_footer_text"] = wp_kses_post(
          $input["admin_footer_text"] ?? ""
        );
        $s["custom_admin_css"] = wp_strip_all_tags(
          $input["custom_admin_css"] ?? ""
        );
        break;
    }

    return $s;
  }

  /**
   * Render settings page
   *
   * @return void
   */
  public function render_settings_page(): void
  {
    if (!current_user_can("manage_options")) {
      return;
    }

    if (
      isset($_GET["settings-updated"]) &&
      $_GET["settings-updated"] === "true"
    ) {
      echo '<div class="notice notice-success is-dismissible"><p>' .
        esc_html__("Settings saved.", "cdg-core") .
        "</p></div>";
    }

    $settings = $this->plugin->get_settings();
    $active_tab = sanitize_text_field($_GET["tab"] ?? "features");
    $tabs = [
      "features" => __("Features", "cdg-core"),
      "defaults" => __("Defaults", "cdg-core"),
      "cleanup" => __("WordPress Cleanup", "cdg-core"),
      "security" => __("Security", "cdg-core"),
      "performance" => __("Performance", "cdg-core"),
      "gravity-forms" => __("Gravity Forms", "cdg-core"),
      "admin" => __("Admin", "cdg-core"),
    ];
    ?>
        <div class="wrap cdg-core-settings">
            <h1><?php echo esc_html(
              get_admin_page_title()
            ); ?> <small>v<?php echo esc_html(CDG_CORE_VERSION); ?></small></h1>

            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $id => $name): ?>
                    <a href="<?php echo esc_url(
                      admin_url(
                        "options-general.php?page=cdg-core-settings&tab=" . $id
                      )
                    ); ?>"
                       class="nav-tab <?php echo $active_tab === $id
                         ? "nav-tab-active"
                         : ""; ?>">
                        <?php echo esc_html($name); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <form method="post" action="<?php echo esc_url(
              admin_url("options-general.php?page=cdg-core-settings")
            ); ?>">
                <?php wp_nonce_field("cdg_core_settings", "cdg_core_nonce"); ?>
                <input type="hidden" name="cdg_core_tab" value="<?php echo esc_attr(
                  $active_tab
                ); ?>">
                <?php $this->render_tab($active_tab, $settings); ?>
                <p class="submit">
                    <input type="submit" name="cdg_core_save_settings" class="button button-primary" value="<?php esc_attr_e(
                      "Save Changes",
                      "cdg-core"
                    ); ?>">
                </p>
            </form>
        </div>
        <?php
  }

  /**
   * Render the active tab content
   *
   * @param string $tab Active tab ID
   * @param array $s Current settings
   * @return void
   */
  private function render_tab(string $tab, array $s): void
  {
    switch ($tab) {
      case "features":
        $this->tab_features($s);
        break;
      case "defaults":
        $this->tab_defaults($s);
        break;
      case "cleanup":
        $this->tab_cleanup($s);
        break;
      case "security":
        $this->tab_security($s);
        break;
      case "performance":
        $this->tab_performance($s);
        break;
      case "gravity-forms":
        $this->tab_gravity_forms($s);
        break;
      case "admin":
        $this->tab_admin($s);
        break;
    }
  }

  /**
   * Render Features tab
   *
   * @param array $s Current settings
   * @return void
   */
  private function tab_features(array $s): void
  {
    ?>
        <h2><?php esc_html_e("Core Features", "cdg-core"); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e(
                  "Documentation System",
                  "cdg-core"
                ); ?></th>
                <td>
                    <label><input type="checkbox" name="enable_documentation" value="1" <?php checked(
                      $s["enable_documentation"]
                    ); ?>> <?php esc_html_e("Enable internal documentation", "cdg-core"); ?></label>
                    <div style="margin: 10px 0 0 24px;">
                        <label><input type="checkbox" name="show_documentation_widgets" value="1" <?php checked(
                          $s["show_documentation_widgets"]
                        ); ?>> <?php esc_html_e("Show dashboard widgets", "cdg-core"); ?></label><br><br>
                        <strong><?php esc_html_e(
                          "Widget Style:",
                          "cdg-core"
                        ); ?></strong><br>
                        <label><input type="radio" name="documentation_module_style" value="informative" <?php checked(
                          $s["documentation_module_style"],
                          "informative"
                        ); ?>> <?php esc_html_e("Informative (per category)", "cdg-core"); ?></label><br>
                        <label><input type="radio" name="documentation_module_style" value="minimal" <?php checked(
                          $s["documentation_module_style"],
                          "minimal"
                        ); ?>> <?php esc_html_e("Minimal (single widget)", "cdg-core"); ?></label><br><br>
                        <label><?php esc_html_e(
                          "Docs per widget:",
                          "cdg-core"
                        ); ?> <input type="number" name="documentation_widget_limit" value="<?php echo esc_attr($s["documentation_widget_limit"]); ?>" min="1" max="20" style="width: 60px;"></label>
                    </div>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e("CPT Widgets", "cdg-core"); ?></th>
                <td>
                    <label><input type="checkbox" name="enable_cpt_widgets" value="1" <?php checked(
                      $s["enable_cpt_widgets"]
                    ); ?>> <?php esc_html_e("Enable CPT dashboard widgets", "cdg-core"); ?></label>
                    <div style="margin: 10px 0 0 24px;">
                        <label><input type="checkbox" name="show_cpt_widgets" value="1" <?php checked(
                          $s["show_cpt_widgets"]
                        ); ?>> <?php esc_html_e("Show on dashboard", "cdg-core"); ?></label><br><br>
                        <strong><?php esc_html_e(
                          "Widget Style:",
                          "cdg-core"
                        ); ?></strong><br>
                        <label><input type="radio" name="cpt_module_style" value="informative" <?php checked(
                          $s["cpt_module_style"],
                          "informative"
                        ); ?>> <?php esc_html_e("Informative (per post type with stats)", "cdg-core"); ?></label><br>
                        <label><input type="radio" name="cpt_module_style" value="minimal" <?php checked(
                          $s["cpt_module_style"],
                          "minimal"
                        ); ?>> <?php esc_html_e("Minimal (single quick-add widget)", "cdg-core"); ?></label><br><br>
                        <strong><?php esc_html_e(
                          "Select Post Types:",
                          "cdg-core"
                        ); ?></strong><br>
                        <?php
                        $available_cpts = CDG_Core_CPT_Dashboard::get_available_post_types();
                        if (empty($available_cpts)): ?>
                            <p class="description"><?php esc_html_e(
                              "No custom post types available.",
                              "cdg-core"
                            ); ?></p>
                        <?php else:foreach ($available_cpts as $pt): ?>
                                <label style="display:block;margin:3px 0;">
                                    <input type="checkbox" name="selected_cpts[]" value="<?php echo esc_attr(
                                      $pt->name
                                    ); ?>" <?php checked(
  in_array($pt->name, $s["selected_cpts"] ?? [], true)
); ?>>
                                    <?php echo esc_html($pt->labels->name); ?>
                                </label>
                            <?php endforeach;endif;
                        ?>
                        <br>
                        <label><input type="checkbox" name="show_recent_posts" value="1" <?php checked(
                          $s["show_recent_posts"]
                        ); ?>> <?php esc_html_e("Show recent posts in widgets", "cdg-core"); ?></label><br>
                        <label><?php esc_html_e(
                          "Recent posts to show:",
                          "cdg-core"
                        ); ?> <input type="number" name="recent_posts_limit" value="<?php echo esc_attr($s["recent_posts_limit"]); ?>" min="1" max="10" style="width: 60px;"></label>
                    </div>
                </td>
            </tr>
        </table>
        <?php
  }

  /**
   * Render Defaults tab
   *
   * @param array $s Current settings
   * @return void
   */
  private function tab_defaults(array $s): void
  {
    ?>
        <h2><?php esc_html_e("WordPress Comments", "cdg-core"); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e("Disable Comments", "cdg-core"); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="disable_comments" value="1" <?php checked(
                          $s["disable_comments"]
                        ); ?>>
                        <?php esc_html_e(
                          "Completely disable WordPress comments",
                          "cdg-core"
                        ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e(
                          "This will remove comments from all post types, hide the Comments menu, disable the Discussion settings page, and block access to comment-related admin pages.",
                          "cdg-core"
                        ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e("Divi Projects", "cdg-core"); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e("Hide Projects", "cdg-core"); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="hide_divi_projects" value="1" <?php checked(
                          $s["hide_divi_projects"]
                        ); ?>>
                        <?php esc_html_e(
                          "Hide Divi Projects post type",
                          "cdg-core"
                        ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e(
                          "This will unregister Divi's built-in Projects post type and its taxonomies (Project Categories and Tags).",
                          "cdg-core"
                        ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
  }

  /**
   * Render WordPress Cleanup tab
   *
   * @param array $s Current settings
   * @return void
   */
  private function tab_cleanup(array $s): void
  {
    ?>
        <h2><?php esc_html_e("WordPress Head Cleanup", "cdg-core"); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e("Remove from Head", "cdg-core"); ?></th>
                <td>
                    <?php foreach (
                      [
                        "remove_wp_version" => "WordPress version",
                        "remove_wlw_manifest" => "WLW Manifest",
                        "remove_rsd_link" => "RSD link",
                        "remove_shortlink" => "Shortlink",
                        "remove_adjacent_posts" => "Adjacent posts",
                        "remove_oembed_links" => "oEmbed links",
                        "remove_rest_api_link" => "REST API link",
                        "disable_emojis" => "WordPress emojis",
                      ]
                      as $key => $label
                    ): ?>
                        <label style="display:block;margin:3px 0;">
                            <input type="checkbox" name="<?php echo esc_attr(
                              $key
                            ); ?>" value="1" <?php checked($s[$key]); ?>>
                            <?php echo esc_html($label); ?>
                        </label>
                    <?php endforeach; ?>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e("Dashboard Widgets", "cdg-core"); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e("WordPress Widgets", "cdg-core"); ?></th>
                <td>
                    <?php foreach (
                      [
                        "remove_welcome_panel" => "Welcome Panel",
                        "remove_at_a_glance" => "At a Glance",
                        "remove_activity" => "Activity",
                        "remove_quick_draft" => "Quick Draft",
                        "remove_wp_news" => "WordPress Events and News",
                        "remove_site_health" => "Site Health Status",
                        "remove_php_nag" => "PHP Update Nag",
                        "remove_browser_nag" => "Browser Nag (legacy)",
                      ]
                      as $key => $label
                    ): ?>
                        <label style="display:block;margin:3px 0;">
                            <input type="checkbox" name="<?php echo esc_attr(
                              $key
                            ); ?>" value="1" <?php checked($s[$key]); ?>>
                            <?php echo esc_html($label); ?>
                        </label>
                    <?php endforeach; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e("Plugin Widgets", "cdg-core"); ?></th>
                <td>
                    <?php
                    $plugin_widgets = CDG_Core_Cleanup::get_available_widgets();
                    $hidden_widgets = $s["hidden_dashboard_widgets"] ?? [];

                    $core_widgets = [
                      "dashboard_quick_press",
                      "dashboard_primary",
                      "dashboard_secondary",
                      "dashboard_php_nag",
                      "dashboard_browser_nag",
                      "dashboard_site_health",
                      "dashboard_activity",
                      "dashboard_right_now",
                    ];

                    $plugin_widgets = array_filter($plugin_widgets, function (
                      $widget
                    ) use ($core_widgets) {
                      return !in_array($widget["id"], $core_widgets, true);
                    });

                    if (empty($plugin_widgets)): ?>
                        <p class="description">
                            <?php esc_html_e(
                              "No plugin widgets detected yet. Visit the Dashboard once to populate this list.",
                              "cdg-core"
                            ); ?>
                        </p>
                    <?php else: ?>
                        <?php foreach ($plugin_widgets as $widget): ?>
                            <label style="display:block;margin:3px 0;">
                                <input type="checkbox" name="hidden_dashboard_widgets[]" value="<?php echo esc_attr(
                                  $widget["id"]
                                ); ?>" <?php checked(
  in_array($widget["id"], $hidden_widgets, true)
); ?>>
                                <?php echo esc_html($widget["title"]); ?>
                                <code style="font-size: 11px; color: #666;"><?php echo esc_html(
                                  $widget["id"]
                                ); ?></code>
                            </label>
                        <?php endforeach; ?>
                        <p class="description" style="margin-top: 10px;">
                            <?php esc_html_e(
                              "These widgets were detected from other plugins. Check to hide them.",
                              "cdg-core"
                            ); ?>
                        </p>
                    <?php endif;
                    ?>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e("Heartbeat Control", "cdg-core"); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e("Admin", "cdg-core"); ?></th>
                <td>
                    <?php foreach (
                      [
                        "default" => "WordPress Default",
                        "60" => "60 seconds (recommended)",
                        "120" => "120 seconds",
                        "disable" => "Disabled",
                      ]
                      as $val => $label
                    ): ?>
                        <label style="display:block;">
                            <input type="radio" name="heartbeat_admin" value="<?php echo esc_attr(
                              $val
                            ); ?>" <?php checked(
  $s["heartbeat_admin"],
  $val
); ?>>
                            <?php echo esc_html($label); ?>
                        </label>
                    <?php endforeach; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e("Frontend", "cdg-core"); ?></th>
                <td>
                    <?php foreach (
                      [
                        "default" => "WordPress Default",
                        "120" => "120 seconds",
                        "disable" => "Disabled (recommended)",
                      ]
                      as $val => $label
                    ): ?>
                        <label style="display:block;">
                            <input type="radio" name="heartbeat_frontend" value="<?php echo esc_attr(
                              $val
                            ); ?>" <?php checked(
  $s["heartbeat_frontend"],
  $val
); ?>>
                            <?php echo esc_html($label); ?>
                        </label>
                    <?php endforeach; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e("Exceptions", "cdg-core"); ?></th>
                <td>
                    <label style="display:block;">
                        <input type="checkbox" name="heartbeat_exception_builder" value="1" <?php checked(
                          $s["heartbeat_exception_builder"]
                        ); ?>>
                        <?php esc_html_e("Divi Visual Builder", "cdg-core"); ?>
                    </label>
                    <p class="description"><?php esc_html_e(
                      "When enabled, heartbeat will not be disabled while using the Divi Visual Builder.",
                      "cdg-core"
                    ); ?></p>
                </td>
            </tr>
        </table>
        <?php
  }

  /**
   * Render Security tab
   *
   * @param array $s Current settings
   * @return void
   */
  private function tab_security(array $s): void
  {
    ?>
        <h2><?php esc_html_e("Security Hardening", "cdg-core"); ?></h2>
        <p class="description"><?php esc_html_e(
          "Complements Wordfence and SpinupWP. Security headers (X-Frame-Options, HSTS, X-XSS-Protection, X-Content-Type-Options) are handled by SpinupWP at the Nginx level.",
          "cdg-core"
        ); ?></p>
        <table class="form-table">
            <?php foreach (
              [
                "disable_xmlrpc" => [
                  "Disable XML-RPC",
                  "Common attack vector used for brute-force attacks",
                ],
                "block_dangerous_uploads" => [
                  "Block dangerous uploads",
                  "Prevents .exe, .php, .js and other executable files",
                ],
                "remove_powered_by" => [
                  "Remove X-Powered-By",
                  "Hides server information",
                ],
                "disable_code_editor" => [
                  "Disable code editor",
                  "For non-administrators only",
                ],
              ]
              as $key => [$label, $desc]
            ): ?>
                <tr>
                    <th><?php echo esc_html($label); ?></th>
                    <td>
                        <label><input type="checkbox" name="<?php echo esc_attr(
                          $key
                        ); ?>" value="1" <?php checked(
  $s[$key]
); ?>> <?php esc_html_e("Enable", "cdg-core"); ?></label>
                        <p class="description"><?php echo esc_html(
                          $desc
                        ); ?></p>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <h2><?php esc_html_e("SVG Upload Support", "cdg-core"); ?></h2>
        <p class="description"><?php esc_html_e(
          "Allow SVG file uploads. Use with caution as SVGs can contain malicious code.",
          "cdg-core"
        ); ?></p>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e("Enable SVG Uploads", "cdg-core"); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="enable_svg_uploads" value="1" <?php checked(
                          $s["enable_svg_uploads"]
                        ); ?>>
                        <?php esc_html_e(
                          "Allow SVG file uploads",
                          "cdg-core"
                        ); ?>
                    </label>
                    <p class="description"><?php esc_html_e(
                      "When enabled, SVG files can be uploaded through the Media Library.",
                      "cdg-core"
                    ); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e("Restrict to Admins", "cdg-core"); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="svg_admin_only" value="1" <?php checked(
                          $s["svg_admin_only"]
                        ); ?>>
                        <?php esc_html_e(
                          "Only allow administrators to upload SVGs",
                          "cdg-core"
                        ); ?>
                    </label>
                    <p class="description"><?php esc_html_e(
                      "When enabled, only users with the manage_options capability (administrators) can upload SVG files.",
                      "cdg-core"
                    ); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e("Font Upload Support", "cdg-core"); ?></h2>
        <p class="description"><?php esc_html_e(
          "Allow custom font file uploads (OTF, TTF, WOFF, WOFF2) for use with Divi or custom CSS.",
          "cdg-core"
        ); ?></p>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e("Enable Font Uploads", "cdg-core"); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="enable_font_uploads" value="1" <?php checked(
                          $s["enable_font_uploads"]
                        ); ?>>
                        <?php esc_html_e(
                          "Allow font file uploads",
                          "cdg-core"
                        ); ?>
                    </label>
                    <p class="description"><?php esc_html_e(
                      "When enabled, OTF, TTF, WOFF, and WOFF2 files can be uploaded through the Media Library.",
                      "cdg-core"
                    ); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e("Restrict to Admins", "cdg-core"); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="font_admin_only" value="1" <?php checked(
                          $s["font_admin_only"]
                        ); ?>>
                        <?php esc_html_e(
                          "Only allow administrators to upload font files",
                          "cdg-core"
                        ); ?>
                    </label>
                    <p class="description"><?php esc_html_e(
                      "When enabled, only users with the manage_options capability (administrators) can upload font files.",
                      "cdg-core"
                    ); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e("Lottie Upload Support", "cdg-core"); ?></h2>
        <p class="description"><?php esc_html_e(
          "Allow Lottie animation (.json, .lottie) file uploads for use with Divi or animation libraries.",
          "cdg-core"
        ); ?></p>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e(
                  "Enable Lottie Uploads",
                  "cdg-core"
                ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="enable_lottie_uploads" value="1" <?php checked(
                          $s["enable_lottie_uploads"]
                        ); ?>>
                        <?php esc_html_e(
                          "Allow Lottie/JSON file uploads",
                          "cdg-core"
                        ); ?>
                    </label>
                    <p class="description"><?php esc_html_e(
                      "When enabled, .json and .lottie files can be uploaded through the Media Library.",
                      "cdg-core"
                    ); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e("Restrict to Admins", "cdg-core"); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="lottie_admin_only" value="1" <?php checked(
                          $s["lottie_admin_only"]
                        ); ?>>
                        <?php esc_html_e(
                          "Only allow administrators to upload Lottie files",
                          "cdg-core"
                        ); ?>
                    </label>
                    <p class="description"><?php esc_html_e(
                      "When enabled, only users with the manage_options capability (administrators) can upload Lottie/JSON files.",
                      "cdg-core"
                    ); ?></p>
                </td>
            </tr>
        </table>
        <?php
  }

  /**
   * Render Performance tab
   *
   * @param array $s Current settings
   * @return void
   */
  private function tab_performance(array $s): void
  {
    ?>
        <h2><?php esc_html_e("Gutenberg", "cdg-core"); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e("Mode", "cdg-core"); ?></th>
                <td>
                    <?php foreach (
                      [
                        "default" => "WordPress defaults",
                        "optimize" =>
                          "Remove CSS/JS on non-block pages (recommended)",
                        "disable" => "Disable block editor",
                      ]
                      as $val => $label
                    ): ?>
                        <label style="display:block;">
                            <input type="radio" name="gutenberg_mode" value="<?php echo esc_attr(
                              $val
                            ); ?>" <?php checked(
  $s["gutenberg_mode"],
  $val
); ?>>
                            <?php echo esc_html($label); ?>
                        </label>
                    <?php endforeach; ?>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e("Query Optimizations", "cdg-core"); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e("Optimize", "cdg-core"); ?></th>
                <td>
                    <label style="display:block;"><input type="checkbox" name="optimize_search" value="1" <?php checked(
                      $s["optimize_search"]
                    ); ?>> <?php esc_html_e("Search queries", "cdg-core"); ?></label>
                    <label style="display:block;"><input type="checkbox" name="optimize_archives" value="1" <?php checked(
                      $s["optimize_archives"]
                    ); ?>> <?php esc_html_e("Archive queries", "cdg-core"); ?></label>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e("Images", "cdg-core"); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e("Lazy Loading", "cdg-core"); ?></th>
                <td><label><input type="checkbox" name="enable_lazy_loading" value="1" <?php checked(
                  $s["enable_lazy_loading"]
                ); ?>> <?php esc_html_e("Native lazy loading with aspect ratio (CLS)", "cdg-core"); ?></label></td>
            </tr>
            <tr>
                <th><?php esc_html_e("Disable Sizes", "cdg-core"); ?></th>
                <td>
                    <?php
                    $sizes = CDG_Core_Performance::get_available_image_sizes_static();
                    $disabled = $s["disabled_image_sizes"] ?? [];
                    foreach ($sizes as $name => $data): ?>
                        <label style="display:block;margin:2px 0;">
                            <input type="checkbox" name="disabled_image_sizes[]" value="<?php echo esc_attr(
                              $name
                            ); ?>" <?php checked(
  in_array($name, $disabled, true)
); ?>>
                            <?php echo esc_html(
                              "$name ({$data["width"]}×{$data["height"]})"
                            ); ?>
                        </label>
                    <?php endforeach;
                    ?>
                    <br><label><input type="checkbox" name="remove_medium_large" value="1" <?php checked(
                      $s["remove_medium_large"]
                    ); ?>> <?php esc_html_e("Always remove medium_large", "cdg-core"); ?></label>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e("Post Revisions", "cdg-core"); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e("Limit", "cdg-core"); ?></th>
                <td>
                    <label style="display:block;"><input type="radio" name="post_revisions_mode" value="unlimited" <?php checked(
                      $s["post_revisions_mode"],
                      "unlimited"
                    ); ?>> <?php esc_html_e("Unlimited", "cdg-core"); ?></label>
                    <label style="display:block;"><input type="radio" name="post_revisions_mode" value="disabled" <?php checked(
                      $s["post_revisions_mode"],
                      "disabled"
                    ); ?>> <?php esc_html_e("Disabled (no revisions saved)", "cdg-core"); ?></label>
                    <label style="display:block;"><input type="radio" name="post_revisions_mode" value="limited" <?php checked(
                      $s["post_revisions_mode"],
                      "limited"
                    ); ?>> <?php esc_html_e("Limited:", "cdg-core"); ?> <input type="number" name="post_revisions_limit" value="<?php echo esc_attr($s["post_revisions_limit"]); ?>" min="1" max="100" style="width:60px;"> <?php esc_html_e("revisions per post", "cdg-core"); ?></label>
                    <p class="description"><?php esc_html_e(
                      "Controls how many revisions WordPress keeps when saving posts and pages.",
                      "cdg-core"
                    ); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e("DNS", "cdg-core"); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e("Prefetch", "cdg-core"); ?></th>
                <td><label><input type="checkbox" name="remove_dns_prefetch" value="1" <?php checked(
                  $s["remove_dns_prefetch"]
                ); ?>> <?php esc_html_e("Remove s.w.org DNS prefetch", "cdg-core"); ?></label></td>
            </tr>
        </table>
        <?php
  }

  /**
   * Render Gravity Forms tab
   *
   * @param array $s Current settings
   * @return void
   */
  private function tab_gravity_forms(array $s): void
  {
    ?>
        <h2><?php esc_html_e(
          "Gravity Forms / Divi Compatibility",
          "cdg-core"
        ); ?></h2>
        <?php if (!class_exists("GFForms")): ?>
            <div class="notice notice-info inline"><p><?php esc_html_e(
              "Gravity Forms not active. Settings will apply when activated.",
              "cdg-core"
            ); ?></p></div>
        <?php endif; ?>
        <p class="description"><?php esc_html_e(
          'Fixes "gf_global is not defined" errors caused by Divi script optimization.',
          "cdg-core"
        ); ?></p>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e("Enable", "cdg-core"); ?></th>
                <td><label><input type="checkbox" name="enable_gf_fixes" value="1" <?php checked(
                  $s["enable_gf_fixes"]
                ); ?>> <?php esc_html_e("Enable compatibility fixes", "cdg-core"); ?></label></td>
            </tr>
            <tr>
                <th><?php esc_html_e("Detection", "cdg-core"); ?></th>
                <td>
                    <label style="display:block;"><input type="radio" name="gf_detection_mode" value="auto" <?php checked(
                      $s["gf_detection_mode"],
                      "auto"
                    ); ?>> <?php esc_html_e("Auto-detect (recommended)", "cdg-core"); ?></label>
                    <label style="display:block;"><input type="radio" name="gf_detection_mode" value="manual" <?php checked(
                      $s["gf_detection_mode"],
                      "manual"
                    ); ?>> <?php esc_html_e("Manual only", "cdg-core"); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e("Additional Pages", "cdg-core"); ?></th>
                <td>
                    <textarea name="gf_manual_pages" rows="4" class="large-text code" placeholder="contact&#10;submit-form"><?php echo esc_textarea(
                      implode("\n", $s["gf_manual_pages"] ?? [])
                    ); ?></textarea>
                    <p class="description"><?php esc_html_e(
                      "Page slugs, one per line. Always applies fixes to these.",
                      "cdg-core"
                    ); ?></p>
                </td>
            </tr>
        </table>
        <?php
  }

  /**
   * Render Admin tab
   *
   * @param array $s Current settings
   * @return void
   */
  private function tab_admin(array $s): void
  {
    ?>
        <h2><?php esc_html_e("Admin Branding", "cdg-core"); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e("Enable", "cdg-core"); ?></th>
                <td><label><input type="checkbox" name="enable_admin_branding" value="1" <?php checked(
                  $s["enable_admin_branding"]
                ); ?>> <?php esc_html_e("Show custom footer", "cdg-core"); ?></label></td>
            </tr>
            <tr>
                <th><?php esc_html_e("Footer Text", "cdg-core"); ?></th>
                <td>
                    <input type="text" name="admin_footer_text" value="<?php echo esc_attr(
                      $s["admin_footer_text"]
                    ); ?>" class="large-text">
                    <p class="description"><?php esc_html_e(
                      "HTML allowed.",
                      "cdg-core"
                    ); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e("Custom Admin CSS", "cdg-core"); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e("CSS", "cdg-core"); ?></th>
                <td><textarea name="custom_admin_css" rows="10" class="large-text code"><?php echo esc_textarea(
                  $s["custom_admin_css"]
                ); ?></textarea></td>
            </tr>
        </table>
        <?php
  }
}
