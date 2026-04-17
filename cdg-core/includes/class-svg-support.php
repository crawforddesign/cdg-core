<?php
/**
 * SVG Support Class
 *
 * Handles SVG upload support for WordPress.
 *
 * @package CDG_Core
 * @since 1.1.0
 */

declare(strict_types=1);

class CDG_Core_SVG_Support
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

        if ($this->plugin->get_setting("enable_svg_uploads")) {
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
        // Add SVG to allowed mime types
        add_filter("upload_mimes", [$this, "allow_svg_upload"], 20);

        // Fix SVG mime type detection
        add_filter(
            "wp_check_filetype_and_ext",
            [$this, "fix_svg_mime_type"],
            10,
            5,
        );

        // Add SVG preview support in media library
        add_filter(
            "wp_prepare_attachment_for_js",
            [$this, "add_svg_preview"],
            10,
            3,
        );

        // Allow SVG in attachment display
        add_action("admin_head", [$this, "svg_admin_styles"]);
    }

    /**
     * Allow SVG upload
     *
     * @param array<string, string> $mimes Allowed mime types
     * @return array<string, string>
     */
    public function allow_svg_upload(array $mimes): array
    {
        // Only allow for users with upload capability
        if (!current_user_can("upload_files")) {
            return $mimes;
        }

        // Check if restricted to admins only
        if (
            $this->plugin->get_setting("svg_admin_only") &&
            !current_user_can("manage_options")
        ) {
            return $mimes;
        }

        $mimes["svg"] = "image/svg+xml";
        $mimes["svgz"] = "image/svg+xml";

        return $mimes;
    }

    /**
     * Fix SVG mime type detection
     *
     * WordPress sometimes fails to detect SVG mime types correctly.
     *
     * @param array<string, mixed> $data File data
     * @param string $file File path
     * @param string $filename File name
     * @param array<string, string>|null $mimes Allowed mime types
     * @param string|false $real_mime Real mime type
     * @return array<string, mixed>
     */
    public function fix_svg_mime_type(
        array $data,
        string $file,
        string $filename,
        ?array $mimes,
        $real_mime,
    ): array {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        if (strtolower($ext) === "svg" || strtolower($ext) === "svgz") {
            $data["ext"] = $ext;
            $data["type"] = "image/svg+xml";
        }

        return $data;
    }

    /**
     * Add SVG preview support in media library
     *
     * @param array<string, mixed> $response Attachment response
     * @param WP_Post $attachment Attachment post
     * @param array<int>|false $meta Attachment meta
     * @return array<string, mixed>
     */
    public function add_svg_preview(
        array $response,
        WP_Post $attachment,
        $meta,
    ): array {
        if ($response["mime"] !== "image/svg+xml") {
            return $response;
        }

        $svg_url = wp_get_attachment_url($attachment->ID);

        if (!$svg_url) {
            return $response;
        }

        // Set dimensions if not already set
        if (empty($response["width"]) || empty($response["height"])) {
            $dimensions = $this->get_svg_dimensions($attachment->ID);

            if ($dimensions) {
                $response["width"] = $dimensions["width"];
                $response["height"] = $dimensions["height"];
            } else {
                // Default dimensions for display
                $response["width"] = 200;
                $response["height"] = 200;
            }
        }

        // Set image sizes for media library grid
        $response["sizes"] = [
            "full" => [
                "url" => $svg_url,
                "width" => $response["width"],
                "height" => $response["height"],
                "orientation" =>
                    $response["width"] > $response["height"]
                        ? "landscape"
                        : "portrait",
            ],
            "thumbnail" => [
                "url" => $svg_url,
                "width" => 150,
                "height" => 150,
                "orientation" => "portrait",
            ],
            "medium" => [
                "url" => $svg_url,
                "width" => 300,
                "height" => 300,
                "orientation" => "portrait",
            ],
        ];

        return $response;
    }

    /**
     * Get SVG dimensions
     *
     * @param int $attachment_id Attachment ID
     * @return array<string, int>|null
     */
    private function get_svg_dimensions(int $attachment_id): ?array
    {
        $file = get_attached_file($attachment_id);

        if (!$file || !file_exists($file)) {
            return null;
        }

        $svg_content = file_get_contents($file);

        if ($svg_content === false) {
            return null;
        }

        // Try to extract dimensions from SVG
        libxml_use_internal_errors(true);

        $doc = new DOMDocument();
        $loaded = $doc->loadXML($svg_content, LIBXML_NONET);

        libxml_clear_errors();
        libxml_use_internal_errors(false);

        if (!$loaded) {
            return null;
        }

        $svg = $doc->getElementsByTagName("svg")->item(0);

        if (!$svg) {
            return null;
        }

        /** @var DOMElement $svg */
        $width = $svg->getAttribute("width");
        $height = $svg->getAttribute("height");

        // Try viewBox if width/height not available
        if (empty($width) || empty($height)) {
            $viewbox = $svg->getAttribute("viewBox");

            if ($viewbox) {
                $parts = preg_split("/[\s,]+/", trim($viewbox));

                if (count($parts) >= 4) {
                    $width = $parts[2];
                    $height = $parts[3];
                }
            }
        }

        // Parse numeric values
        $width = (int) preg_replace("/[^0-9.]/", "", $width);
        $height = (int) preg_replace("/[^0-9.]/", "", $height);

        if ($width > 0 && $height > 0) {
            return [
                "width" => $width,
                "height" => $height,
            ];
        }

        return null;
    }

    /**
     * Add SVG admin styles for media library
     *
     * @return void
     */
    public function svg_admin_styles(): void
    {
        echo '<style>
            .attachment-preview .thumbnail img[src$=".svg"],
            .attachment-preview .thumbnail img[src$=".svgz"],
            .media-frame-content img[src$=".svg"],
            .media-frame-content img[src$=".svgz"] {
                width: 100%;
                height: auto;
                max-width: 100%;
            }

            .attachment.type-image.subtype-svg .thumbnail {
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .attachment.type-image.subtype-svg .thumbnail img {
                max-width: 80%;
                max-height: 80%;
            }

            .media-modal .thumbnail img[src$=".svg"],
            .media-modal .thumbnail img[src$=".svgz"] {
                width: 100%;
                height: auto;
            }
        </style>';
    }

    /**
     * Check if SVG uploads are enabled
     *
     * @return bool
     */
    public static function is_enabled(): bool
    {
        if (!function_exists("cdg_core")) {
            return false;
        }

        return (bool) cdg_core()->get_setting("enable_svg_uploads");
    }
}
