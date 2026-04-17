<?php
/**
 * CDG Core - Must-Use Plugin
 *
 * WordPress optimizations, security hardening, and agency features
 * for Crawford Design Group client sites.
 *
 * @package CDG_Core
 * @version 1.3.1
 * @author Crawford Design Group
 * @link https://crawforddesigngroup.com
 */

// Prevent direct access
if (!defined("ABSPATH")) {
  exit();
}

/**
 * Plugin Constants
 */
define("CDG_CORE_VERSION", "1.3.1");
define("CDG_CORE_DIR", plugin_dir_path(__FILE__));
define("CDG_CORE_URL", plugin_dir_url(__FILE__));
define("CDG_CORE_BASENAME", plugin_basename(__FILE__));

/**
 * Autoloader for CDG Core classes
 */
spl_autoload_register(function (string $class): void {
  $prefix = "CDG_Core_";

  if (strpos($class, $prefix) !== 0) {
    return;
  }

  // Convert class name to file name
  $class_name = substr($class, strlen($prefix));
  $file_name =
    "class-" . str_replace("_", "-", strtolower($class_name)) . ".php";
  $file_path = CDG_CORE_DIR . "includes/" . $file_name;

  if (file_exists($file_path)) {
    require_once $file_path;
  }
});

/**
 * Main CDG Core Class
 */
final class CDG_Core
{
  /**
   * Plugin instance
   *
   * @var CDG_Core|null
   */
  private static ?CDG_Core $instance = null;

  /**
   * Plugin settings
   *
   * @var array
   */
  private array $settings = [];

  /**
   * Documentation component instance
   *
   * @var CDG_Core_Documentation|null
   */
  private ?CDG_Core_Documentation $documentation = null;

  /**
   * Default settings
   *
   * @var array
   */
  private array $defaults = [
    // Features
    "enable_documentation" => true,
    "enable_cpt_widgets" => true,
    "enable_admin_branding" => true,

    // Defaults - Comments
    "disable_comments" => false,

    // Defaults - Divi Projects
    "hide_divi_projects" => false,

    // WordPress Cleanup
    "remove_wp_version" => true,
    "remove_wlw_manifest" => true,
    "remove_rsd_link" => true,
    "remove_shortlink" => true,
    "remove_adjacent_posts" => true,
    "remove_oembed_links" => true,
    "remove_rest_api_link" => true,
    "disable_emojis" => true,

    // Security
    "disable_xmlrpc" => true,
    "block_dangerous_uploads" => true,
    "remove_powered_by" => true,
    "disable_code_editor" => true,
    "enable_svg_uploads" => false,
    "svg_admin_only" => true,
    "enable_font_uploads" => false,
    "font_admin_only" => true,
    "enable_lottie_uploads" => false,
    "lottie_admin_only" => true,

    // Dashboard Widgets
    "remove_quick_draft" => true,
    "remove_wp_news" => true,
    "remove_php_nag" => true,
    "remove_browser_nag" => true,
    "remove_site_health" => false,
    "remove_welcome_panel" => false,
    "remove_activity" => false,
    "remove_at_a_glance" => false,
    "hidden_dashboard_widgets" => [],

    // Heartbeat
    "heartbeat_admin" => "60",
    "heartbeat_frontend" => "disable",
    "heartbeat_exception_builder" => true,

    // Gutenberg
    "gutenberg_mode" => "optimize",

    // Performance
    "optimize_search" => true,
    "optimize_archives" => true,
    "enable_lazy_loading" => true,
    "remove_medium_large" => true,
    "remove_dns_prefetch" => true,

    // Image Sizes
    "disabled_image_sizes" => [],

    // Post Revisions
    "post_revisions_mode" => "limited",
    "post_revisions_limit" => 5,

    // Gravity Forms
    "enable_gf_fixes" => true,
    "gf_detection_mode" => "auto",
    "gf_manual_pages" => [],

    // Admin
    "admin_footer_text" =>
      'Website by <a href="https://crawforddesigngroup.com" target="_blank">Crawford Design Group</a>',
    "custom_admin_css" => "",

    // Documentation
    "show_documentation_widgets" => true,
    "documentation_module_style" => "informative",
    "documentation_widget_limit" => 5,

    // CPT Dashboard
    "show_cpt_widgets" => true,
    "cpt_module_style" => "informative",
    "selected_cpts" => [],
    "show_recent_posts" => true,
    "recent_posts_limit" => 3,
  ];

