<?php
/**
 * WebP Backup Class
 *
 * Creates timestamped zip backups of every PNG/JPG file in the uploads
 * directory before the destructive "Replace originals with WebP" step
 * runs. Stores zips in /wp-content/uploads/cdg-webp-backups/, records
 * metadata in a site option, and auto-deletes backups older than the
 * configured retention window via a daily cron.
 *
 * The Restore button reads the most recent non-expired backup. Without
 * a valid backup, both the Replace and Restore paths are disabled.
 *
 * @package CDG_Core
 * @since 1.10.0
 */

declare(strict_types=1);

class CDG_Core_WebP_Backup
{
    /**
     * Site option key for the list of known backups.
     */
    private const BACKUPS_OPTION = "cdg_webp_backups";

    /**
     * Site option key for the backup currently being built.
     */
    private const JOB_OPTION = "cdg_webp_backup_job";

    /**
     * Per-request budget while filling the zip.
     */
    private const TICK_FILES   = 400;
    private const TICK_SECONDS = 15;

    /**
     * Subdirectory of /wp-content/uploads/ where backup zips live.
     * Public so the admin UI can link to / scan the directory if needed.
     */
    public const BACKUP_SUBDIR = "cdg-webp-backups";

    /**
     * Daily cron hook. Scheduled in cdg-core.php.
     */
    public const CRON_HOOK = "cdg_core_webp_backup_expire";

