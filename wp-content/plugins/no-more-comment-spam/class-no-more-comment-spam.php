<?php
/**
 * Main plugin class
 */

if (!defined('ABSPATH')) {
    exit;
}

class No_More_Comment_Spam {
    const OPTION_KEY = 'no_more_comment_spam_options';
    const VERSION = '0.2.0';

    private static $instance = null;

    public static function init() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Admin: settings page
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);

        // Front‑end: scripts and form hooks
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('comment_form_top', [$this, 'render_auth_buttons']);
        add_filter('preprocess_comment', [$this, 'handle_comment_submission']);
    }

    private function opt($key, $default = null) {
        $options = get_option(self::OPTION_KEY, []);
        return isset($options[$key]) ? $options[$key] : $default;
    }

    public function register_settings() {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [$this, 'sanitize_options']);

        add_settings_section(
            'nmcs_auth',
            __('Authentication Settings', 'no-more-comment-spam'),
            null,
            'no-more-comment-spam'
        );

        add_settings_field(
            'auth_methods',
            __('Enabled Authentication Methods', 'no-more-comment-spam'),
            [$this, 'field_auth_methods'],
            'no-more-comment-spam',
            'nmcs_auth'
        );
    }

    public function sanitize_options($input) {
        $sanitized = [];
        
        if (isset($input['auth_methods']) && is_array($input['auth_methods'])) {
            $sanitized['auth_methods'] = array_map('sanitize_text_field', $input['auth_methods']);
        } else {
            $sanitized['auth_methods'] = [];
        }
        
        return $sanitized;
    }

    public function field_auth_methods() {
        $auth_methods = $this->opt('auth_methods', []);
        ?>
        <fieldset>
            <label>
                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[auth_methods][]" value="lightning" <?php checked(in_array('lightning', $auth_methods)); ?>>
                <?php _e('Lightning Login', 'no-more-comment-spam'); ?>
            </label><br>
            <label>
                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[auth_methods][]" value="nostr_browser" <?php checked(in_array('nostr_browser', $auth_methods)); ?>>
                <?php _e('Nostr Browser Extension', 'no-more-comment-spam'); ?>
            </label><br>
            <label>
                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[auth_methods][]" value="nostr_connect" <?php checked(in_array('nostr_connect', $auth_methods)); ?>>
                <?php _e('Nostr Connect', 'no-more-comment-spam'); ?>
            </label>
        </fieldset>
        <?php
    }

    public function add_settings_page() {
        add_options_page(
            __('No More Comment Spam', 'no-more-comment-spam'),
            __('No More Comment Spam', 'no-more-comment-spam'),
            'manage_options',
            'no-more-comment-spam',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_KEY);
                do_settings_sections('no-more-comment-spam');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function enqueue_scripts() {
        if (!is_singular() || !comments_open()) {
            return;
        }

        wp_enqueue_style(
            'no-more-comment-spam',
            plugins_url('css/no-more-comment-spam.css', __FILE__),
            [],
            self::VERSION
        );

        wp_enqueue_script(
            'no-more-comment-spam',
            plugins_url('js/no-more-comment-spam.js', __FILE__),
            ['jquery'],
            self::VERSION,
            true
        );

        $auth_methods = $this->opt('auth_methods', []);
        
        wp_localize_script('no-more-comment-spam', 'nmcsData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('no-more-comment-spam'),
            'nostrBrowserEnabled' => in_array('nostr_browser', $auth_methods),
            'nostrConnectEnabled' => in_array('nostr_connect', $auth_methods),
            'i18n' => [
                'nostr_extension_required' => __('Nostr browser extension required', 'no-more-comment-spam')
            ]
        ]);
    }

    public function render_auth_buttons() {
        $auth_methods = $this->opt('auth_methods', []);
        
        if (empty($auth_methods)) {
            return;
        }

        echo '<div class="nmcs-auth-container">';
        echo '<p class="nmcs-auth-title">' . esc_html__('Authenticate to comment:', 'no-more-comment-spam') . '</p>';
        
        if (in_array('lightning', $auth_methods)) {
            echo do_shortcode('[generatelnurl]');
        }
        
        if (in_array('nostr_connect', $auth_methods)) {
            echo '<div id="nmcs-nostr-container">';
            echo '<input type="hidden" name="nostr_pubkey" value="">';
            echo '</div>';
        }
        
        if (in_array('nostr_browser', $auth_methods)) {
            echo '<button type="button" class="nmcs-auth-btn nmcs-nostr-browser-btn" onclick="nmcsLoginWithNostrBrowser()">';
            echo '<span class="nmcs-auth-icon">🔑</span> ' . esc_html__('Login with Nostr Browser', 'no-more-comment-spam');
            echo '</button>';
        }
        
        echo '<div class="nmcs-auth-status" style="display:none;">';
        echo '<p class="nmcs-auth-success">' . esc_html__('✓ Authenticated', 'no-more-comment-spam') . '</p>';
        echo '</div>';
        echo '</div>';
    }

    public function handle_comment_submission($commentdata) {
        $auth_methods = $this->opt('auth_methods', []);
        
        if (empty($auth_methods)) {
            return $commentdata;
        }

        if (current_user_can('moderate_comments')) {
            return $commentdata;
        }

        $is_authenticated = false;

        if (in_array('nostr_connect', $auth_methods) || in_array('nostr_browser', $auth_methods)) {
            if (!empty($_POST['nostr_pubkey'])) {
                $is_authenticated = true;
            }
        }

        if (in_array('lightning', $auth_methods)) {
            if (is_user_logged_in()) {
                $is_authenticated = true;
            }
        }

        if (!$is_authenticated) {
            wp_die(
                __('Please authenticate to post a comment.', 'no-more-comment-spam'),
                __('Authentication Required', 'no-more-comment-spam'),
                ['response' => 403]
            );
        }

        return $commentdata;
    }
} 