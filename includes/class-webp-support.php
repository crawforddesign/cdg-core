<?php
/**
 * WebP Support Class
 *
 * Optimizes uploaded PNG/JPG images. Three modes (setting `webp_mode`):
 *   - convert:  write a .webp sibling and serve it on the front end
 *   - compress: re-encode the original in place at the chosen quality
 *   - both:     write the .webp first, then compress the original
 * Independently of mode, oversized uploads can be proportionally resized
 * down to a max width (upload-time only).
 *
 * Originals are never deleted by this class — the destructive "replace
 * originals" step lives in CDG_Core_WebP_Bulk and is gated by a backup zip
 * created by CDG_Core_WebP_Backup.
 *
 * Every path is derived from the uploads base directory plus the path
 * stored in attachment metadata, so everything works the same whether or
 * not "Organize my uploads into month- and year-based folders" is on (and
 * for libraries that mix both layouts).
 *
 * @package CDG_Core
 * @since 1.10.0
 */

declare(strict_types=1);

class CDG_Core_WebP_Support
{
    /**
     * Post meta flag marking an attachment as already compressed.
     */
    public const COMPRESSED_META = "_cdg_img_compressed";

    /**
     * Plugin instance
     *
     * @var CDG_Core
     */
    private CDG_Core $plugin;

    /**
     * Singleton — there is only ever one WebP support class.
     *
     * @var CDG_Core_WebP_Support|null
     */
    private static ?CDG_Core_WebP_Support $instance = null;

    /**
     * Per-request cache of file_exists() results, keyed by absolute path.
     *
     * @var array<string, bool>
     */
    private array $exists_cache = [];

    /**
     * Compiled uploads-URL patterns, keyed by extension group.
     *
     * @var array<string, string>
     */
    private array $url_patterns = [];

    /**
     * Constructor
     *
     * @param CDG_Core $plugin Plugin instance
     */
    public function __construct(CDG_Core $plugin)
    {
        $this->plugin = $plugin;
        self::$instance = $this;

        if ($this->plugin->get_setting("enable_webp")) {
            $this->setup_hooks();
        }
    }

    /**
     * Get the singleton instance.
     *
     * @return CDG_Core_WebP_Support|null
     */
    public static function get_instance(): ?CDG_Core_WebP_Support
    {
        return self::$instance;
    }

    /**
     * Setup hooks
     *
     * @return void
     */
    private function setup_hooks(): void
    {
        // Upload-time resize (full-size original only). Sideloads cover
        // importers and plugins that call media_handle_sideload().
        add_filter("wp_handle_upload", [$this, "handle_uploaded_image"], 10, 2);
        add_filter("wp_handle_sideload", [$this, "handle_uploaded_image"], 10, 2);

        // WordPress's own 2560px "-scaled" copy would leave the huge
        // original on disk beside ours, so step aside when resizing is on.
        if ($this->resize_enabled()) {
            add_filter("big_image_size_threshold", "__return_false");
        }

        // Generate size variants at our quality instead of re-compressing
        // them afterwards (a second lossy pass for almost no size gain).
        if ($this->mode_compresses()) {
            add_filter("wp_editor_set_quality", [$this, "filter_editor_quality"], 10, 2);
        }

        // Once every size variant exists: WebP first, then compress.
        add_filter(
            "wp_generate_attachment_metadata",
            [$this, "process_generated_sizes"],
            10,
            2,
        );

        if ($this->mode_converts()) {
            // Front-end output: swap image URLs to .webp when a sibling
            // exists. The output is WebP-only (no <picture> fallback), per
            // the 1.10.0 design decision.
            add_filter("wp_get_attachment_image_src", [$this, "swap_to_webp_src"], 10, 4);
            add_filter("wp_calculate_image_srcset_meta", [$this, "filter_srcset_meta"], 10, 4);
            add_filter("wp_calculate_image_srcset", [$this, "swap_srcset_to_webp"], 10, 5);
            add_filter("the_content", [$this, "swap_content_to_webp"], 99);
            add_filter("post_thumbnail_html", [$this, "swap_content_to_webp"], 99);
            add_filter("get_avatar", [$this, "swap_content_to_webp"], 99);

            // Catch everything the filters can't (widgets, theme output,
            // Divi inline CSS, hard-coded URLs) with one pass over the page.
            add_action("template_redirect", [$this, "maybe_start_buffer"], 0);
        }

        // Admin notice if the server can't actually generate WebP.
        add_action("admin_notices", [$this, "maybe_show_converter_missing_notice"]);
    }