    /**
     * Register the AJAX endpoint and the daily expiry cron.
     *
     * @return void
     */
    public function register_hooks(): void
    {
        add_action("wp_ajax_cdg_webp_create_backup", [$this, "ajax_create_backup"]);
        add_action("wp_ajax_cdg_webp_tick_backup", [$this, "ajax_tick_backup"]);
        add_action(self::CRON_HOOK, [$this, "expire_old_backups"]);
        add_action("init", static function (): void {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, "daily", self::CRON_HOOK);
            }
        });
    }

    /**
     * Build the absolute path to the backup subdirectory (creating it
     * if necessary).
     *
     * @return string
     */
    public function backup_dir(): string
    {
        $upload = wp_get_upload_dir();
        $dir = trailingslashit($upload["basedir"]) . self::BACKUP_SUBDIR;
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            // Belt-and-suspenders: keep direct PHP execution out of the
            // backup dir, even though it's already under wp-content.
            $ht = $dir . "/.htaccess";
            if (!file_exists($ht)) {
                @file_put_contents(
                    $ht,
                    "# Deny direct PHP execution in WebP backups\n" .
                        "<FilesMatch \"\\.(php|phtml|php3|php4|php5|phar)$\">\n" .
                        "  Require all denied\n" .
                        "</FilesMatch>\n",
                );
            }
            $idx = $dir . "/index.html";
            if (!file_exists($idx)) {
                @file_put_contents($idx, "");
            }
        }
        return $dir;
    }

    /**
     * AJAX: begin a backup. Lists every PNG/JPG into a manifest file and
     * opens a job; the zip itself is filled in by repeated ajax_tick_backup
     * calls so no single request can hit the web server's timeout.
     */
    public function ajax_create_backup(): void
    {
        check_ajax_referer("cdg_webp", "nonce");

        if (!current_user_can("manage_options")) {
            wp_send_json_error(["message" => "forbidden"], 403);
        }

        $result = $this->start_backup_job();
        if (is_wp_error($result)) {
            wp_send_json_error(["message" => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    /**
     * AJAX: add the next batch of files to the zip. Returns progress, or
     * the finished backup entry once every file is in.
     */
    public function ajax_tick_backup(): void
    {
        check_ajax_referer("cdg_webp", "nonce");

        if (!current_user_can("manage_options")) {
            wp_send_json_error(["message" => "forbidden"], 403);
        }

        $result = $this->tick_backup_job();
        if (is_wp_error($result)) {
            wp_send_json_error(["message" => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    /**
     * Start a new backup job, discarding any unfinished one.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function start_backup_job()
    {
        if (!class_exists("ZipArchive")) {
            return new \WP_Error(
                "no_zip",
                "PHP ZipArchive is not available on this server.",
            );
        }

        $this->discard_job();

        $upload = wp_get_upload_dir();
        $basedir = $upload["basedir"];
        $dir = $this->backup_dir();
        if (!is_writable($dir)) {
            return new \WP_Error(
                "not_writable",
                "The backup folder is not writable: {$dir}",
            );
        }

        $iter = $this->iter_original_files($basedir);
        if ($iter === null) {
            return new \WP_Error(
                "no_files",
                "No original PNG/JPG files found in uploads.",
            );
        }

        $token = wp_generate_password(12, false);
        $manifest = "{$dir}/.job-{$token}.list";
        $fh = @fopen($manifest, "wb");
        if (!$fh) {
            return new \WP_Error(
                "manifest_failed",
                "Could not write to the backup folder: {$dir}",
            );
        }
        $total = 0;
        foreach ($iter as $abs_path) {
            fwrite($fh, substr($abs_path, strlen($basedir)) . "\n");
            $total++;
        }
        fclose($fh);

        if ($total === 0) {
            @unlink($manifest);
            return new \WP_Error(
                "no_files",
                "No original PNG/JPG files found in uploads.",
            );
        }

        $stamp = gmdate("Ymd-His");
        $filename = "webp-originals-{$stamp}-{$token}.zip";

        update_option(
            self::JOB_OPTION,
            [
                "path" => "{$dir}/{$filename}",
                "filename" => $filename,
                "manifest" => $manifest,
                "offset" => 0,
                "total" => $total,
                "added" => 0,
                // Files edited after this moment make the backup stale.
                "started_at" => time(),
            ],
            false,
        );

        return ["done" => false, "added" => 0, "total" => $total];
    }

    /**
     * Add the next batch of files to the job's zip. Stops at a file or
     * time budget so each request stays short.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function tick_backup_job()
    {
        $job = get_option(self::JOB_OPTION);
        if (!is_array($job) || empty($job["manifest"]) || !is_file($job["manifest"])) {
            return new \WP_Error(
                "no_job",
                "No backup is in progress. Start a new one.",
            );
        }

        $upload = wp_get_upload_dir();
        $basedir = $upload["basedir"];
        $path = $job["path"];

        $zip = new \ZipArchive();
        $opened = $zip->open(
            $path,
            is_file($path) ? 0 : \ZipArchive::CREATE,
        );
        if ($opened !== true) {
            $this->discard_job();
            return new \WP_Error(
                "zip_open_failed",
                "Could not open backup zip for writing: {$path}",
            );
        }

        $fh = fopen($job["manifest"], "rb");
        if (!$fh) {
            $zip->close();
            $this->discard_job();
            return new \WP_Error("manifest_failed", "Could not read the backup file list.");
        }
        fseek($fh, (int) $job["offset"]);

        $deadline = microtime(true) + self::TICK_SECONDS;
        $batch = 0;
        while (
            $batch < self::TICK_FILES &&
            microtime(true) < $deadline &&
            ($line = fgets($fh)) !== false
        ) {
            $rel = trim($line, "\r\n");
            $abs = $basedir . $rel;
            if ($rel !== "" && is_file($abs) && is_readable($abs)) {
                $name = ltrim($rel, "/\\");
                $zip->addFile($abs, $name);
                // JPG/PNG are already compressed; storing them is far faster.
                if (method_exists($zip, "setCompressionName")) {
                    @$zip->setCompressionName($name, \ZipArchive::CM_STORE);
                }
                $job["added"]++;
            }
            $batch++;
        }
        $job["offset"] = (int) ftell($fh);
        $eof = feof($fh) || fgets($fh) === false;
        fclose($fh);

        // Writing the zip happens here, so it's inside this request's budget.
        $closed = $zip->close();
        if ($closed !== true) {
            $this->discard_job();
            return new \WP_Error(
                "zip_write_failed",
                "Could not write the backup zip. Check disk space and folder permissions.",
            );
        }

        if (!$eof) {
            update_option(self::JOB_OPTION, $job, false);
            return [
                "done" => false,
                "added" => (int) $job["added"],
                "total" => (int) $job["total"],
            ];
        }

        @unlink($job["manifest"]);
        delete_option(self::JOB_OPTION);

        if ((int) $job["added"] === 0 || !is_file($path)) {
            @unlink($path);
            return new \WP_Error(
                "no_files",
                "No original PNG/JPG files found in uploads.",
            );
        }

        clearstatcache(true, $path);
        $entry = [
            "path" => $path,
            "filename" => $job["filename"],
            "created_at" => (int) $job["started_at"],
            "expires_at" => time() + $this->get_retention_seconds(),
            "files" => (int) $job["added"],
            "bytes" => (int) filesize($path),
        ];

        $backups = get_option(self::BACKUPS_OPTION, []);
        if (!is_array($backups)) {
            $backups = [];
        }
        $backups[] = $entry;
        update_option(self::BACKUPS_OPTION, $backups, false);

        return ["done" => true, "backup" => $entry] + $entry;
    }

    /**
     * Remove an unfinished job's manifest and partial zip.
     *
     * @return void
     */
    private function discard_job(): void
    {
        $job = get_option(self::JOB_OPTION);
        if (is_array($job)) {
            if (!empty($job["manifest"])) {
                @unlink($job["manifest"]);
            }
            if (!empty($job["path"])) {
                @unlink($job["path"]);
            }
        }
        delete_option(self::JOB_OPTION);
    }

    /**
     * Yield absolute paths of every PNG/JPG file under $basedir, recursing
     * into year/month subdirectories. Uses RecursiveDirectoryIterator to
     * keep memory bounded on large libraries.
     *
     * @param string $basedir
     * @return \Generator|null
     */
    private function iter_original_files(string $basedir): ?\Generator
    {
        if (!is_dir($basedir)) {
            return null;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $basedir,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iter as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            if (!$file->isFile() || !$file->isReadable()) {
                continue;
            }
            $name = $file->getFilename();
            if (!preg_match('/\.(jpe?g|png)$/i', $name)) {
                continue;
            }
            // Don't back up the backup dir or anything inside it
            if (
                strpos(
                    $file->getPathname(),
                    "/" . self::BACKUP_SUBDIR . "/",
                ) !== false
            ) {
                continue;
            }
            yield $file->getPathname();
        }
    }

    /**
     * List known backup zips, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list_backups(): array
    {
        $backups = get_option(self::BACKUPS_OPTION, []);
        if (!is_array($backups)) {
            return [];
        }
        usort($backups, function ($a, $b) {
            return ($b["created_at"] ?? 0) <=> ($a["created_at"] ?? 0);
        });
        return $backups;
    }

    /**
     * Get the most recent non-expired backup, or null.
     *
     * @return array<string, mixed>|null
     */
    public function get_latest_backup(): ?array
    {
        $now = time();
        foreach ($this->list_backups() as $b) {
            if (!is_array($b)) {
                continue;
            }
            if (empty($b["path"]) || !file_exists($b["path"])) {
                continue;
            }
            if (
                isset($b["expires_at"]) &&
                (int) $b["expires_at"] > 0 &&
                (int) $b["expires_at"] < $now
            ) {
                continue;
            }
            return $b;
        }
        return null;
    }

    /**
     * Restore originals from a backup zip.
     *
     * The zip is assumed to have been created by create_backup_zip() and
     * therefore mirrors the original uploads directory layout. We extract
     * every entry on top of the current uploads dir, overwriting any .webp
     * or broken reference with the original PNG/JPG bytes.
     *
     * @param string $zip_path Absolute path to the zip
     * @return array<string, mixed>|WP_Error
     */
    public function restore_from_zip(string $zip_path)
    {
        if (!class_exists("ZipArchive")) {
            return new \WP_Error(
                "no_zip",
                "PHP ZipArchive is not available on this server.",
            );
        }
        if (!file_exists($zip_path)) {
            return new \WP_Error("not_found", "Backup zip not found.");
        }

        $upload = wp_get_upload_dir();
        $basedir = $upload["basedir"];

        $zip = new \ZipArchive();
        $opened = $zip->open($zip_path);
        if ($opened !== true) {
            return new \WP_Error(
                "zip_read_failed",
                "Could not read backup zip.",
            );
        }

        $files = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat) || empty($stat["name"])) {
                continue;
            }
            $rel = $stat["name"];
            // Directory entries, absolute paths and ".." are never valid.
            if (
                substr($rel, -1) === "/" ||
                $rel[0] === "/" ||
                strpos($rel, "..") !== false
            ) {
                continue;
            }
            $target = $basedir . "/" . $rel;
            $dir = dirname($target);
            // Create the folder first: a month folder can be gone, and
            // realpath() of a missing folder would silently skip the file.
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }
            $real_basedir = realpath($basedir);
            $real_dir = realpath($dir);
            if (
                $real_basedir === false ||
                $real_dir === false ||
                strpos($real_dir, $real_basedir) !== 0
            ) {
                continue;
            }
            $content = $zip->getFromIndex($i);
            if ($content === false || $content === null) {
                continue;
            }
            @file_put_contents($target, $content);
            $files++;
        }
        $zip->close();

        return [
            "files" => $files,
        ];
    }

    /**
     * Daily cron: delete backup zips and option entries that have
     * passed their expires_at timestamp.
     *
     * Hooked from CDG_Core::schedule_crons() in cdg-core.php.
     */
    public function expire_old_backups(): void
    {
        $backups = $this->list_backups();
        $now = time();
        $kept = [];
        foreach ($backups as $b) {
            if (!is_array($b)) {
                continue;
            }
            $expired =
                !empty($b["expires_at"]) &&
                (int) $b["expires_at"] > 0 &&
                (int) $b["expires_at"] <= $now;
            if ($expired) {
                if (!empty($b["path"]) && file_exists($b["path"])) {
                    @unlink($b["path"]);
                }
                continue;
            }
            $kept[] = $b;
        }
        update_option(self::BACKUPS_OPTION, $kept, false);
    }

    /**
     * Get the configured retention in seconds.
     *
     * @return int
     */
    public function get_retention_seconds(): int
    {
        $days = (int) cdg_core()->get_setting("webp_backup_retention_days", 30);
        $days = max(7, min(90, $days));
        return $days * DAY_IN_SECONDS;
    }
}
