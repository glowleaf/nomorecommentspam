<?php
/**
 * Plugin Name: No More Comment Spam
 * Plugin URI: https://github.com/glowleaf/no-more-comment-spam
 * Description: Prevent comment spam by requiring authentication via Nostr or Lightning.
 * Version: 0.2.0
 * Author: Glowleaf
 * Author URI: https://georgesaoulidis.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: no-more-comment-spam
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load plugin class
require_once plugin_dir_path(__FILE__) . 'class-no-more-comment-spam.php';

// Initialize plugin
function no_more_comment_spam_init() {
    if (class_exists('No_More_Comment_Spam')) {
        No_More_Comment_Spam::init();
    }
}
add_action('plugins_loaded', 'no_more_comment_spam_init'); 