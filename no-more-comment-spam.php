<?php
/**
 * Plugin Name:       No More Comment Spam
 * Plugin URI:        https://github.com/glowleaf/nomorecommentspam
 * Description:       Protect your WordPress comments from spam using Lightning Network payments and Nostr authentication. Requires authentication before commenting to eliminate spam.
 * Version:           1.0.0
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            glowleaf
 * Author URI:        https://glowleaf.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       no-more-comment-spam
 * Domain Path:       /languages
 * Network:           false
 *
 * @package           NoMoreCommentSpam
 * @author            glowleaf
 * @copyright         2025 glowleaf
 * @license           GPL-2.0-or-later
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