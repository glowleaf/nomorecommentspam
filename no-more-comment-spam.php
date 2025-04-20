<?php
/**
 * Plugin Name: No More Comment Spam
 * Description: Protect your WordPress comments from spam using Lightning Network payments and Nostr authentication
 * Version: 1.0.0
 * Author: glowleaf
 * Author URI: https://glowleaf.com
 * License: GPL v2 or later
 */

defined('ABSPATH') or die('No direct script access allowed.');

// DEBUG: Log that we're trying to load
error_log('NMCS: Main plugin file loaded at ' . __FILE__);

// DEBUG: Check if class file exists
$class_file = dirname(__FILE__) . '/class-no-more-comment-spam.php';
error_log('NMCS: Looking for class file at: ' . $class_file);
error_log('NMCS: Class file exists: ' . (file_exists($class_file) ? 'YES' : 'NO'));

require_once dirname(__FILE__) . '/class-no-more-comment-spam.php';

// DEBUG: Check if class exists
error_log('NMCS: Class exists after require: ' . (class_exists('No_More_Comment_Spam') ? 'YES' : 'NO'));

No_More_Comment_Spam::init(); 