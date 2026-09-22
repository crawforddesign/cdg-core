<?php
/**
 * Code Snippets Class
 *
 * Injects admin-managed CSS, JS, HTML, and PHP snippets into the site.
 *
 * @package CDG_Core
 * @since 1.7.0
 */

declare(strict_types=1);

class CDG_Core_Code_Snippets
{
  private CDG_Core $plugin;

  public function __construct(CDG_Core $plugin)
  {
    $this->plugin = $plugin;
    add_action("wp_head",   [$this, "inject_head"],   999);
    add_action("wp_footer", [$this, "inject_footer"], 999);
    add_action("init",      [$this, "run_php"],       1);
  }

  private function active(string $type, string $location = ""): array
  {
    $out = [];
    foreach ((array) ($this->plugin->get_settings()["code_snippets"] ?? []) as $s) {
      if (empty($s["active"]) || ($s["type"] ?? "") !== $type) {
        continue;
      }
      if ($location !== "" && ($s["location"] ?? "head") !== $location) {
        continue;
      }
      $out[] = $s;
    }
    return $out;
  }

  public function inject_head(): void
  {
    foreach ($this->active("css", "head") as $s) {
      echo "\n<style>\n" . $s["code"] . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
    }
    foreach ($this->active("js", "head") as $s) {
      echo "\n<script>\n" . $s["code"] . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
    }
    foreach ($this->active("html", "head") as $s) {
      echo "\n" . $s["code"] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
    }
  }

  public function inject_footer(): void
  {
    foreach ($this->active("css", "footer") as $s) {
      echo "\n<style>\n" . $s["code"] . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
    }
    foreach ($this->active("js", "footer") as $s) {
      echo "\n<script>\n" . $s["code"] . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
    }
    foreach ($this->active("html", "footer") as $s) {
      echo "\n" . $s["code"] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
    }
  }

  public function run_php(): void
  {
    // Do not run PHP snippets during AJAX or REST API calls — output or header()
    // calls inside a snippet would corrupt the JSON response.
    if (wp_doing_ajax() || (defined("REST_REQUEST") && REST_REQUEST)) {
      return;
    }

    foreach ($this->active("php") as $s) {
      $code = self::strip_open_tag((string) ($s["code"] ?? ""));

      if ($code === "") {
        continue;
      }

      try {
        eval($code); // phpcs:ignore Squiz.PHP.Eval.Discouraged
      } catch (\Throwable $e) {
        // Still swallowed so a broken snippet can never white-screen the site,
        // but it now leaves a trail. A silently discarded ParseError here is
        // effectively undebuggable from the front end.
        error_log(
          sprintf(
            'CDG Core: PHP snippet "%s" failed - %s: %s in %s on line %d',
            $s["title"] ?? "untitled",
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
          )
        );
      }
    }
  }

  /**
   * eval() parses its argument as PHP already, so a leading `<?php` tag is a
   * ParseError rather than a no-op. Admins paste it out of habit, so strip it
   * (and a trailing close tag) instead of failing on it.
   */
  private static function strip_open_tag(string $code): string
  {
    // Long-form tag only: stripping `<?=` would silently discard an echo.
    $code = (string) preg_replace('/\A\s*<\?php\b/i', "", $code, 1);
    $code = (string) preg_replace('/\?>\s*\z/', "", $code, 1);

    return trim($code);
  }
}
