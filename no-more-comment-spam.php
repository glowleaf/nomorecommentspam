﻿<?php
/**
 * Plugin Name:       No More Comment Spam
 * Plugin URI:        https://github.com/glowleaf/nomorecommentspam
 * Description:       Prevent comment spam using Lightning Network payments and Nostr authentication.
 * Version:           1.0.1
 * Requires at least: 5.0
 * Tested up to:      6.4
 * Requires PHP:      7.4
 * Author:            glowleaf
 * Author URI:        https://georgesaoulidis.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       no-more-comment-spam
 * Domain Path:       /languages
 * Network:           false
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('NMCS_PLUGIN_FILE', __FILE__);
define('NMCS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NMCS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('NMCS_VERSION', '1.0.1');

// Check if the main class file exists before loading
$main_class_file = NMCS_PLUGIN_DIR . 'includes/class-no-more-comment-spam.php';
if (!file_exists($main_class_file)) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p><strong>No More Comment Spam:</strong> Main class file is missing. Please reinstall the plugin.</p></div>';
    });
    return;
}

// Load the main plugin class
require_once $main_class_file;

// Check if class exists before initializing
if (class_exists('No_More_Comment_Spam')) {
    // Initialize the plugin
    No_More_Comment_Spam::init();
} else {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p><strong>No More Comment Spam:</strong> Main class could not be loaded. Please reinstall the plugin.</p></div>';
    });
}

 