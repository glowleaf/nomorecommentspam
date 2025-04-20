<?php
/**
 * Plugin Name: No More Comment Spam
 * Plugin URI: https://github.com/glowleaf/nomorecommentspam
 * Description: Prevent comment spam using Lightning Network and Nostr authentication
 * Version: 0.2.0
 * Author: glowleaf
 * Author URI: https://github.com/glowleaf
 * License: MIT
 * Text Domain: no-more-comment-spam
 */

if (!defined('ABSPATH')) {
    exit;
}

// Debug log to check if main plugin file is loaded
error_log('No More Comment Spam: Main plugin file loaded');

// Load the main plugin class
require_once plugin_dir_path(__FILE__) . 'class-no-more-comment-spam.php';

// Initialize the plugin
add_action('plugins_loaded', function() {
    error_log('No More Comment Spam: plugins_loaded action fired');
    No_More_Comment_Spam::init();
}); 