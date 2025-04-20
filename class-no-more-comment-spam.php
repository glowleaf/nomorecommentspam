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
        // Try to load LNLogin if not already active
        if (!class_exists('LNLogin') && file_exists(dirname(__DIR__) . '/lnlogin-main/lnlogin.php')) {
            require_once dirname(__DIR__) . '/lnlogin-main/lnlogin.php';
        }

        // Admin: settings page
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);

        // Front‑end: scripts and form hooks
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('comment_form_before', [$this, 'render_auth_buttons']);
        add_filter('preprocess_comment', [$this, 'handle_comment_submission']);

        // Debug log to check if constructor is running
        error_log('No More Comment Spam: Constructor initialized');
    }

    private function opt($key, $default = null) {
        $options = get_option(self::OPTION_KEY, []);
        return isset($options[$key]) ? $options[$key] : $default;
    }

    public function register_settings() {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [$this, 'sanitize_options']);

        // Authentication Settings Section
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

        // Lightning Settings Section
        add_settings_section(
            'nmcs_lightning',
            __('Lightning Network Settings', 'no-more-comment-spam'),
            [$this, 'lightning_section_description'],
            'no-more-comment-spam'
        );

        add_settings_field(
            'lightning_price',
            __('Comment Price (sats)', 'no-more-comment-spam'),
            [$this, 'field_lightning_price'],
            'no-more-comment-spam',
            'nmcs_lightning'
        );

        add_settings_field(
            'lightning_address',
            __('Lightning Address', 'no-more-comment-spam'),
            [$this, 'field_lightning_address'],
            'no-more-comment-spam',
            'nmcs_lightning'
        );

        // Nostr Settings Section
        add_settings_section(
            'nmcs_nostr',
            __('Nostr Settings', 'no-more-comment-spam'),
            [$this, 'nostr_section_description'],
            'no-more-comment-spam'
        );

        add_settings_field(
            'nostr_relays',
            __('Nostr Relays', 'no-more-comment-spam'),
            [$this, 'field_nostr_relays'],
            'no-more-comment-spam',
            'nmcs_nostr'
        );

        // Spam Protection Section
        add_settings_section(
            'nmcs_spam',
            __('Spam Protection Settings', 'no-more-comment-spam'),
            [$this, 'spam_section_description'],
            'no-more-comment-spam'
        );

        add_settings_field(
            'spam_threshold',
            __('Spam Detection Threshold', 'no-more-comment-spam'),
            [$this, 'field_spam_threshold'],
            'no-more-comment-spam',
            'nmcs_spam'
        );

        add_settings_field(
            'spam_multiplier',
            __('Price Multiplier During Spam', 'no-more-comment-spam'),
            [$this, 'field_spam_multiplier'],
            'no-more-comment-spam',
            'nmcs_spam'
        );

        add_settings_field(
            'spam_duration',
            __('Spam Protection Duration (hours)', 'no-more-comment-spam'),
            [$this, 'field_spam_duration'],
            'no-more-comment-spam',
            'nmcs_spam'
        );
    }

    public function lightning_section_description() {
        echo '<p>' . __('Configure Lightning Network authentication settings. If you\'re still getting spam comments, don\'t worry! Just increase the price for a short while. The beauty of Proof of Work antispam is that you get paid to clean out spam comments, if any still exist.', 'no-more-comment-spam') . '</p>';
        echo '<p>' . __('To receive Lightning payments, you need to set up a Lightning address. This is where the payments for comments will be sent. You can get a Lightning address from services like Alby or Stacker News (yourname@stacker.news).', 'no-more-comment-spam') . '</p>';
    }

    public function nostr_section_description() {
        echo '<p>' . __('Configure Nostr authentication settings. Add your preferred Nostr relays for better connectivity.', 'no-more-comment-spam') . '</p>';
    }

    public function spam_section_description() {
        echo '<p>' . __('Configure automatic spam protection settings. The plugin will monitor comment frequency and adjust prices accordingly.', 'no-more-comment-spam') . '</p>';
    }

    public function field_lightning_price() {
        $price = $this->opt('lightning_price', 1);
        ?>
        <input type="number" 
               name="<?php echo esc_attr(self::OPTION_KEY); ?>[lightning_price]" 
               value="<?php echo esc_attr($price); ?>" 
               min="1" 
               step="1">
        <p class="description"><?php _e('Amount in satoshis required to post a comment', 'no-more-comment-spam'); ?></p>
        <?php
    }

    public function field_lightning_address() {
        $address = $this->opt('lightning_address', '');
        ?>
        <input type="text" 
               name="<?php echo esc_attr(self::OPTION_KEY); ?>[lightning_address]" 
               value="<?php echo esc_attr($address); ?>" 
               class="regular-text">
        <p class="description"><?php _e('Your Lightning address (e.g., yourname@getalby.com). This is where payments for comments will be sent. Required features: 1) Must support LNURL-pay protocol 2) Must be able to receive payments (not just send) 3) Must provide a static address that doesn\'t change 4) Must be compatible with the LNLogin plugin\'s payment verification system. This is why Alby and Stacker News (yourname@stacker.news) work, but Wallet of Satoshi doesn\'t.', 'no-more-comment-spam'); ?></p>
        <?php
    }

    public function field_nostr_relays() {
        $default_relays = [
            'wss://relay.damus.io',
            'wss://relay.primal.net',
            'wss://nostr.wine',
            'wss://nos.lol',
            'wss://relay.nostr.band'
        ];
        
        // Get saved relays or use defaults if none are saved
        $options = get_option(self::OPTION_KEY, []);
        $relays = isset($options['nostr_relays']) && !empty($options['nostr_relays']) 
            ? $options['nostr_relays'] 
            : $default_relays;
        
        if (is_string($relays)) {
            $relays = array_filter(explode("\n", $relays));
        }
        
        ?>
        <textarea name="<?php echo esc_attr(self::OPTION_KEY); ?>[nostr_relays]" 
                  rows="5" 
                  cols="50"><?php echo esc_textarea(implode("\n", $relays)); ?></textarea>
        <p class="description"><?php _e('One relay per line. These relays will be used for Nostr authentication. Default relays include Damus, Primal, nostr.wine, nos.lol, and nostr.band.', 'no-more-comment-spam'); ?></p>
        <?php
    }

    public function field_spam_threshold() {
        $threshold = $this->opt('spam_threshold', 5);
        ?>
        <input type="number" 
               name="<?php echo esc_attr(self::OPTION_KEY); ?>[spam_threshold]" 
               value="<?php echo esc_attr($threshold); ?>" 
               min="1" 
               step="1">
        <p class="description"><?php _e('Number of comments in 5 minutes that triggers spam protection', 'no-more-comment-spam'); ?></p>
        <?php
    }

    public function field_spam_multiplier() {
        $multiplier = $this->opt('spam_multiplier', 10);
        ?>
        <input type="number" 
               name="<?php echo esc_attr(self::OPTION_KEY); ?>[spam_multiplier]" 
               value="<?php echo esc_attr($multiplier); ?>" 
               min="1" 
               step="1">
        <p class="description"><?php _e('Multiply the price by this amount during spam attacks', 'no-more-comment-spam'); ?></p>
        <?php
    }

    public function field_spam_duration() {
        $duration = $this->opt('spam_duration', 1);
        ?>
        <input type="number" 
               name="<?php echo esc_attr(self::OPTION_KEY); ?>[spam_duration]" 
               value="<?php echo esc_attr($duration); ?>" 
               min="1" 
               step="1">
        <p class="description"><?php _e('Number of hours to keep increased prices after spam detection', 'no-more-comment-spam'); ?></p>
        <?php
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

    public function sanitize_options($input) {
        $sanitized = [];
        
        // Authentication methods
        if (isset($input['auth_methods']) && is_array($input['auth_methods'])) {
            $sanitized['auth_methods'] = array_map('sanitize_text_field', $input['auth_methods']);
        } else {
            $sanitized['auth_methods'] = [];
        }
        
        // Lightning settings
        if (isset($input['lightning_price'])) {
            $sanitized['lightning_price'] = absint($input['lightning_price']);
        }
        
        if (isset($input['lightning_address'])) {
            $sanitized['lightning_address'] = sanitize_text_field($input['lightning_address']);
        }
        
        // Nostr relays
        if (isset($input['nostr_relays'])) {
            $relays = array_map('trim', explode("\n", $input['nostr_relays']));
            $relays = array_filter($relays); // Remove empty lines
            $sanitized['nostr_relays'] = array_map('esc_url_raw', $relays);
        }
        
        // Spam protection
        if (isset($input['spam_threshold'])) {
            $sanitized['spam_threshold'] = absint($input['spam_threshold']);
        }
        
        if (isset($input['spam_multiplier'])) {
            $sanitized['spam_multiplier'] = absint($input['spam_multiplier']);
        }
        
        if (isset($input['spam_duration'])) {
            $sanitized['spam_duration'] = absint($input['spam_duration']);
        }
        
        return $sanitized;
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
            plugins_url('css/no-more-comment-spam.css', dirname(__FILE__)),
            [],
            self::VERSION
        );

        wp_register_script(
            'no-more-comment-spam',
            plugins_url('js/no-more-comment-spam.js', dirname(__FILE__)),
            ['jquery'],
            self::VERSION,
            true
        );

        $auth_methods = $this->opt('auth_methods', []);
        if (empty($auth_methods)) {
            $auth_methods = ['lightning', 'nostr_browser', 'nostr_connect'];
            update_option(self::OPTION_KEY, array_merge(get_option(self::OPTION_KEY, []), ['auth_methods' => $auth_methods]));
        }
        
        wp_localize_script('no-more-comment-spam', 'nmcsData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('no-more-comment-spam'),
            'nostrBrowserEnabled' => in_array('nostr_browser', $auth_methods),
            'nostrConnectEnabled' => in_array('nostr_connect', $auth_methods),
            'lightningEnabled' => in_array('lightning', $auth_methods),
            'nostrRelays' => $this->opt('nostr_relays', [
                'wss://relay.damus.io',
                'wss://relay.primal.net',
                'wss://nostr.wine',
                'wss://nos.lol',
                'wss://relay.nostr.band'
            ]),
            'i18n' => [
                'nostr_extension_required' => __('Nostr browser extension required', 'no-more-comment-spam'),
                'error_occurred' => __('An error occurred', 'no-more-comment-spam')
            ]
        ]);

        wp_enqueue_script('no-more-comment-spam');
    }

    public function render_auth_buttons() {
        // Debug log to check if function is being called
        error_log('No More Comment Spam: render_auth_buttons called');

        // Only show on single posts/pages with comments enabled
        if (!is_singular() || !comments_open()) {
            error_log('No More Comment Spam: Not showing buttons - not singular or comments closed');
            return;
        }

        // Get auth methods, default to all enabled if none set
        $auth_methods = $this->opt('auth_methods', []);
        if (empty($auth_methods)) {
            $auth_methods = ['lightning', 'nostr_browser', 'nostr_connect'];
        }
        
        error_log('No More Comment Spam: Enabled auth methods: ' . print_r($auth_methods, true));

        // Add hidden inputs
        echo '<input type="hidden" name="lightning_pubkey" value="">';
        echo '<input type="hidden" name="nostr_pubkey" value="">';
        
        // Start auth buttons container
        echo '<div class="nmcs-auth-section" style="margin-bottom: 20px;">';
        echo '<p>' . esc_html__('Please authenticate to comment:', 'no-more-comment-spam') . '</p>';
        echo '<div class="nmcs-auth-buttons">';
        
        // Lightning Login
        if (in_array('lightning', $auth_methods)) {
            if (!class_exists('LNLogin')) {
                error_log('No More Comment Spam: LNLogin class not found even after attempting to load from local directory');
                echo '<div class="nmcs-error">' . 
                    esc_html__('Lightning Login is not properly set up. Please check the plugin configuration.', 'no-more-comment-spam') . 
                    '</div>';
            } else {
                echo '<button type="button" class="nmcs-button lightning-button" onclick="nmcsLightningLogin()">' .
                    '<span class="nmcs-icon">⚡</span>' .
                    esc_html__('Login with Lightning', 'no-more-comment-spam') .
                    '</button>';
            }
        }

        // Nostr Browser Extension
        if (in_array('nostr_browser', $auth_methods)) {
            echo '<button type="button" class="nmcs-button nostr-button" onclick="nmcsNostrBrowserLogin()">' .
                '<span class="nmcs-icon">🦩</span>' .
                esc_html__('Login with Nostr Extension', 'no-more-comment-spam') .
                '</button>';
        }

        // Nostr Connect
        if (in_array('nostr_connect', $auth_methods)) {
            echo '<button type="button" class="nmcs-button nostr-connect-button" onclick="nmcsNostrConnectLogin()">' .
                '<span class="nmcs-icon">🔑</span>' .
                esc_html__('Login with Nostr Connect', 'no-more-comment-spam') .
                '</button>';
        }

        echo '</div>'; // Close nmcs-auth-buttons

        // Success message container
        echo '<div id="nmcs-auth-success" class="nmcs-success" style="display: none;">' .
            esc_html__('Authentication successful! You can now submit your comment.', 'no-more-comment-spam') .
            '</div>';
            
        echo '</div>'; // Close nmcs-auth-section
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