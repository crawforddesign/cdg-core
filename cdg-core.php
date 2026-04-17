<?php
/**
 * CDG Core Loader
 *
 * This file loads the CDG Core mu-plugin from its directory.
 * Place this file in /wp-content/mu-plugins/cdg-core.php
 * Place the cdg-core folder in /wp-content/mu-plugins/cdg-core/
 *
 * @package CDG_Core
 * @version 1.3.1
 */

// Prevent direct access
if (!defined("ABSPATH")) {
  exit();
}

// Load the main plugin file
require_once __DIR__ . "/cdg-core/cdg-core-main.php";
