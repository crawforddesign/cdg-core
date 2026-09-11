<?php
/**
 * Font Support Class
 *
 * Handles custom font file upload support for WordPress (OTF, TTF, WOFF, WOFF2).
 *
 * @package CDG_Core
 * @since 1.3.1
 */

declare(strict_types=1);

class CDG_Core_Font_Support
{
  /**
   * Plugin instance
   *
   * @var CDG_Core
   */
  private CDG_Core $plugin;

  /**
   * Supported font mime types
   *
   * @var array<string, string>
   */
  private const FONT_MIMES = [
    "otf" => "font/otf",
    "ttf" => "font/ttf",
    "woff" => "font/woff",
    "woff2" => "font/woff2",
  ];

  /**
   * Constructor
   *
   * @param CDG_Core $plugin Plugin instance
   */
  public function __construct(CDG_Core $plugin)
  {
    $this->plugin = $plugin;

    if ($this->plugin->get_setting("enable_font_uploads")) {
      $this->setup_hooks();
    }
  }

  /**
   * Setup hooks
   *
   * @return void
   */
  private function setup_hooks(): void
  {
    // Add font types to allowed mime types
    add_filter("upload_mimes", [$this, "allow_font_upload"], 20);

    // Fix font mime type detection
    add_filter(
      "wp_check_filetype_and_ext",
      [$this, "fix_font_mime_type"],
      10,
      5
    );
  }

  /**
   * Allow font file uploads
   *
   * @param array<string, string> $mimes Allowed mime types
   * @return array<string, string>
   */
  public function allow_font_upload(array $mimes): array
  {
    if (!$this->current_user_can_upload_fonts()) {
      return $mimes;
    }

    foreach (self::FONT_MIMES as $ext => $mime) {
      $mimes[$ext] = $mime;
    }

    return $mimes;
  }

  /**
   * Fix font mime type detection
   *
   * WordPress sometimes fails to detect font mime types correctly.
   *
   * @param array<string, mixed> $data File data
   * @param string $file File path
   * @param string $filename File name
   * @param array<string, string>|null $mimes Allowed mime types
   * @param string|false $real_mime Real mime type
   * @return array<string, mixed>
   */
  public function fix_font_mime_type(
    array $data,
    string $file,
    string $filename,
    ?array $mimes,
    $real_mime
  ): array {
    // Mirror allow_font_upload()'s capability gate. Without this check the
    // filter would hand back a valid ext/type pair for a font file even when
    // the uploader isn't permitted to upload one, which is enough for
    // wp_handle_upload() to accept it — bypassing "Restrict to Administrators".
    if (!$this->current_user_can_upload_fonts()) {
      return $data;
    }

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if (array_key_exists($ext, self::FONT_MIMES)) {
      $data["ext"] = $ext;
      $data["type"] = self::FONT_MIMES[$ext];
    }

    return $data;
  }

  /**
   * Whether the current user may upload font files: needs the upload
   * capability, plus manage_options when "Restrict to Administrators" is on.
   *
   * @return bool
   */
  private function current_user_can_upload_fonts(): bool
  {
    if (!current_user_can("upload_files")) {
      return false;
    }

    if (
      $this->plugin->get_setting("font_admin_only") &&
      !current_user_can("manage_options")
    ) {
      return false;
    }

    return true;
  }

  /**
   * Check if font uploads are enabled
   *
   * @return bool
   */
  public static function is_enabled(): bool
  {
    if (!function_exists("cdg_core")) {
      return false;
    }

    return (bool) cdg_core()->get_setting("enable_font_uploads");
  }
}