    // ─────────────────────────────────────────────────────────────
    // Settings
    // ─────────────────────────────────────────────────────────────

    /**
     * Configured mode: "convert", "compress" or "both".
     *
     * @return string
     */
    public function get_mode(): string
    {
        $mode = (string) $this->plugin->get_setting("webp_mode", "convert");
        return in_array($mode, ["convert", "compress", "both"], true)
            ? $mode
            : "convert";
    }

    public function mode_converts(): bool
    {
        return $this->get_mode() !== "compress";
    }

    public function mode_compresses(): bool
    {
        return $this->get_mode() !== "convert";
    }

    public function resize_enabled(): bool
    {
        return (bool) $this->plugin->get_setting("webp_resize", false);
    }

    /**
     * Max width in px for upload-time resizing, clamped to 320-10000.
     *
     * @return int
     */
    public function get_max_width(): int
    {
        $w = (int) $this->plugin->get_setting("webp_max_width", 2400);
        return max(320, min(10000, $w));
    }

    /**
     * Get the configured quality (1-100), clamped.
     *
     * @return int
     */
    public function get_quality(): int
    {
        $q = (int) $this->plugin->get_setting("webp_quality", 80);
        return max(1, min(100, $q));
    }

    /**
     * Apply our quality to JPEG size variants WordPress generates.
     *
     * @param int $quality Quality WordPress would use
     * @param string $mime_type Image mime type
     * @return int
     */
    public function filter_editor_quality($quality, $mime_type)
    {
        return $mime_type === "image/jpeg" ? $this->get_quality() : $quality;
    }

    // ─────────────────────────────────────────────────────────────
    // Upload pipeline
    // ─────────────────────────────────────────────────────────────

    /**
     * Resize the just-uploaded original if it exceeds the max width.
     *
     * Runs on `wp_handle_upload` / `wp_handle_sideload` — the file is
     * already validated and on disk. Returns $file unchanged (path and
     * mime stay the same because the resize overwrites in place), so
     * WordPress records accurate metadata, including the new dimensions.
     *
     * @param array<string, mixed> $file Uploaded file data
     * @param string $context 'upload' or 'sideload'
     * @return array<string, mixed>
     */
    public function handle_uploaded_image(array $file, string $context): array
    {
        if (!isset($file["file"]) || !is_string($file["file"])) {
            return $file;
        }

        if ($this->resize_enabled() && $this->should_convert($file["file"])) {
            $this->resize_file($file["file"], $this->get_max_width());
        }

        return $file;
    }

    /**
     * Process the full-size file and every size variant WordPress just
     * generated. The full-size file gets a WebP sibling first (so it is
     * encoded from the pristine file, not a recompressed one) and is then
     * compressed in place. Size variants only get a WebP sibling — they
     * were already generated at our quality.
     *
     * Paths come from the attachment metadata relative to the uploads
     * base directory, so flat and year/month layouts both work.
     *
     * @param array<string, mixed> $metadata Attachment metadata
     * @param int $attachment_id Attachment ID
     * @return array<string, mixed>
     */
    public function process_generated_sizes(array $metadata, int $attachment_id): array
    {
        if (empty($metadata["file"])) {
            return $metadata;
        }

        $upload_dir = wp_get_upload_dir();
        $base_dir = trailingslashit($upload_dir["basedir"]);
        $base_file = trailingslashit(dirname($base_dir . $metadata["file"]));

        // Compressing twice degrades JPEGs, so each attachment is only
        // compressed once (thumbnail regeneration re-fires this filter).
        $compress =
            $this->mode_compresses() &&
            !($attachment_id && get_post_meta($attachment_id, self::COMPRESSED_META, true));

        $full_ok = $this->process_file($base_dir . $metadata["file"], $compress);

        foreach ((array) ($metadata["sizes"] ?? []) as $size) {
            if (!empty($size["file"])) {
                $this->process_file($base_file . $size["file"], false);
            }
        }

        if ($compress && $full_ok && $attachment_id) {
            update_post_meta($attachment_id, self::COMPRESSED_META, 1);
        }

        return $metadata;
    }