  /**
   * Get plugin instance
   *
   * @return CDG_Core
   */
  public static function get_instance(): CDG_Core
  {
    if (null === self::$instance) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  /**
   * Constructor
   */
  private function __construct()
  {
    $this->load_settings();
    $this->check_version();
    $this->init_components();
    $this->setup_hooks();
  }

  /**
   * Load settings from database
   *
   * @return void
   */
  private function load_settings(): void
  {
    $saved = get_option("cdg_core_settings", []);

    if (!is_array($saved)) {
      $saved = [];
    }

    $this->settings = wp_parse_args($saved, $this->defaults);
  }

  /**
   * Check version and run upgrades if needed
   *
   * @return void
   */
  private function check_version(): void
  {
    $installed_version = get_option("cdg_core_version", "0.0.0");

    if (version_compare($installed_version, CDG_CORE_VERSION, "<")) {
      // Schedule activation tasks for 'init' hook when WordPress is fully loaded
      add_action("init", [$this, "run_activation"], 0);
      update_option("cdg_core_version", CDG_CORE_VERSION);
    }
  }

  /**
   * Run activation tasks
   *
   * Reuses the stored Documentation component instance rather than
   * creating a duplicate. Runs on 'init' hook to ensure WordPress
   * rewrite rules are available.
   *
   * @return void
   */
  public function run_activation(): void
  {
    // Reuse existing Documentation instance if available
    if ($this->documentation) {
      $this->documentation->register_post_type();
      $this->documentation->register_taxonomy();
      $this->documentation->create_default_categories();
    }

    // Flush rewrite rules - must happen after post types are registered
    flush_rewrite_rules();
  }

  /**
   * Initialize components
   *
   * @return void
   */
  private function init_components(): void
  {
    // Core WordPress optimizations
    new CDG_Core_Cleanup($this);
    new CDG_Core_Security($this);
    new CDG_Core_Performance($this);

    // Defaults (Comments, Projects)
    new CDG_Core_Defaults($this);

    // Features
    if ($this->get_setting("enable_documentation")) {
      $this->documentation = new CDG_Core_Documentation($this);
    }

    if ($this->get_setting("enable_cpt_widgets")) {
      new CDG_Core_CPT_Dashboard($this);
    }

    if ($this->get_setting("enable_gf_fixes")) {
      new CDG_Core_Gravity_Forms($this);
    }

    // SVG Support - initialize regardless of setting (class checks internally)
    new CDG_Core_SVG_Support($this);

    // Font Support - initialize regardless of setting (class checks internally)
    new CDG_Core_Font_Support($this);

    // Lottie Support - initialize regardless of setting (class checks internally)
    new CDG_Core_Lottie_Support($this);

    // Admin
    if (is_admin()) {
      new CDG_Core_Admin($this);
    }
  }

  /**
   * Setup hooks
   *
   * @return void
   */
  private function setup_hooks(): void
  {
    // Admin branding
    if ($this->get_setting("enable_admin_branding")) {
      add_filter("admin_footer_text", [$this, "admin_footer_text"]);
      add_filter("update_footer", [$this, "admin_footer_version"], 11);
    }

    // Custom admin CSS
    if (!empty($this->get_setting("custom_admin_css"))) {
      add_action("admin_head", [$this, "output_custom_admin_css"]);
    }
  }

  /**
   * Get a setting value
   *
   * @param string $key Setting key
   * @param mixed $default Default value
   * @return mixed
   */
  public function get_setting(string $key, $default = null)
  {
    if (array_key_exists($key, $this->settings)) {
      return $this->settings[$key];
    }

    if (array_key_exists($key, $this->defaults)) {
      return $this->defaults[$key];
    }

    return $default;
  }

  /**
   * Get all settings
   *
   * @return array
   */
  public function get_settings(): array
  {
    return $this->settings;
  }

  /**
   * Get default settings
   *
   * @return array
   */
  public function get_defaults(): array
  {
    return $this->defaults;
  }

  /**
   * Update settings
   *
   * @param array $new_settings New settings
   * @return bool
   */
  public function update_settings(array $new_settings): bool
  {
    $this->settings = wp_parse_args($new_settings, $this->defaults);
    return update_option("cdg_core_settings", $this->settings);
  }

  /**
   * Admin footer text
   *
   * @return string
   */
  public function admin_footer_text(): string
  {
    return wp_kses_post($this->get_setting("admin_footer_text"));
  }

  /**
   * Admin footer version
   *
   * @return string
   */
  public function admin_footer_version(): string
  {
    return sprintf(
      "CDG Core %s | WordPress %s",
      esc_html(CDG_CORE_VERSION),
      esc_html(get_bloginfo("version"))
    );
  }

  /**
   * Output custom admin CSS
   *
   * @return void
   */
  public function output_custom_admin_css(): void
  {
    $css = $this->get_setting("custom_admin_css");
    if (!empty($css)) {
      printf(
        '<style id="cdg-core-admin-css">%s</style>',
        wp_strip_all_tags($css)
      );
    }
  }
}

/**
 * Initialize CDG Core
 */
add_action(
  "plugins_loaded",
  function () {
    CDG_Core::get_instance();
  },
  5
);

/**
 * Helper function to get CDG Core instance
 *
 * @return CDG_Core
 */
function cdg_core(): CDG_Core
{
  return CDG_Core::get_instance();
}
