<?php
/**
 * WebP Bulk Conversion Class
 *
 * Drives the one-time bulk conversion of an existing media library, and
 * the destructive "Replace originals with WebP" step that rewrites
 * post_content (including Divi builder JSON) and deletes the original
 * PNG/JPG files. The replace step is gated by a backup zip created by
 * CDG_Core_WebP_Backup; without a fresh backup it refuses to run.
 *
 * Progress is tracked via a site option so the AJAX UI can poll across
 * multiple short-lived PHP requests without losing state.
 *
 * @package CDG_Core
 * @since 1.10.0
 */

declare(strict_types=1);

class CDG_Core_WebP_Bulk
{
    /**
     * Site option key for the running bulk job.
     */
    private const STATUS_OPTION = "cdg_webp_bulk_status";

    /**
     * Site option key for the last completed bulk job (for the UI to show
     * "Last completed: ..." alongside a fresh "Start" button).
     */
    private const LAST_RUN_OPTION = "cdg_webp_bulk_last_run";

    /**
     * Site option key for the last completed replace step.
     */
    private const LAST_REPLACE_OPTION = "cdg_webp_bulk_last_replace";

    /**
     * Batch size per AJAX tick. Kept small so PHP execution time stays
     * well under typical shared-hosting timeouts.
     */
    private const BATCH_SIZE = 25;

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
    }

    /**
     * Register the settings-page AJAX endpoints and WP-CLI commands.
     *
     * @return void
     */
    public function register_hooks(): void
    {
        $actions = [
            "start_bulk" => "ajax_start_bulk",
            "tick_bulk" => "ajax_tick_bulk",
            "cancel_bulk" => "ajax_cancel_bulk",
            "start_replace" => "ajax_start_replace",
            "tick_replace" => "ajax_tick_replace",
            "start_restore" => "ajax_start_restore",
            "preview" => "ajax_preview",
            "start_cleanup" => "ajax_start_cleanup",
            "tick_cleanup" => "ajax_tick_cleanup",
            "status" => "ajax_get_status",
        ];
        foreach ($actions as $action => $method) {
            add_action("wp_ajax_cdg_webp_" . $action, [$this, $method]);
        }

        $this->register_cli_commands();
    }

    /**
     * Is the feature switched on in saved settings?
     *
     * @return bool
     */
    private function feature_enabled(): bool
    {
        return (bool) $this->plugin->get_setting("enable_webp");
    }

    /**
     * Send the standard "turn it on first" error.
     *
     * @return void
     */
    private function require_feature_enabled(): void
    {
        if (!$this->feature_enabled()) {
            wp_send_json_error([
                "message" =>
                    "Turn on \"Optimize Images on Upload\" and click Save Changes first.",
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // AJAX entry points
    // ─────────────────────────────────────────────────────────────

    /**
     * AJAX: start a bulk conversion job. Resets status, kicks off the
     * first batch synchronously, and returns the initial state.
     */
    public function ajax_start_bulk(): void
    {
        check_ajax_referer("cdg_webp", "nonce");

        if (!$this->current_user_can_run()) {
            wp_send_json_error(["message" => "forbidden"], 403);
        }

        $this->require_feature_enabled();

        $support = CDG_Core_WebP_Support::get_instance();
        if (
            $support === null ||
            ($support->mode_converts() && $support->get_converter() === null)
        ) {
            wp_send_json_error([
                "message" =>
                    "WebP conversion is not supported on this server.",
            ]);
        }

        // Compressing existing images overwrites originals, so it needs a
        // backup first. (Uploads are new files — no backup needed there.)
        if ($support->mode_compresses()) {
            if (!$this->has_fresh_backup()) {
                wp_send_json_error([
                    "message" =>
                        "No up-to-date backup. Create a new backup first (it must be under 24 hours old and newer than your latest upload).",
                ]);
            }
        }

        // Reset status
        $this->set_status([
            "phase" => "converting",
            "started_at" => time(),
            "cursor" => 0,
            "total" => $this->count_candidates(),
            "converted" => 0,
            "skipped" => 0,
            "failed" => 0,
            "done" => false,
            "error" => null,
        ]);

        // Process the first batch inline so the user sees progress immediately
        $this->process_batch();

        wp_send_json_success($this->get_status());
    }

    /**
     * AJAX: continue a running bulk job by one batch.
     */
    public function ajax_tick_bulk(): void
    {
        check_ajax_referer("cdg_webp", "nonce");

        if (!$this->current_user_can_run()) {
            wp_send_json_error(["message" => "forbidden"], 403);
        }

        $status = $this->get_status();
        if (empty($status) || ($status["phase"] ?? null) !== "converting") {
            wp_send_json_success($status ?: ["done" => true]);
        }
        if (!empty($status["done"])) {
            wp_send_json_success($status);
        }

        $this->process_batch();
        wp_send_json_success($this->get_status());
    }

    /**
     * AJAX: cancel a running bulk job. Already-converted files stay
     * converted; the cursor just stops advancing.
     */
    public function ajax_cancel_bulk(): void
    {
        check_ajax_referer("cdg_webp", "nonce");

        if (!$this->current_user_can_run()) {
            wp_send_json_error(["message" => "forbidden"], 403);
        }

        $status = $this->get_status();
        if (!empty($status)) {
            $status["done"] = true;
            $status["phase"] = "cancelled";
            $this->set_status($status);
            update_option(self::LAST_RUN_OPTION, [
                "finished_at" => time(),
                "converted" => $status["converted"] ?? 0,
                "skipped" => $status["skipped"] ?? 0,
                "failed" => $status["failed"] ?? 0,
                "outcome" => "cancelled",
            ]);
        }
        wp_send_json_success($this->get_status());
    }

    /**
     * AJAX: kick off the destructive replace step. Requires a fresh
     * backup zip in the uploads directory.
     */
    public function ajax_start_replace(): void
    {
        check_ajax_referer("cdg_webp", "nonce");

        if (!$this->current_user_can_run()) {
            wp_send_json_error(["message" => "forbidden"], 403);
        }

        $this->require_feature_enabled();

        $blocked = $this->replace_gate();
        if ($blocked !== null) {
            wp_send_json_error($blocked);
        }

        $this->set_status($this->init_replace_status());

        $this->process_replace_batch();

        wp_send_json_success($this->get_status());
    }

    /**
     * AJAX: continue a running replace job by one batch.
     */
    public function ajax_tick_replace(): void
    {
        check_ajax_referer("cdg_webp", "nonce");

        if (!$this->current_user_can_run()) {
            wp_send_json_error(["message" => "forbidden"], 403);
        }

        $status = $this->get_status();
        if (empty($status) || ($status["phase"] ?? null) !== "replacing") {
            wp_send_json_success($status ?: ["done" => true]);
        }
        if (!empty($status["done"])) {
            wp_send_json_success($status);
        }

        $this->process_replace_batch();
        wp_send_json_success($this->get_status());
    }

    /**
     * AJAX: restore from the latest backup zip.
     */
    public function ajax_start_restore(): void
    {
        check_ajax_referer("cdg_webp", "nonce");

        if (!$this->current_user_can_run()) {
            wp_send_json_error(["message" => "forbidden"], 403);
        }

        $backup = new CDG_Core_WebP_Backup();
        $latest = $backup->get_latest_backup();
        if ($latest === null) {
            wp_send_json_error([
                "message" =>
                    "No backup available. Cannot restore.",
            ]);
        }

        $result = $this->run_restore($latest);

        if (is_wp_error($result)) {
            wp_send_json_error(["message" => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: start deleting leftover originals (the pre-scaled and
     * pre-rotated copies WordPress keeps next to a resized image).
     */
    public function ajax_start_cleanup(): void
    {
        check_ajax_referer("cdg_webp", "nonce");

        if (!$this->current_user_can_run()) {
            wp_send_json_error(["message" => "forbidden"], 403);
        }
        $this->require_feature_enabled();

        if (!$this->has_fresh_backup()) {
            wp_send_json_error([
                "message" =>
                    "No up-to-date backup. Create a new backup first (it must be under 24 hours old and newer than your latest upload).",
            ]);
        }

        $this->set_status([
            "phase" => "cleaning",
            "started_at" => time(),
            "cursor" => 0,
            "seen" => 0,
            "total" => $this->count_leftover_originals(),
            "deleted" => 0,
            "in_use" => 0,
            "missing" => 0,
            "bytes_freed" => 0,
            "done" => false,
            "error" => null,
        ]);

        $this->process_cleanup_batch();

        wp_send_json_success($this->get_status());
    }

    /**
     * AJAX: continue a running cleanup job by one batch.
     */
    public function ajax_tick_cleanup(): void
    {
        check_ajax_referer("cdg_webp", "nonce");

        if (!$this->current_user_can_run()) {
            wp_send_json_error(["message" => "forbidden"], 403);
        }

        $status = $this->get_status();
        if (empty($status) || ($status["phase"] ?? null) !== "cleaning" || !empty($status["done"])) {
            wp_send_json_success($status ?: ["done" => true]);
        }

        $this->process_cleanup_batch();
        wp_send_json_success($this->get_status());
    }

    /**
     * AJAX: dry run. Scans the library exactly like the real job and
     * reports what it would do, without changing a thing.
     *
     * Stateless: the browser sends back the cursor and running totals with
     * each request, so nothing is stored on the server.
     */
    public function ajax_preview(): void
    {
        check_ajax_referer("cdg_webp", "nonce");

        if (!$this->current_user_can_run()) {
            wp_send_json_error(["message" => "forbidden"], 403);
        }
        $this->require_feature_enabled();

        $kind = sanitize_key((string) ($_POST["kind"] ?? ""));
        $step = sanitize_key((string) ($_POST["step"] ?? ""));
        $cursor = max(0, (int) ($_POST["cursor"] ?? 0));

        // Only known counters are accepted back, and only as integers.
        $acc = [];
        $raw = json_decode(wp_unslash((string) ($_POST["acc"] ?? "{}")), true);
        if (is_array($raw)) {
            foreach ($raw as $k => $v) {
                if (is_string($k) && preg_match('/^[a-z_]{1,32}$/', $k)) {
                    $acc[$k] = (int) $v;
                }
            }
        }

        if ($kind === "convert") {
            $out = $this->preview_convert($cursor, $acc);
        } elseif ($kind === "replace") {
            $out = $this->preview_replace($step ?: "content", $cursor, $acc);
        } elseif ($kind === "cleanup") {
            $out = $this->preview_cleanup($cursor, $acc);
        } else {
            wp_send_json_error(["message" => "Unknown preview."]);
        }

        wp_send_json_success($out);
    }

    /**
     * Dry run for step 1 (convert / compress existing images).
     *
     * @param int $cursor Offset into the PNG/JPG attachments
     * @param array<string, int> $acc Running totals
     * @return array<string, mixed>
     */
    private function preview_convert(int $cursor, array $acc): array
    {
        $support = CDG_Core_WebP_Support::get_instance();
        $ids = $this->get_candidate_ids($cursor, self::BATCH_SIZE);
        $deadline = microtime(true) + 15;
        $processed = 0;

        foreach ($ids as $id) {
            if (microtime(true) > $deadline) {
                break;
            }
            $processed++;
            $files = $this->attachment_files((int) $id);
            if (empty($files)) {
                $acc["missing"] = ($acc["missing"] ?? 0) + 1;
                continue;
            }

            $acc["images"] = ($acc["images"] ?? 0) + 1;
            $needs_webp = false;
            foreach ($files as $f) {
                $acc["files"] = ($acc["files"] ?? 0) + 1;
                $acc["bytes"] = ($acc["bytes"] ?? 0) + (int) @filesize($f);
                if ($support->mode_converts()) {
                    $w = $support->webp_path_for($f);
                    if (
                        $w !== null &&
                        (!$support->webp_exists($w) || filemtime($w) < filemtime($f))
                    ) {
                        $needs_webp = true;
                        $acc["webp_files"] = ($acc["webp_files"] ?? 0) + 1;
                    }
                }
            }

            $needs_compress =
                $support->mode_compresses() &&
                !get_post_meta($id, CDG_Core_WebP_Support::COMPRESSED_META, true);

            if ($needs_webp) {
                $acc["to_convert"] = ($acc["to_convert"] ?? 0) + 1;
            }
            if ($needs_compress) {
                $acc["to_compress"] = ($acc["to_compress"] ?? 0) + 1;
            }
            if (!$needs_webp && !$needs_compress) {
                $acc["up_to_date"] = ($acc["up_to_date"] ?? 0) + 1;
            }
        }

        return [
            "done" => empty($ids),
            "step" => "convert",
            "cursor" => $cursor + $processed,
            "total" => $this->count_candidates(),
            "acc" => $acc,
            "mode" => $support->get_mode(),
        ];
    }

    /**
     * Dry run for step 3 (replace originals), in the same order as the
     * real job: content, then options/meta, then attachments.
     *
     * @param string $step "content", "options", "postmeta", "termmeta" or "attachments"
     * @param int $cursor Position within the current step
     * @param array<string, int> $acc Running totals
     * @return array<string, mixed>
     */
    private function preview_replace(string $step, int $cursor, array $acc): array
    {
        $support = CDG_Core_WebP_Support::get_instance();
        $next = ["content" => "options", "options" => "postmeta", "postmeta" => "termmeta", "termmeta" => "attachments"];
        $deadline = microtime(true) + 15;
        $done = false;
        $processed = 0;
        $total = 0;

        if ($step === "content") {
            $ids = $this->get_replaceable_post_ids($cursor, self::BATCH_SIZE);
            $total = $this->count_replaceable();
            foreach ($ids as $post_id) {
                if (microtime(true) > $deadline) {
                    break;
                }
                $processed++;
                $acc["posts_scanned"] = ($acc["posts_scanned"] ?? 0) + 1;
                $post = get_post($post_id);
                if ($post && is_string($post->post_content) && $post->post_content !== "" &&
                    $support->rewrite_urls($post->post_content) !== $post->post_content) {
                    $acc["posts"] = ($acc["posts"] ?? 0) + 1;
                }
            }
            $finished = empty($ids);
        } elseif (isset($next[$step]) && $step !== "content") {
            $r = $this->rewrite_meta_batch($step, $cursor, false, 100, true);
            $acc["values"] = ($acc["values"] ?? 0) + $r["modified"];
            $finished = $r["seen"] === 0;
            $cursor = $r["last"];
        } else {
            $ids = $this->get_candidate_ids($cursor, self::BATCH_SIZE);
            $total = $this->count_candidates();
            foreach ($ids as $id) {
                if (microtime(true) > $deadline) {
                    break;
                }
                $processed++;
                $plan = $this->plan_attachment_switch((int) $id);
                if ($plan === null) {
                    $acc["skipped"] = ($acc["skipped"] ?? 0) + 1;
                } else {
                    $acc["switch"] = ($acc["switch"] ?? 0) + 1;
                    $acc["files"] = ($acc["files"] ?? 0) + count(array_filter($plan["old_files"], "file_exists"));
                    $acc["bytes"] = ($acc["bytes"] ?? 0) + $plan["bytes"];
                }
            }
            $finished = empty($ids);
            $done = $finished;
        }

        if ($finished && !$done) {
            $step = $next[$step];
            $cursor = 0;
        } elseif (!$finished) {
            $cursor += $processed;
        }

        $out = [
            "done" => $done,
            "step" => $step,
            "cursor" => $cursor,
            "total" => $total,
            "acc" => $acc,
        ];
        if ($done) {
            $out["backup_ok"] = $this->has_fresh_backup();
        }
        return $out;
    }

    /**
     * Dry run for step 4 (free up space).
     *
     * @param int $cursor Last metadata row handled
     * @param array<string, int> $acc Running totals
     * @return array<string, mixed>
     */
    private function preview_cleanup(int $cursor, array $acc): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_wp_attachment_metadata' AND meta_value LIKE %s AND meta_id > %d
             ORDER BY meta_id LIMIT %d",
            "%original_image%",
            $cursor,
            self::BATCH_SIZE,
        ));

        $deadline = microtime(true) + 15;
        foreach ((array) $rows as $row) {
            if (microtime(true) > $deadline) {
                break;
            }
            $cursor = (int) $row->meta_id;
            $acc["seen"] = ($acc["seen"] ?? 0) + 1;
            $result = $this->remove_leftover_original((int) $row->post_id, true);
            if ($result === null) {
                $acc["in_use"] = ($acc["in_use"] ?? 0) + 1;
            } elseif ($result === -1) {
                $acc["missing"] = ($acc["missing"] ?? 0) + 1;
            } else {
                $acc["deletable"] = ($acc["deletable"] ?? 0) + 1;
                $acc["bytes"] = ($acc["bytes"] ?? 0) + $result;
            }
        }

        return [
            "done" => empty($rows),
            "step" => "cleanup",
            "cursor" => $cursor,
            "total" => $this->count_leftover_originals(),
            "acc" => $acc,
        ];
    }

    /**
     * AJAX: return current status, last run, and backup info for the
     * settings page to render on initial load.
     */
    public function ajax_get_status(): void
    {
        check_ajax_referer("cdg_webp", "nonce");

        if (!$this->current_user_can_run()) {
            wp_send_json_error(["message" => "forbidden"], 403);
        }

        $support = CDG_Core_WebP_Support::get_instance();
        $by_mime = wp_count_attachments();

        // Never expose server paths to the browser.
        $backups = array_map(static function ($b): array {
            return [
                "filename" => (string) ($b["filename"] ?? ""),
                "created_at" => (int) ($b["created_at"] ?? 0),
                "expires_at" => (int) ($b["expires_at"] ?? 0),
                "files" => (int) ($b["files"] ?? 0),
                "bytes" => (int) ($b["bytes"] ?? 0),
            ];
        }, (new CDG_Core_WebP_Backup())->list_backups());

        wp_send_json_success([
            "enabled" => $this->feature_enabled(),
            "mode" => $support ? $support->get_mode() : "convert",
            "converter" => $support ? $support->get_converter() : null,
            "candidates" => $this->count_candidates(),
            "webp_attachments" => (int) ($by_mime->{"image/webp"} ?? 0),
            "fresh_backup" => $this->has_fresh_backup(),
            "leftover_originals" => $this->count_leftover_originals(),
            "status" => $this->get_status(),
            "last_run" => get_option(self::LAST_RUN_OPTION, null),
            "last_replace" => get_option(self::LAST_REPLACE_OPTION, null),
            "backups" => $backups,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // WP-CLI integration
    // ─────────────────────────────────────────────────────────────

    /**
     * Register WP-CLI commands if the CLI is present.
     *
     * @return void
     */
    public function register_cli_commands(): void
    {
        if (!defined("WP_CLI") || !WP_CLI) {
            return;
        }

        \WP_CLI::add_command("cdg webp convert", function (): void {
            $this->cli_convert();
        });
        \WP_CLI::add_command("cdg webp replace", function (): void {
            $this->cli_replace();
        });
        \WP_CLI::add_command("cdg webp restore", function (): void {
            $this->cli_restore();
        });
        \WP_CLI::add_command("cdg webp status", function (): void {
            $status = $this->get_status();
            \WP_CLI::log(json_encode($status, JSON_PRETTY_PRINT));
        });
    }

    /**
     * CLI: drive a full bulk conversion to completion.
     */
    public function cli_convert(): void
    {
        $this->set_status([
            "phase" => "converting",
            "started_at" => time(),
            "cursor" => 0,
            "total" => $this->count_candidates(),
            "converted" => 0,
            "skipped" => 0,
            "failed" => 0,
            "done" => false,
            "error" => null,
        ]);

        $progress = \WP_CLI\Utils\make_progress_bar(
            "Converting images",
            (int) $this->get_status()["total"],
        );

        $support = CDG_Core_WebP_Support::get_instance();
        if ($support === null) {
            \WP_CLI::error("WebP support class is not loaded.");
        }

        do {
            $status = $this->get_status();
            $before = (int) ($status["converted"] ?? 0);
            $this->process_batch();
            $status = $this->get_status();
            $delta =
                (int) ($status["converted"] ?? 0) -
                $before +
                (int) ($status["skipped"] ?? 0) +
                (int) ($status["failed"] ?? 0);
            $progress->tick($delta);
        } while (empty($status["done"]));

        $progress->finish();
        \WP_CLI::success(sprintf(
            "Converted %d, skipped %d, failed %d.",
            $status["converted"] ?? 0,
            $status["skipped"] ?? 0,
            $status["failed"] ?? 0,
        ));
    }

    /**
     * CLI: drive the full replace step to completion.
     */
    public function cli_replace(): void
    {
        $blocked = $this->replace_gate();
        if ($blocked !== null) {
            \WP_CLI::error($blocked["message"]);
        }

        $this->set_status($this->init_replace_status());

        do {
            $this->process_replace_batch();
            $status = $this->get_status();
        } while (empty($status["done"]));

        \WP_CLI::success(sprintf(
            "Scanned %d posts, modified %d (plus %d options/meta values); switched %d attachments (%d skipped), deleted %d files.",
            $status["posts_scanned"] ?? 0,
            $status["posts_modified"] ?? 0,
            $status["meta_modified"] ?? 0,
            $status["attachments_switched"] ?? 0,
            $status["attachments_skipped"] ?? 0,
            $status["files_deleted"] ?? 0,
        ));
    }

    /**
     * CLI: restore from backup.
     */
    public function cli_restore(): void
    {
        $latest = (new CDG_Core_WebP_Backup())->get_latest_backup();
        if ($latest === null) {
            \WP_CLI::error("No backup zip available.");
        }
        $result = $this->run_restore($latest);
        if (is_wp_error($result)) {
            \WP_CLI::error($result->get_error_message());
        }
        \WP_CLI::success(sprintf(
            "Restored %d files and %d attachments; reversed %d posts.",
            $result["files_restored"],
            $result["attachments_restored"],
            $result["posts_modified"],
        ));
    }

    // ─────────────────────────────────────────────────────────────
    // Bulk conversion core
    // ─────────────────────────────────────────────────────────────

    /**
     * Process one batch of the bulk conversion job.
     *
     * @return void
     */
    private function process_batch(): void
    {
        $status = $this->get_status();
        $cursor = (int) ($status["cursor"] ?? 0);
        $total = (int) ($status["total"] ?? 0);

        $support = CDG_Core_WebP_Support::get_instance();
        if ($support === null) {
            $status["error"] = "WebP support not loaded.";
            $status["done"] = true;
            $this->set_status($status);
            return;
        }

        // Page through attachment IDs. We use attachment IDs (not file
        // paths) because every size variant is registered against an
        // attachment; converting by ID ensures we cover thumbnail/medium/
        // large/divi sizes in one go.
        $ids = $this->get_candidate_ids($cursor, self::BATCH_SIZE);
        if (empty($ids)) {
            $status["done"] = true;
            $status["phase"] = "done";
            update_option(self::LAST_RUN_OPTION, [
                "finished_at" => time(),
                "converted" => $status["converted"] ?? 0,
                "skipped" => $status["skipped"] ?? 0,
                "failed" => $status["failed"] ?? 0,
                "outcome" => "completed",
            ]);
            $this->set_status($status);
            return;
        }

        $converted = (int) ($status["converted"] ?? 0);
        $skipped = (int) ($status["skipped"] ?? 0);
        $failed = (int) ($status["failed"] ?? 0);

        // Stop early when a batch is slow (big images, compression) so a
        // single request never runs into PHP's time limit; the cursor
        // only advances past what was actually processed.
        $deadline = microtime(true) + 15;
        $processed = 0;

        foreach ($ids as $id) {
            if (microtime(true) > $deadline) {
                break;
            }
            $processed++;
            $file = get_attached_file($id);
            if (!is_string($file) || empty($file) || !file_exists($file)) {
                $skipped++;
                continue;
            }
            if (!$support->should_convert($file)) {
                $skipped++;
                continue;
            }

            $meta = wp_get_attachment_metadata($id);
            $size_files = [];
            if (is_array($meta) && !empty($meta["sizes"])) {
                $dir = trailingslashit(dirname($file));
                foreach ((array) $meta["sizes"] as $size) {
                    if (!empty($size["file"])) {
                        $size_files[] = $dir . $size["file"];
                    }
                }
            }

            // Compress at most once per attachment (see COMPRESSED_META).
            $compress = !get_post_meta($id, CDG_Core_WebP_Support::COMPRESSED_META, true);
            $ok = $support->process_file($file, $compress);
            foreach ($size_files as $sf) {
                $support->process_file($sf, $compress);
            }
            if ($compress && $support->mode_compresses()) {
                update_post_meta($id, CDG_Core_WebP_Support::COMPRESSED_META, 1);
            }

            if ($ok) {
                $converted++;
            } else {
                $failed++;
            }
        }

        $status["cursor"] = $cursor + $processed;
        $status["converted"] = $converted;
        $status["skipped"] = $skipped;
        $status["failed"] = $failed;
        $this->set_status($status);
    }

    /**
     * Get the IDs of attachments that have a PNG or JPG file attached.
     *
     * @param int $offset Offset into the candidate list
     * @param int $limit Max IDs to return
     * @return int[]
     */
    private function get_candidate_ids(int $offset, int $limit): array
    {
        $query = new \WP_Query([
            "post_type" => "attachment",
            "post_status" => "inherit",
            "post_mime_type" => ["image/jpeg", "image/png"],
            "posts_per_page" => $limit,
            "offset" => $offset,
            "fields" => "ids",
            "no_found_rows" => true,
            "orderby" => "ID",
            "order" => "ASC",
        ]);
        return is_array($query->posts) ? $query->posts : [];
    }

    /**
     * Count attachments that are PNG or JPG.
     *
     * @return int
     */
    private function count_candidates(): int
    {
        $by_mime = wp_count_attachments();
        return (int) ($by_mime->{"image/jpeg"} ?? 0) + (int) ($by_mime->{"image/png"} ?? 0);
    }

    // ─────────────────────────────────────────────────────────────
    // Replace step core
    // ─────────────────────────────────────────────────────────────

    /**
     * Preconditions for the destructive replace step.
     *
     * @return array{message:string, missing?:string[]}|null Null when clear to run
     */
    private function replace_gate(): ?array
    {
        if (!$this->has_fresh_backup()) {
            return [
                "message" =>
                    "No up-to-date backup. Create a new backup first (it must be under 24 hours old and newer than your latest upload).",
            ];
        }

        // Refuse to run if any original is missing its WebP sibling — that
        // would create broken image references after the rewrite.
        $missing = $this->find_orphans();
        if (!empty($missing)) {
            return [
                "message" => sprintf(
                    "%d image file(s) are missing WebP siblings. Run bulk conversion again before replacing.",
                    count($missing),
                ),
                "missing" => array_slice($missing, 0, 20),
            ];
        }

        return null;
    }

    /**
     * Is there a backup that actually covers the library as it is now?
     *
     * It must be a non-expired zip from the last 24 hours, and no PNG/JPG
     * attachment may have been added or edited since it was made —
     * otherwise those originals would be deleted with no copy to restore.
     *
     * @return bool
     */
    private function has_fresh_backup(): bool
    {
        $latest = (new CDG_Core_WebP_Backup())->get_latest_backup();
        if ($latest === null) {
            return false;
        }

        $created = (int) ($latest["created_at"] ?? 0);
        if ($created < time() - DAY_IN_SECONDS) {
            return false;
        }

        $newer = new \WP_Query([
            "post_type" => "attachment",
            "post_status" => "inherit",
            "post_mime_type" => ["image/jpeg", "image/png"],
            "posts_per_page" => 1,
            "fields" => "ids",
            "no_found_rows" => true,
            "date_query" => [
                [
                    "column" => "post_modified_gmt",
                    "after" => gmdate("Y-m-d H:i:s", $created),
                ],
            ],
        ]);

        return empty($newer->posts);
    }

    /**
     * Fresh status for a replace job.
     *
     * The job has two steps, run strictly in order: first every post's
     * content is rewritten to point at the WebP files, and only then are
     * the attachments switched over and the originals deleted. Deleting
     * earlier would break posts that haven't been rewritten yet.
     *
     * @return array<string, mixed>
     */
    private function init_replace_status(): array
    {
        return [
            "phase" => "replacing",
            "step" => "content",
            "started_at" => time(),
            "cursor" => 0,
            "total" => $this->count_replaceable(),
            "posts_scanned" => 0,
            "posts_modified" => 0,
            "meta_modified" => 0,
            "attachments_switched" => 0,
            "attachments_skipped" => 0,
            "files_deleted" => 0,
            "done" => false,
            "error" => null,
        ];
    }

    /**
     * Process one batch of the destructive replace step.
     *
     * @return void
     */
    private function process_replace_batch(): void
    {
        $status = $this->get_status();
        $step = $status["step"] ?? "content";
        if ($step === "content") {
            $this->replace_content_step($status);
        } elseif ($step === "meta") {
            $this->replace_meta_step($status);
        } else {
            $this->replace_attachments_step($status);
        }
    }

    /**
     * Clear caches that hold copies of image URLs.
     *
     * Divi writes static CSS (with background-image URLs) and URL-to-ID
     * lookups into wp-content/et-cache. Its own clear function does
     * nothing outside a logged-in admin request (WP-CLI, cron) and only
     * marks files stale otherwise, so remove the cache files directly —
     * Divi rebuilds them on the next page view. Also flushes the object
     * cache and lets other code (page-cache plugins) hook in.
     *
     * @return void
     */
    private function clear_builder_caches(): void
    {
        $dir = trailingslashit(WP_CONTENT_DIR) . "et-cache";
        if (is_dir($dir)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                if ($item->isLink()) {
                    @unlink($item->getPathname());
                } elseif ($item->isDir()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                }
            }
        }

        if (function_exists("et_core_clear_wp_cache")) {
            et_core_clear_wp_cache();
        }
        wp_cache_flush();

        /**
         * Fires after CDG Core changes image URLs or files in bulk, so a
         * page cache can be purged.
         */
        do_action("cdg_webp_caches_cleared");
    }

    /**
     * Step 1: rewrite upload image URLs in every post's content.
     *
     * Covers every post type (pages, posts, reusable blocks, Divi library
     * and Theme Builder layouts, block templates...), and every URL form —
     * <img>, srcset, CSS url(), shortcode attributes and block JSON —
     * because it rewrites URLs wherever they appear in the content.
     *
     * @param array<string, mixed> $status Current job status
     * @return void
     */
    private function replace_content_step(array $status): void
    {
        $support = CDG_Core_WebP_Support::get_instance();
        if ($support === null) {
            $status["error"] = "WebP support not loaded.";
            $status["done"] = true;
            $this->set_status($status);
            return;
        }

        $ids = $this->get_replaceable_post_ids((int) ($status["cursor"] ?? 0), self::BATCH_SIZE);
        if (empty($ids)) {
            $this->clear_builder_caches();
            $status["step"] = "meta";
            $status["cursor"] = 0;
            $status["total"] = 3;
            $this->set_status($status);
            return;
        }

        $deadline = microtime(true) + 15;

        // Rewriting URLs isn't an editorial change — don't spawn a
        // revision for every post.
        remove_action("post_updated", "wp_save_post_revision");

        foreach ($ids as $post_id) {
            if (microtime(true) > $deadline) {
                break;
            }
            $status["cursor"]++;
            $status["posts_scanned"]++;

            $post = get_post($post_id);
            if (!$post || !is_string($post->post_content) || $post->post_content === "") {
                continue;
            }

            $rewritten = $support->rewrite_urls($post->post_content);
            if ($rewritten !== $post->post_content) {
                // wp_update_post() expects slashed data; without this
                // WordPress strips backslashes out of JSON and shortcodes.
                wp_update_post([
                    "ID" => $post_id,
                    "post_content" => wp_slash($rewritten),
                ]);
                $status["posts_modified"]++;
            }
        }

        add_action("post_updated", "wp_save_post_revision", 10, 1);

        $this->set_status($status);
    }

    /**
     * Step 2: rewrite image URLs stored in options (Divi logo/favicon,
     * Customizer, widgets), post meta (custom fields, builder settings)
     * and term meta.
     *
     * Works on raw database values: plain strings and JSON are rewritten
     * directly; PHP-serialized data is unserialized, walked and
     * re-serialized so string lengths stay valid. Anything containing a
     * PHP object is left alone.
     *
     * @param array<string, mixed> $status Current job status
     * @return void
     */
    private function replace_meta_step(array $status): void
    {
        $tables = ["options", "postmeta", "termmeta"];
        $i = (int) ($status["meta_table"] ?? 0);

        if ($i >= count($tables)) {
            $status["step"] = "attachments";
            $status["cursor"] = 0;
            $status["total"] = $this->count_candidates();
            $this->set_status($status);
            return;
        }

        $r = $this->rewrite_meta_batch($tables[$i], (int) ($status["meta_cursor"] ?? 0), false);
        if ($r["seen"] === 0) {
            $status["meta_table"] = $i + 1;
            $status["meta_cursor"] = 0;
        } else {
            $status["meta_cursor"] = $r["last"];
        }
        $status["meta_modified"] = (int) ($status["meta_modified"] ?? 0) + $r["modified"];
        $status["cursor"] = min($i + ($r["seen"] === 0 ? 1 : 0), count($tables));
        $status["total"] = count($tables);
        $this->set_status($status);
    }

    /**
     * Rewrite one batch of rows from options / postmeta / termmeta.
     *
     * @param string $table "options", "postmeta" or "termmeta"
     * @param int $cursor Last row ID handled
     * @param bool $reverse Map .webp back to the original format
     * @param int $limit Rows per batch
     * @param bool $dry Count what would change without writing anything
     * @return array{last:int, seen:int, modified:int}
     */
    private function rewrite_meta_batch(string $table, int $cursor, bool $reverse, int $limit = 100, bool $dry = false): array
    {
        global $wpdb;
        $like = "%uploads%";

        if ($table === "options") {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT option_id AS id, option_value AS v FROM {$wpdb->options}
                 WHERE option_id > %d AND option_value LIKE %s
                   AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s
                   AND option_name NOT IN ('siteurl', 'home')
                 ORDER BY option_id LIMIT %d",
                $cursor,
                $like,
                $wpdb->esc_like("_transient_") . "%",
                $wpdb->esc_like("_site_transient_") . "%",
                $wpdb->esc_like("cdg_") . "%",
                $limit,
            ));
            $target = [$wpdb->options, "option_value", "option_id"];
        } elseif ($table === "postmeta") {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_id AS id, meta_value AS v FROM {$wpdb->postmeta}
                 WHERE meta_id > %d AND meta_value LIKE %s
                   AND meta_key NOT IN ('_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', '_cdg_webp_original')
                 ORDER BY meta_id LIMIT %d",
                $cursor,
                $like,
                $limit,
            ));
            $target = [$wpdb->postmeta, "meta_value", "meta_id"];
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_id AS id, meta_value AS v FROM {$wpdb->termmeta}
                 WHERE meta_id > %d AND meta_value LIKE %s ORDER BY meta_id LIMIT %d",
                $cursor,
                $like,
                $limit,
            ));
            $target = [$wpdb->termmeta, "meta_value", "meta_id"];
        }

        $modified = 0;
        $last = $cursor;
        foreach ((array) $rows as $row) {
            $last = (int) $row->id;
            $changed = false;
            $new = $this->rewrite_stored_value((string) $row->v, $reverse, $changed);
            if ($changed && $new !== $row->v) {
                if (!$dry) {
                    $wpdb->update($target[0], [$target[1] => $new], [$target[2] => $row->id]);
                }
                $modified++;
            }
        }

        return ["last" => $last, "seen" => count((array) $rows), "modified" => $modified];
    }

    /**
     * Rewrite image URLs inside a raw stored value (string, JSON or
     * PHP-serialized), keeping its format intact.
     *
     * @param mixed $value Raw value
     * @param bool $reverse Map .webp back to the original format
     * @param bool $changed Set to true when anything was rewritten
     * @return mixed
     */
    private function rewrite_stored_value($value, bool $reverse, bool &$changed)
    {
        $support = CDG_Core_WebP_Support::get_instance();
        if ($support === null) {
            return $value;
        }

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->rewrite_stored_value($v, $reverse, $changed);
            }
            return $value;
        }

        if ($value instanceof \stdClass) {
            foreach (get_object_vars($value) as $k => $v) {
                $value->$k = $this->rewrite_stored_value($v, $reverse, $changed);
            }
            return $value;
        }

        if (!is_string($value) || stripos($value, "uploads") === false) {
            return $value;
        }

        if (is_serialized($value)) {
            // Plain stdClass objects are common (Divi stores them); any other
            // class means unknown behavior on re-serialize, so leave it be.
            $inner = @unserialize($value, ["allowed_classes" => ["stdClass"]]);
            if ($inner === false && $value !== "b:0;") {
                return $value;
            }
            if ($this->contains_foreign_object($inner)) {
                return $value;
            }
            $inner_changed = false;
            $inner = $this->rewrite_stored_value($inner, $reverse, $inner_changed);
            if (!$inner_changed) {
                return $value;
            }
            $changed = true;
            return serialize($inner);
        }

        $new = $support->rewrite_urls($value, $reverse);
        if ($new !== $value) {
            $changed = true;
        }
        return $new;
    }

    /**
     * Does a value contain a PHP object other than stdClass?
     *
     * @param mixed $value
     * @return bool
     */
    private function contains_foreign_object($value): bool
    {
        if (is_object($value)) {
            if (!($value instanceof \stdClass)) {
                return true;
            }
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            foreach ($value as $v) {
                if ($this->contains_foreign_object($v)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Reverse the options / post meta / term meta rewrites (Restore).
     *
     * @return int Rows modified
     */
    private function restore_meta(): int
    {
        $modified = 0;
        foreach (["options", "postmeta", "termmeta"] as $table) {
            $cursor = 0;
            do {
                $r = $this->rewrite_meta_batch($table, $cursor, true);
                $cursor = $r["last"];
                $modified += $r["modified"];
            } while ($r["seen"] > 0);
        }
        return $modified;
    }

    /**
     * Step 3: turn each attachment into a native WebP attachment and
     * delete the original files.
     *
     * Switched attachments drop out of the PNG/JPG candidate query, so
     * the offset into that query is just the number skipped so far (the
     * skipped ones are always the earliest remaining IDs).
     *
     * @param array<string, mixed> $status Current job status
     * @return void
     */
    private function replace_attachments_step(array $status): void
    {
        $skipped = (int) ($status["attachments_skipped"] ?? 0);
        $ids = $this->get_candidate_ids($skipped, self::BATCH_SIZE);

        if (empty($ids)) {
            $this->clear_builder_caches();
            $status["done"] = true;
            $status["phase"] = "done";
            update_option(self::LAST_REPLACE_OPTION, [
                "finished_at" => time(),
                "files_deleted" => $status["files_deleted"] ?? 0,
                "posts_modified" => $status["posts_modified"] ?? 0,
                "meta_modified" => $status["meta_modified"] ?? 0,
                "attachments_switched" => $status["attachments_switched"] ?? 0,
                "attachments_skipped" => $status["attachments_skipped"] ?? 0,
                "outcome" => "completed",
            ]);
            $this->set_status($status);
            return;
        }

        $deadline = microtime(true) + 15;

        foreach ($ids as $id) {
            if (microtime(true) > $deadline) {
                break;
            }
            $deleted = $this->switch_attachment_to_webp((int) $id);
            if ($deleted === null) {
                $status["attachments_skipped"]++;
            } else {
                $status["attachments_switched"]++;
                $status["files_deleted"] += $deleted;
            }
            $status["cursor"]++;
        }

        $this->set_status($status);
    }

    /**
     * Make one attachment a native WebP attachment, then delete its
     * original PNG/JPG files.
     *
     * The attachment's file path, mime type and metadata (including every
     * size variant) all switch to .webp, so wp_get_attachment_url(),
     * srcsets and the Media Library keep working with no further
     * rewriting. What it needs to undo this is saved in post meta.
     *
     * Refuses (returns null, changes nothing) unless the full-size file
     * and every size variant already has a WebP sibling.
     *
     * @param int $id Attachment ID
     * @return int|null Files deleted, or null if the attachment was skipped
     */
    private function switch_attachment_to_webp(int $id): ?int
    {
        $plan = $this->plan_attachment_switch($id);
        if ($plan === null) {
            return null;
        }

        update_post_meta($id, "_cdg_webp_original", wp_slash([
            "file" => $plan["rel"],
            "mime" => get_post_mime_type($id),
            "meta" => $plan["meta"],
        ]));
        update_post_meta($id, "_wp_attached_file", $plan["new_rel"]);
        $this->set_attachment_mime($id, "image/webp");
        wp_update_attachment_metadata($id, $plan["new_meta"]);

        $deleted = 0;
        foreach ($plan["old_files"] as $old) {
            if (@unlink($old)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * Work out what switching an attachment to WebP would change, without
     * changing anything. Shared by the real run and the dry run, so the
     * preview can't disagree with what Replace actually does.
     *
     * @param int $id Attachment ID
     * @return array{rel:string,new_rel:string,meta:mixed,new_meta:array<string,mixed>,old_files:string[],bytes:int}|null
     *         Null when the attachment would be skipped
     */
    private function plan_attachment_switch(int $id): ?array
    {
        $support = CDG_Core_WebP_Support::get_instance();
        if ($support === null) {
            return null;
        }

        $file = get_attached_file($id);
        $rel = get_post_meta($id, "_wp_attached_file", true);
        if (
            !is_string($file) || $file === "" || !file_exists($file) ||
            !is_string($rel) || $rel === ""
        ) {
            return null;
        }
        $webp = $support->webp_path_for($file);
        if ($webp === null || !$support->webp_exists($webp)) {
            return null;
        }

        $meta = wp_get_attachment_metadata($id);
        $new_meta = is_array($meta) ? $meta : [];
        $old_files = [$file];
        $dir = trailingslashit(dirname($file));

        foreach ((array) ($new_meta["sizes"] ?? []) as $key => $size) {
            if (empty($size["file"])) {
                continue;
            }
            $size_webp = $support->webp_path_for($dir . $size["file"]);
            if ($size_webp === null) {
                continue;
            }
            if (!$support->webp_exists($size_webp)) {
                return null;
            }
            $old_files[] = $dir . $size["file"];
            $new_meta["sizes"][$key]["file"] = wp_basename($size_webp);
            $new_meta["sizes"][$key]["mime-type"] = "image/webp";
        }

        $to_webp = static function (string $path): string {
            return (string) preg_replace('/\.(jpe?g|png)$/i', ".webp", $path);
        };
        if (!empty($new_meta["file"]) && is_string($new_meta["file"])) {
            $new_meta["file"] = $to_webp($new_meta["file"]);
        }

        // Several size names can point at the same file; count it once.
        $old_files = array_values(array_unique($old_files));

        $bytes = 0;
        foreach ($old_files as $f) {
            $bytes += (int) @filesize($f);
        }

        return [
            "rel" => $rel,
            "new_rel" => $to_webp($rel),
            "meta" => $meta,
            "new_meta" => $new_meta,
            "old_files" => $old_files,
            "bytes" => $bytes,
        ];
    }

    /**
     * Set an attachment's mime type without firing save hooks.
     *
     * @param int $id Attachment ID
     * @param string $mime Mime type
     * @return void
     */
    private function set_attachment_mime(int $id, string $mime): void
    {
        global $wpdb;
        $wpdb->update($wpdb->posts, ["post_mime_type" => $mime], ["ID" => $id]);
        clean_post_cache($id);
    }

    /**
     * Get IDs of posts that might contain image references.
     *
     * @param int $offset
     * @param int $limit
     * @return int[]
     */
    private function get_replaceable_post_ids(int $offset, int $limit): array
    {
        $query = new \WP_Query([
            "post_type" => $this->get_target_post_types(),
            "post_status" => "any",
            "posts_per_page" => $limit,
            "offset" => $offset,
            "fields" => "ids",
            "no_found_rows" => true,
            "orderby" => "ID",
            "order" => "ASC",
            "suppress_filters" => true,
        ]);
        return is_array($query->posts) ? $query->posts : [];
    }

    /**
     * Every post type that has content in the database, public or not —
     * Divi Theme Builder layouts, reusable blocks and block templates are
     * all non-public but full of image URLs.
     *
     * Types come from the database as well as from registration: some
     * plugins (Divi's Theme Builder types, for one) only register their
     * post types on certain requests, so an admin-ajax run would otherwise
     * skip them while a WP-CLI run would not.
     *
     * @return string[]
     */
    private function get_target_post_types(): array
    {
        global $wpdb;

        $skip = [
            "attachment",
            "revision",
            "customize_changeset",
            "oembed_cache",
            "user_request",
            "nav_menu_item",
        ];
        $types = array_merge(
            get_post_types([], "names"),
            (array) $wpdb->get_col("SELECT DISTINCT post_type FROM {$wpdb->posts}"),
        );

        return array_values(array_diff(array_unique($types), $skip));
    }

    /**
     * Estimate how many posts will be scanned. Used to set the "total"
     * for the progress bar.
     *
     * @return int
     */
    private function count_replaceable(): int
    {
        $q = new \WP_Query([
            "post_type" => $this->get_target_post_types(),
            "post_status" => "any",
            "posts_per_page" => 1,
            "fields" => "ids",
            "no_found_rows" => false,
            "suppress_filters" => true,
        ]);
        return (int) $q->found_posts;
    }

    /**
     * Absolute paths of an attachment's PNG/JPG files (full size plus
     * every size variant), built from the attachment's stored path so the
     * uploads folder layout doesn't matter.
     *
     * @param int $id Attachment ID
     * @return string[]
     */
    private function attachment_files(int $id): array
    {
        $file = get_attached_file($id);
        if (!is_string($file) || $file === "") {
            return [];
        }
        $files = [$file];
        $meta = wp_get_attachment_metadata($id);
        $dir = trailingslashit(dirname($file));
        foreach ((array) ($meta["sizes"] ?? []) as $size) {
            if (!empty($size["file"])) {
                $files[] = $dir . $size["file"];
            }
        }
        return array_values(array_filter($files, static function ($f): bool {
            return (bool) preg_match('/\.(jpe?g|png)$/i', $f) && file_exists($f);
        }));
    }

    /**
     * Find PNG/JPG files (full size and every size variant) that are
     * missing a WebP sibling. The replace step refuses to run while any
     * are missing. Pages through the whole library.
     *
     * @return string[] Missing file paths (capped at ~100)
     */
    private function find_orphans(): array
    {
        $support = CDG_Core_WebP_Support::get_instance();
        if ($support === null) {
            return [];
        }

        $missing = [];
        $offset = 0;
        $page = 200;
        do {
            $ids = $this->get_candidate_ids($offset, $page);
            foreach ($ids as $id) {
                foreach ($this->attachment_files((int) $id) as $f) {
                    $webp = $support->webp_path_for($f);
                    if ($webp !== null && !$support->webp_exists($webp)) {
                        $missing[] = $f;
                        if (count($missing) > 100) {
                            break 3;
                        }
                    }
                }
            }
            $offset += $page;
        } while (count($ids) === $page);

        return $missing;
    }

    // ─────────────────────────────────────────────────────────────
    // Leftover originals cleanup
    // ─────────────────────────────────────────────────────────────

    /**
     * Post meta recording an original_image file that was removed, so
     * Restore can point the attachment back at it.
     */
    private const REMOVED_ORIGINAL_META = "_cdg_original_image_removed";

    /**
     * How many attachments still carry a separate "original image"
     * (WordPress keeps the full-size upload beside its scaled or rotated
     * copy).
     *
     * @return int
     */
    private function count_leftover_originals(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE meta_key = '_wp_attachment_metadata' AND meta_value LIKE '%original_image%'",
        );
    }

    /**
     * Process one batch of the cleanup job.
     *
     * @return void
     */
    private function process_cleanup_batch(): void
    {
        global $wpdb;
        $status = $this->get_status();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_wp_attachment_metadata' AND meta_value LIKE %s AND meta_id > %d
             ORDER BY meta_id LIMIT %d",
            "%original_image%",
            (int) ($status["cursor"] ?? 0),
            self::BATCH_SIZE,
        ));

        if (empty($rows)) {
            $status["done"] = true;
            $status["phase"] = "done";
            update_option(self::LAST_REPLACE_OPTION, [
                "cleanup_at" => time(),
                "deleted" => $status["deleted"] ?? 0,
                "in_use" => $status["in_use"] ?? 0,
                "bytes_freed" => $status["bytes_freed"] ?? 0,
            ] + (array) get_option(self::LAST_REPLACE_OPTION, []));
            $this->set_status($status);
            return;
        }

        $deadline = microtime(true) + 15;

        foreach ($rows as $row) {
            if (microtime(true) > $deadline) {
                break;
            }
            $status["cursor"] = (int) $row->meta_id;
            $status["seen"]++;

            $result = $this->remove_leftover_original((int) $row->post_id);
            if ($result === null) {
                $status["in_use"]++;
            } elseif ($result === -1) {
                $status["missing"]++;
            } else {
                $status["deleted"]++;
                $status["bytes_freed"] += $result;
            }
        }

        $this->set_status($status);
    }

    /**
     * Delete one attachment's leftover original, if that is safe.
     *
     * The file is only removed when ALL of these hold:
     *  - the attachment's own current file exists, so the image keeps
     *    working;
     *  - the original's filename appears nowhere in post content (any
     *    post type or status, including revisions), custom fields or
     *    options — so no page, Divi layout, setting or saved revision
     *    points at it.
     * The attachment's metadata is then updated so WordPress no longer
     * expects the file, and what was removed is recorded for Restore.
     *
     * @param int $id Attachment ID
     * @param bool $dry Report what would happen without deleting anything
     * @return int|null Bytes freed; -1 if there was nothing to delete;
     *                  null if skipped because it is still referenced
     */
    private function remove_leftover_original(int $id, bool $dry = false): ?int
    {
        $meta = wp_get_attachment_metadata($id);
        $file = get_attached_file($id);
        if (!is_array($meta) || empty($meta["original_image"]) || !is_string($file) || $file === "") {
            return -1;
        }

        // The image itself must still be there.
        if (!is_file($file) || filesize($file) === 0) {
            return null;
        }

        $name = wp_basename((string) $meta["original_image"]);
        if ($name === wp_basename($file)) {
            return -1;
        }
        $path = trailingslashit(dirname($file)) . $name;

        if (file_exists($path) && $this->original_is_referenced($name, $id)) {
            return null;
        }

        $bytes = file_exists($path) ? (int) filesize($path) : 0;
        if ($dry) {
            return $bytes;
        }
        if (file_exists($path) && !@unlink($path)) {
            return null;
        }

        update_post_meta($id, self::REMOVED_ORIGINAL_META, wp_slash($meta["original_image"]));
        unset($meta["original_image"]);
        wp_update_attachment_metadata($id, $meta);

        return $bytes;
    }

    /**
     * Does a filename appear anywhere content, custom fields or options
     * could use it? Deliberately broad: a partial match counts as "in
     * use" and keeps the file.
     *
     * @param string $name File name, e.g. "photo.jpg"
     * @param int $attachment_id The attachment that owns it (its own rows don't count)
     * @return bool
     */
    private function original_is_referenced(string $name, int $attachment_id): bool
    {
        global $wpdb;
        $like = "%" . $wpdb->esc_like($name) . "%";

        $in_posts = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND ID <> %d LIMIT 1",
            $like,
            $attachment_id,
        ));
        if ($in_posts) {
            return true;
        }

        $in_meta = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_id FROM {$wpdb->postmeta}
             WHERE meta_value LIKE %s
               AND meta_key NOT IN ('_wp_attachment_metadata', '_wp_attached_file', '_wp_attachment_backup_sizes', '_cdg_webp_original', %s)
             LIMIT 1",
            $like,
            self::REMOVED_ORIGINAL_META,
        ));
        if ($in_meta) {
            return true;
        }

        $in_options = $wpdb->get_var($wpdb->prepare(
            "SELECT option_id FROM {$wpdb->options}
             WHERE option_value LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s
             LIMIT 1",
            $like,
            $wpdb->esc_like("_transient_") . "%",
            $wpdb->esc_like("_site_transient_") . "%",
            $wpdb->esc_like("cdg_") . "%",
        ));
        if ($in_options) {
            return true;
        }

        $in_terms = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_id FROM {$wpdb->termmeta} WHERE meta_value LIKE %s LIMIT 1",
            $like,
        ));

        return (bool) $in_terms;
    }

    /**
     * Point attachments back at leftover originals that Restore just
     * brought back from the backup.
     *
     * @return int Attachments updated
     */
    private function restore_original_images(): int
    {
        $ids = get_posts([
            "post_type" => "attachment",
            "post_status" => "inherit",
            "meta_key" => self::REMOVED_ORIGINAL_META,
            "fields" => "ids",
            "posts_per_page" => -1,
            "no_found_rows" => true,
            "suppress_filters" => true,
        ]);

        $restored = 0;
        foreach ($ids as $id) {
            $name = get_post_meta($id, self::REMOVED_ORIGINAL_META, true);
            $file = get_attached_file($id);
            if (!is_string($name) || $name === "" || !is_string($file) || $file === "") {
                continue;
            }
            if (!file_exists(trailingslashit(dirname($file)) . $name)) {
                continue;
            }
            $meta = wp_get_attachment_metadata($id);
            if (is_array($meta)) {
                $meta["original_image"] = $name;
                wp_update_attachment_metadata($id, $meta);
            }
            delete_post_meta($id, self::REMOVED_ORIGINAL_META);
            $restored++;
        }

        return $restored;
    }

    // ─────────────────────────────────────────────────────────────
    // Restore
    // ─────────────────────────────────────────────────────────────

    /**
     * Restore originals from a backup zip, then reverse the attachment
     * and content changes.
     *
     * @param array<string, mixed> $backup Backup entry
     * @return array<string, int>|WP_Error
     */
    private function run_restore(array $backup)
    {
        $result = (new CDG_Core_WebP_Backup())->restore_from_zip($backup["path"]);
        if (is_wp_error($result)) {
            return $result;
        }

        // The restored originals are uncompressed again.
        delete_post_meta_by_key(CDG_Core_WebP_Support::COMPRESSED_META);

        $attachments = $this->restore_attachments();
        $this->restore_original_images();
        $content = $this->restore_content();
        $meta = $this->restore_meta();

        $summary = [
            "files_restored" => (int) ($result["files"] ?? 0),
            "attachments_restored" => $attachments,
            "posts_modified" => (int) ($content["posts_modified"] ?? 0),
            "meta_modified" => $meta,
        ];
        update_option(self::LAST_REPLACE_OPTION, $summary + ["restored_at" => time()]);

        return $summary;
    }

    /**
     * Point switched attachments back at their restored originals.
     *
     * @return int Attachments restored
     */
    private function restore_attachments(): int
    {
        $ids = get_posts([
            "post_type" => "attachment",
            "post_status" => "inherit",
            "meta_key" => "_cdg_webp_original",
            "fields" => "ids",
            "posts_per_page" => -1,
            "no_found_rows" => true,
            "suppress_filters" => true,
        ]);

        $basedir = trailingslashit(wp_get_upload_dir()["basedir"]);
        $restored = 0;

        foreach ($ids as $id) {
            $orig = get_post_meta($id, "_cdg_webp_original", true);
            if (!is_array($orig) || empty($orig["file"])) {
                continue;
            }
            // Only switch back if the backup actually brought the file back.
            if (!file_exists($basedir . $orig["file"])) {
                continue;
            }
            update_post_meta($id, "_wp_attached_file", $orig["file"]);
            $this->set_attachment_mime($id, (string) ($orig["mime"] ?? "image/jpeg"));
            if (is_array($orig["meta"] ?? null)) {
                wp_update_attachment_metadata($id, $orig["meta"]);
            }
            delete_post_meta($id, "_cdg_webp_original");
            $restored++;
        }

        return $restored;
    }

    /**
     * Reverse the content rewrites after a restore: .webp URLs go back to
     * the original format wherever the original exists again.
     *
     * @return array{posts_scanned:int, posts_modified:int}
     */
    private function restore_content(): array
    {
        $support = CDG_Core_WebP_Support::get_instance();

        $status = [
            "phase" => "restoring",
            "cursor" => 0,
            "total" => $this->count_replaceable(),
            "posts_scanned" => 0,
            "posts_modified" => 0,
            "done" => false,
        ];
        $this->set_status($status);

        $scanned = 0;
        $modified = 0;
        $cursor = 0;

        if ($support !== null) {
            remove_action("post_updated", "wp_save_post_revision");
            do {
                $ids = $this->get_replaceable_post_ids($cursor, self::BATCH_SIZE);
                foreach ($ids as $post_id) {
                    $scanned++;
                    $cursor++;
                    $post = get_post($post_id);
                    if (!$post || !is_string($post->post_content) || $post->post_content === "") {
                        continue;
                    }
                    $rewritten = $support->rewrite_urls($post->post_content, true);
                    if ($rewritten !== $post->post_content) {
                        wp_update_post([
                            "ID" => $post_id,
                            "post_content" => wp_slash($rewritten),
                        ]);
                        $modified++;
                    }
                }
            } while (count($ids) === self::BATCH_SIZE);
            add_action("post_updated", "wp_save_post_revision", 10, 1);

            $this->clear_builder_caches();
        }

        $status["done"] = true;
        $status["posts_scanned"] = $scanned;
        $status["posts_modified"] = $modified;
        $this->set_status($status);

        return [
            "posts_scanned" => $scanned,
            "posts_modified" => $modified,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Get the current job status.
     *
     * @return array<string, mixed>
     */
    public function get_status(): array
    {
        $status = get_option(self::STATUS_OPTION, []);
        return is_array($status) ? $status : [];
    }

    /**
     * Save the current job status.
     *
     * @param array<string, mixed> $status
     * @return void
     */
    private function set_status(array $status): void
    {
        update_option(self::STATUS_OPTION, $status, false);
    }

    /**
     * Capability check for AJAX and CLI actions.
     *
     * @return bool
     */
    private function current_user_can_run(): bool
    {
        if (defined("WP_CLI") && WP_CLI) {
            return true;
        }
        if (!is_user_logged_in()) {
            return false;
        }
        if (!current_user_can("manage_options")) {
            return false;
        }
        return true;
    }
}