    /**
     * Run the configured mode on a single file: WebP sibling, then
     * in-place compression.
     *
     * @param string $path Absolute path to a PNG/JPG
     * @param bool $compress Whether to compress the original in place
     * @return bool True if every requested step succeeded
     */
    public function process_file(string $path, bool $compress = true): bool
    {
        if (!$this->should_convert($path)) {
            return false;
        }

        $ok = true;
        $quality = $this->get_quality();

        if ($this->mode_converts()) {
            $ok = $this->convert_file($path, $quality);
        }
        if ($compress && $this->mode_compresses()) {
            $ok = $this->compress_file($path, $quality) && $ok;
        }

        return $ok;
    }

    // ─────────────────────────────────────────────────────────────
    // Front-end URL swapping
    // ─────────────────────────────────────────────────────────────

    /**
     * Should front-end URLs be swapped right now?
     *
     * Never in the admin, REST (the block editor would otherwise save
     * .webp URLs into post content permanently), the Customizer preview
     * or the Divi Visual Builder / builder previews.
     *
     * @return bool
     */
    private function should_swap(): bool
    {
        if (is_admin()) {
            return false;
        }
        if (defined("REST_REQUEST") && REST_REQUEST) {
            return false;
        }
        if (function_exists("is_customize_preview") && is_customize_preview()) {
            return false;
        }
        // Divi 4 and Divi 5 visual builder (and builder previews).
        if (function_exists("et_core_is_fb_enabled") && et_core_is_fb_enabled()) {
            return false;
        }
        if (function_exists("et_builder_is_frontend_editor") && et_builder_is_frontend_editor()) {
            return false;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET["et_fb"]) || isset($_GET["et_pb_preview"])) {
            return false;
        }
        return true;
    }

    /**
     * Swap a single image-src return to the .webp sibling if it exists.
     *
     * @param array<int, mixed>|false $image {url, width, height, is_intermediate}
     * @param int $attachment_id Attachment ID
     * @param string|int[] $size Requested size
     * @param bool $icon Whether to return the icon
     * @return array<int, mixed>|false
     */
    public function swap_to_webp_src($image, $attachment_id, $size, $icon)
    {
        if (!is_array($image) || empty($image[0]) || !$this->should_swap()) {
            return $image;
        }

        $image[0] = $this->swap_url_to_webp((string) $image[0]);

        return $image;
    }

    /**
     * Keep WordPress's srcset builder working when the src is a .webp.
     *
     * wp_calculate_image_srcset() bails out (no srcset at all) unless the
     * src contains one of the size filenames from attachment metadata,
     * which still say .jpg/.png. When the src we were handed is a .webp,
     * point the metadata filenames at their .webp siblings too so the
     * match succeeds and the srcset is built from the WebP files.
     *
     * @param array<string, mixed> $image_meta Attachment metadata
     * @param int[] $size_array Requested width/height
     * @param string $image_src The image src
     * @param int $attachment_id Attachment ID
     * @return array<string, mixed>
     */
    public function filter_srcset_meta($image_meta, $size_array, $image_src, $attachment_id)
    {
        if (
            !is_array($image_meta) ||
            empty($image_meta["file"]) ||
            !is_string($image_src) ||
            !preg_match('/\.webp([?#].*)?$/i', $image_src) ||
            !$this->should_swap()
        ) {
            return $image_meta;
        }

        $upload_dir = wp_get_upload_dir();
        $dir = trailingslashit(
            dirname(trailingslashit($upload_dir["basedir"]) . $image_meta["file"]),
        );

        $to_webp = function (string $name) use ($dir): string {
            $webp = preg_replace('/\.(jpe?g|png)$/i', ".webp", $name);
            if (
                is_string($webp) &&
                $webp !== $name &&
                $this->path_exists($dir . $webp)
            ) {
                return $webp;
            }
            return $name;
        };

        $image_meta["file"] = dirname($image_meta["file"]) === "."
            ? $to_webp($image_meta["file"])
            : dirname($image_meta["file"]) . "/" . $to_webp(wp_basename($image_meta["file"]));

        foreach ((array) ($image_meta["sizes"] ?? []) as $key => $size) {
            if (!empty($size["file"])) {
                $image_meta["sizes"][$key]["file"] = $to_webp((string) $size["file"]);
            }
        }

        return $image_meta;
    }

    /**
     * Swap every entry in a srcset array to its .webp sibling.
     *
     * @param array<int, array<string, mixed>>|false $sources Srcset sources
     * @return array<int, array<string, mixed>>|false
     */
    public function swap_srcset_to_webp($sources)
    {
        if (!is_array($sources) || !$this->should_swap()) {
            return $sources;
        }

        foreach ($sources as $key => $source) {
            if (!is_array($source) || empty($source["url"])) {
                continue;
            }
            $sources[$key]["url"] = $this->swap_url_to_webp((string) $source["url"]);
        }

        return $sources;
    }

    /**
     * Filter callback for rendered content blobs (the_content,
     * post_thumbnail_html, get_avatar).
     *
     * @param string $content HTML content
     * @return string
     */
    public function swap_content_to_webp($content)
    {
        if (!is_string($content) || $content === "" || !$this->should_swap()) {
            return $content;
        }

        return $this->swap_html($content);
    }

    /**
     * Rewrite uploads image URLs in an HTML string to their .webp
     * siblings, wherever the sibling exists on disk.
     *
     * Touches image-bearing attributes (src, srcset, poster and every
     * data-*) and CSS url(...) values. It deliberately leaves hrefs
     * alone, so "click for the original" links still go to the original.
     * Unchanged text is returned byte-for-byte — no re-escaping.
     *
     * @param string $html HTML
     * @return string
     */
    public function swap_html(string $html): string
    {
        if (stripos($html, "uploads") === false) {
            return $html;
        }

        $out = preg_replace_callback(
            '/(?<![\w-])((?:data-[\w-]+|src|srcset|poster))(\s*=\s*)("|\')(.*?)\3/is',
            function ($m) {
                $new = $this->rewrite_urls($m[4]);
                return $new === $m[4] ? $m[0] : $m[1] . $m[2] . $m[3] . $new . $m[3];
            },
            $html,
        );
        if (!is_string($out)) {
            return $html;
        }

        $out2 = preg_replace_callback(
            '/url\(\s*(["\']?)([^)"\']+)\1\s*\)/i',
            function ($m) {
                $new = $this->rewrite_urls($m[2]);
                return $new === $m[2] ? $m[0] : "url(" . $m[1] . $new . $m[1] . ")";
            },
            $out,
        );

        return is_string($out2) ? $out2 : $out;
    }

    /**
     * Start output buffering on front-end HTML pages so every remaining
     * uploads image URL can be swapped in one pass.
     *
     * @return void
     */
    public function maybe_start_buffer(): void
    {
        if (
            !$this->should_swap() ||
            wp_doing_ajax() ||
            is_feed() ||
            is_robots() ||
            !apply_filters("cdg_webp_buffer_enabled", true)
        ) {
            return;
        }

        ob_start([$this, "filter_buffer"]);
    }

    /**
     * Output-buffer callback. Fails open: on any problem the original
     * HTML is sent untouched.
     *
     * @param string $html Buffered page
     * @return string
     */
    public function filter_buffer($html)
    {
        if (!is_string($html) || stripos($html, "<html") === false) {
            return $html;
        }

        try {
            // Social/SEO tags and JSON-LD keep their original image URLs —
            // not every crawler accepts WebP.
            $stash = [];
            $protected = preg_replace_callback(
                '/<meta\b[^>]*>|<script\b[^>]*application\/ld\+json[^>]*>.*?<\/script>/is',
                function ($m) use (&$stash) {
                    $key = "<!--cdgwebp" . count($stash) . "-->";
                    $stash[$key] = $m[0];
                    return $key;
                },
                $html,
            );
            if (!is_string($protected)) {
                return $html;
            }

            $swapped = $this->swap_html($protected);

            return $stash ? strtr($swapped, $stash) : $swapped;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    /**
     * Convert a given URL to its .webp sibling if one exists on disk.
     *
     * Pure URL transform — does no DB work. Used by the srcset and
     * attachment-src filters.
     *
     * @param string $url Original image URL
     * @return string WebP URL if a sibling exists, otherwise the original URL
     */
    public function swap_url_to_webp(string $url): string
    {
        if ($url === "" || strpos($url, ".webp") !== false) {
            return $url;
        }

        return $this->rewrite_urls($url);
    }

    /**
     * Rewrite every uploads image URL found in $text.
     *
     * Forward (default): .jpg/.jpeg/.png -> .webp when the .webp sibling
     * exists. Reverse: .webp -> .jpg/.jpeg/.png when that original exists.
     *
     * Matches absolute, protocol-relative and root-relative URLs, plus the
     * JSON-escaped form (https:\/\/...) found in block comments and Divi 5
     * data. File checks use the uploads base directory plus the URL path
     * after it, so it makes no difference how uploads are organized into
     * folders. Query strings and fragments are preserved.
     *
     * @param string $text Text containing URLs
     * @param bool $reverse Map .webp back to the original format
     * @return string
     */
    public function rewrite_urls(string $text, bool $reverse = false): string
    {
        $pattern = $this->get_url_pattern($reverse);
        if ($pattern === "") {
            return $text;
        }

        $upload_dir = wp_get_upload_dir();
        $basedir = rtrim($upload_dir["basedir"], "/\\");

        $out = preg_replace_callback(
            $pattern,
            function ($m) use ($basedir, $reverse) {
                $rel = rawurldecode(str_replace("\\/", "/", $m[1]));
                if (strpos($rel, "..") !== false) {
                    return $m[0];
                }
                $stem = $basedir . "/" . ltrim(substr($rel, 0, -strlen($m[2])), "/");
                $base = substr($m[0], 0, -strlen($m[2]));

                if (!$reverse) {
                    return $this->path_exists($stem . "webp") ? $base . "webp" : $m[0];
                }
                foreach (["jpg", "jpeg", "png"] as $candidate) {
                    if ($this->path_exists($stem . $candidate)) {
                        return $base . $candidate;
                    }
                }
                return $m[0];
            },
            $text,
        );

        return is_string($out) ? $out : $text;
    }

    /**
     * Build (and cache) the regex that finds uploads image URLs.
     *
     * @param bool $reverse Match .webp instead of .jpg/.jpeg/.png
     * @return string Regex, or "" if the uploads URL can't be parsed
     */
    private function get_url_pattern(bool $reverse): string
    {
        $key = $reverse ? "webp" : "raster";
        if (isset($this->url_patterns[$key])) {
            return $this->url_patterns[$key];
        }

        $upload_dir = wp_get_upload_dir();
        $parts = wp_parse_url($upload_dir["baseurl"]);
        if (empty($parts["host"]) || !isset($parts["path"])) {
            return $this->url_patterns[$key] = "";
        }

        // Allow an escaped slash (\/) anywhere a slash can appear.
        $slash = static function (string $s): string {
            return str_replace("/", "\\\\?/", preg_quote($s, "#"));
        };

        $host = $parts["host"] . (isset($parts["port"]) ? ":" . $parts["port"] : "");
        $prefix =
            "(?:(?:https?:)?\\\\?/\\\\?/" . preg_quote($host, "#") . ")?" .
            $slash(rtrim($parts["path"], "/"));

        $ext = $reverse ? "webp" : "jpe?g|png";

        // (?<![\w.-]) stops a root-relative path from matching the tail of
        // somebody else's host. The URL body excludes whitespace, quotes,
        // brackets, commas and bare backslashes.
        return $this->url_patterns[$key] =
            "#(?<![\\w.\\-])" . $prefix .
            "((?:[^\\s\"'<>()\\\\,]|\\\\/)+?\\.(" . $ext . "))" .
            "(?=\$|[\\s\"'<>()\\\\,?\\#&])#i";
    }

    /**
     * Cached file_exists().
     *
     * @param string $path Absolute path
     * @return bool
     */
    private function path_exists(string $path): bool
    {
        if (!isset($this->exists_cache[$path])) {
            // A 0-byte file (a failed write) must never count as existing.
            $this->exists_cache[$path] = is_file($path) && filesize($path) > 0;
        }
        return $this->exists_cache[$path];
    }

    // ─────────────────────────────────────────────────────────────
    // File operations
    // ─────────────────────────────────────────────────────────────

    /**
     * Absolute path of the .webp sibling for a PNG/JPG path.
     *
     * @param string $path Absolute path to a PNG/JPG
     * @return string|null Null when $path isn't a PNG/JPG
     */
    public function webp_path_for(string $path): ?string
    {
        $webp = preg_replace('/\.(jpe?g|png)$/i', ".webp", $path);
        return is_string($webp) && $webp !== $path ? $webp : null;
    }

    /**
     * Is there a usable (non-empty) file at $path?
     *
     * @param string $path Absolute path, typically a .webp
     * @return bool
     */
    public function webp_exists(string $path): bool
    {
        return is_file($path) && filesize($path) > 0;
    }

    /**
     * Proportionally shrink an image in place if it is wider than $max.
     * Never upscales. Applies the EXIF orientation first so phone photos
     * don't end up sideways once the metadata is dropped.
     *
     * @param string $path Absolute path to a PNG/JPG
     * @param int $max Max width in px
     * @return bool True if the file was resized
     */
    public function resize_file(string $path, int $max): bool
    {
        $info = @getimagesize($path);
        if (!$info || (int) $info[0] <= $max) {
            return false;
        }

        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            error_log("[CDG Core WebP] Resize failed for $path: " . $editor->get_error_message());
            return false;
        }

        if (method_exists($editor, "maybe_exif_rotate")) {
            $editor->maybe_exif_rotate();
        }
        $editor->set_quality($this->get_quality());

        // Height is null so the editor scales proportionally.
        $resized = $editor->resize($max, null, false);
        if (is_wp_error($resized)) {
            error_log("[CDG Core WebP] Resize failed for $path: " . $resized->get_error_message());
            return false;
        }

        // A PNG that is only a little too wide can come out larger after
        // scaling (smooth, mostly-transparent images compress very well at
        // full size). In that case keep the original — a slightly large
        // file beats a bigger one. Genuinely oversized images are always
        // resized.
        $is_png = (bool) preg_match('/\.png$/i', $path);
        $guard = $is_png && (int) $info[0] <= (int) ceil($max * 1.5);

        return $this->save_editor($editor, $path, $guard);
    }

    /**
     * Re-encode an image in place at the given quality. The original is
     * only replaced when the result is actually smaller, so PNGs that
     * can't shrink are left untouched.
     *
     * Note: re-encoding drops embedded metadata (EXIF, camera data) from
     * the file. Whether an embedded colour profile survives depends on
     * the image library in use, so photos with unusual profiles can shift
     * colour slightly.
     *
     * @param string $path Absolute path to a PNG/JPG
     * @param int $quality 1-100
     * @return bool True on success (including "nothing to gain")
     */
    public function compress_file(string $path, int $quality): bool
    {
        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            error_log("[CDG Core WebP] Compress failed for $path: " . $editor->get_error_message());
            return false;
        }

        $editor->set_quality($quality);
        $ok = $this->save_editor($editor, $path, true);

        // Keep the WebP sibling's mtime in step so a later bulk run does
        // not regenerate it from the already-compressed original.
        $webp = $this->webp_path_for($path);
        if ($ok && $webp !== null && $this->webp_exists($webp)) {
            @touch($webp, (int) filemtime($path));
        }

        return $ok;
    }

    /**
     * Save an editor's image over $path via a temp file.
     *
     * @param WP_Image_Editor $editor Loaded editor
     * @param string $path Destination (also the source)
     * @param bool $only_if_smaller Discard the result if it isn't smaller
     * @return bool
     */
    private function save_editor($editor, string $path, bool $only_if_smaller): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $tmp = dirname($path) . "/" . wp_basename($path, ".$ext") . "-cdgtmp." . $ext;

        $saved = $editor->save($tmp);
        if (is_wp_error($saved) || empty($saved["path"]) || !file_exists($saved["path"])) {
            error_log("[CDG Core WebP] Could not write temp file for $path");
            return false;
        }

        $tmp_path = $saved["path"];
        if ($only_if_smaller && filesize($tmp_path) >= filesize($path)) {
            @unlink($tmp_path);
            return true;
        }

        if (!@rename($tmp_path, $path)) {
            @unlink($tmp_path);
            return false;
        }
        clearstatcache(true, $path);
        unset($this->exists_cache[$path]);
        return true;
    }

    /**
     * Convert a file on disk to WebP, writing a sibling.
     *
     * If the input is `photo.jpg`, writes `photo.webp` next to it. Original
     * is never touched. If conversion fails for any reason, the original
     * is left intact and an error is logged — this code path must not be
     * destructive.
     *
     * Uses WordPress's own image editor (so EXIF orientation, alpha and
     * the Imagick-vs-GD choice behave exactly like core), with a direct GD
     * fallback for the cases the GD editor can't handle (palette PNGs).
     *
     * @param string $path Absolute filesystem path to a PNG/JPG file
     * @param int $quality 1-100
     * @return bool True if a WebP file now exists for this image
     */
    public function convert_file(string $path, int $quality): bool
    {
        if (!file_exists($path) || !$this->should_convert($path)) {
            return false;
        }

        $webp_path = $this->webp_path_for($path);
        if ($webp_path === null) {
            return false;
        }

        // Don't reconvert a newer-or-equal WebP that already exists.
        if (
            $this->webp_exists($webp_path) &&
            filemtime($webp_path) >= filemtime($path)
        ) {
            return true;
        }

        if (self::detect_engine() === null) {
            return false;
        }

        $tmp = dirname($webp_path) . "/" . wp_basename($webp_path, ".webp") . "-cdgtmp.webp";
        // WordPress's GD editor "succeeds" on palette PNGs while writing a
        // 0-byte file, so send those straight to our own GD routine.
        $ok = false;
        if (!(self::detect_engine() === "gd" && $this->is_palette_png($path))) {
            $ok = $this->convert_with_editor($path, $tmp, $quality);
        }
        if (!$ok && function_exists("imagewebp")) {
            @unlink($tmp);
            $ok = $this->convert_with_gd($path, $tmp, $quality);
        }

        if ($ok && $this->webp_exists($tmp) && @rename($tmp, $webp_path)) {
            clearstatcache(true, $webp_path);
            unset($this->exists_cache[$webp_path]);
            return true;
        }

        @unlink($tmp);
        error_log("[CDG Core WebP] Failed to convert $path");
        return false;
    }

    /**
     * Convert with the WordPress image editor.
     *
     * @param string $src Source file path
     * @param string $dst Destination file path (.webp)
     * @param int $quality 1-100
     * @return bool
     */
    private function convert_with_editor(string $src, string $dst, int $quality): bool
    {
        $editor = wp_get_image_editor($src);
        if (is_wp_error($editor)) {
            return false;
        }
        if (method_exists($editor, "maybe_exif_rotate")) {
            $editor->maybe_exif_rotate();
        }
        $editor->set_quality($quality);

        $saved = @$editor->save($dst, "image/webp");
        return !is_wp_error($saved) && !empty($saved["path"]) &&
            $this->webp_exists($saved["path"]) &&
            ($saved["path"] === $dst || @rename($saved["path"], $dst));
    }

    /**
     * Is this an indexed-colour (palette) PNG? Reads the colour-type byte
     * of the PNG header.
     *
     * @param string $path Absolute path
     * @return bool
     */
    private function is_palette_png(string $path): bool
    {
        if (!preg_match('/\.png$/i', $path)) {
            return false;
        }
        $h = @file_get_contents($path, false, null, 0, 26);
        return is_string($h) && strlen($h) === 26 &&
            strncmp($h, "\x89PNG", 4) === 0 && ord($h[25]) === 3;
    }

    /**
     * Convert with GD directly.
     *
     * @param string $src Source file path
     * @param string $dst Destination file path
     * @param int $quality Quality 1-100
     * @return bool
     */
    private function convert_with_gd(string $src, string $dst, int $quality): bool
    {
        if (!function_exists("imagecreatefromstring")) {
            return false;
        }
        $bytes = @file_get_contents($src);
        if ($bytes === false) {
            return false;
        }
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return false;
        }

        // imagewebp() rejects palette images.
        if (function_exists("imagepalettetotruecolor") && !imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }

        $is_png = (bool) preg_match('/\.png$/i', $src);
        if ($is_png) {
            imagesavealpha($img, true);
            imagealphablending($img, true);
        } else {
            // Apply EXIF orientation, which WebP can't carry.
            if (function_exists("exif_read_data")) {
                $exif = @exif_read_data($src);
                $orientation = (int) ($exif["Orientation"] ?? 1);
                $angles = [3 => 180, 6 => -90, 8 => 90];
                if (isset($angles[$orientation])) {
                    $rotated = imagerotate($img, $angles[$orientation], 0);
                    if ($rotated !== false) {
                        imagedestroy($img);
                        $img = $rotated;
                    }
                }
            }
            // Flatten onto white (JPEGs have no alpha, but be safe).
            $w = imagesx($img);
            $h = imagesy($img);
            $canvas = imagecreatetruecolor($w, $h);
            imagefilledrectangle($canvas, 0, 0, $w, $h, imagecolorallocate($canvas, 255, 255, 255));
            imagecopy($canvas, $img, 0, 0, 0, 0, $w, $h);
            imagedestroy($img);
            $img = $canvas;
        }

        $ok = imagewebp($img, $dst, $quality);
        imagedestroy($img);

        return (bool) $ok && $this->webp_exists($dst);
    }

    /**
     * Check whether a path is a candidate for conversion.
     *
     * @param string $path Absolute path
     * @return bool
     */
    public function should_convert(string $path): bool
    {
        if (empty($path) || !file_exists($path)) {
            return false;
        }

        return (bool) preg_match('/\.(jpe?g|png)$/i', $path);
    }

    // ─────────────────────────────────────────────────────────────
    // Capability detection
    // ─────────────────────────────────────────────────────────────

    /**
     * Which engine WordPress will use to write WebP.
     *
     * Asks WordPress itself, which only picks Imagick if it really has
     * the WebP delegate (the bare Imagick class is not proof of that) and
     * otherwise falls back to GD's imagewebp().
     *
     * @return string|null "imagick", "gd", or null if neither can write WebP
     */
    private static function detect_engine(): ?string
    {
        if (!function_exists("_wp_image_editor_choose")) {
            return function_exists("imagewebp") ? "gd" : null;
        }
        $class = _wp_image_editor_choose(["mime_type" => "image/webp"]);
        if (!$class) {
            return null;
        }
        return stripos((string) $class, "imagick") !== false ? "imagick" : "gd";
    }

    /**
     * Pick the best available converter.
     *
     * @return string|null "imagick", "gd", or null if neither is usable
     */
    public function get_converter(): ?string
    {
        return self::detect_engine();
    }

    /**
     * Is WebP conversion supported on this server?
     *
     * @return bool
     */
    public static function is_supported(): bool
    {
        return self::detect_engine() !== null;
    }

    /**
     * Show an admin notice if the server can't convert images.
     *
     * @return void
     */
    public function maybe_show_converter_missing_notice(): void
    {
        if (!$this->mode_converts() || $this->get_converter() !== null) {
            return;
        }
        if (!current_user_can("manage_options")) {
            return;
        }
        $user_id = get_current_user_id();
        if (get_user_meta($user_id, "cdg_webp_converter_notice_dismissed", true)) {
            return;
        }
        echo '<div class="notice notice-error is-dismissible cdg-webp-converter-notice">';
        echo "<p>";
        echo wp_kses(
            __(
                "<strong>CDG Core &mdash; WebP conversion is enabled</strong> but this server has neither Imagick (with WebP support) nor the GD <code>imagewebp()</code> function. Install one of them, or disable the WebP feature.",
                "cdg-core",
            ),
            ["strong" => [], "code" => []],
        );
        echo "</p></div>";
    }
}
