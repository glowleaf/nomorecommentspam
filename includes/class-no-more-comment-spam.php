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
        error_log('NMCS: Constructor called');
        
        // Load required Lightning Network files directly
        if (file_exists(dirname(dirname(__FILE__)) . '/lnlogin-main/BitcoinECDSA.php')) {
            require_once dirname(dirname(__FILE__)) . '/lnlogin-main/BitcoinECDSA.php';
        }
        if (file_exists(dirname(dirname(__FILE__)) . '/lnlogin-main/lnurl.php')) {
            require_once dirname(dirname(__FILE__)) . '/lnlogin-main/lnurl.php';
        }
        if (file_exists(dirname(dirname(__FILE__)) . '/lnlogin-main/bech32.php')) {
            require_once dirname(dirname(__FILE__)) . '/lnlogin-main/bech32.php';
        }

        // Admin: settings page
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);

        // Load scripts and styles
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        
        // Add buttons only before the comment field
        add_filter('comment_form_field_comment', [$this, 'add_buttons_before_comment_field'], 10);
        
        // Handle comment submission
        add_filter('preprocess_comment', [$this, 'handle_comment_submission']);

        // Add AJAX handlers
        add_action('wp_ajax_nmcs_verify_nostr', [$this, 'handle_nostr_verification']);
        add_action('wp_ajax_nopriv_nmcs_verify_nostr', [$this, 'handle_nostr_verification']);
        add_action('wp_ajax_nmcs_generate_lnurl_data', [$this, 'handle_nmcs_generate_lnurl_data']);
        add_action('wp_ajax_nopriv_nmcs_generate_lnurl_data', [$this, 'handle_nmcs_generate_lnurl_data']);

        error_log('NMCS: All hooks registered');
    }

    private function opt($key, $default = null) {
        $options = get_option(self::OPTION_KEY, []);
        return isset($options[$key]) ? $options[$key] : $default;
    }

    private function get_default_relays() {
        return [
            'wss://relay.damus.io',
            'wss://relay.primal.net',
            'wss://nostr.wine',
            'wss://nos.lol',
            'wss://relay.nostr.band'
        ];
    }

    public function restore_default_settings() {
        $default_options = [
            'auth_methods' => ['lightning', 'nostr_browser', 'nostr_connect'],
            'lightning_price' => 1,
            'lightning_address' => '',
            'nostr_relays' => $this->get_default_relays(),
            'spam_threshold' => 5,
            'spam_multiplier' => 10,
            'spam_duration' => 1
        ];
        
        update_option(self::OPTION_KEY, $default_options);
        error_log('NMCS: Default settings restored');
        return $default_options;
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
        echo '<p>' . sprintf(
            __('Need a Lightning address? <a href="%s" target="_blank" rel="noopener">Sign up for Stacker News</a> to get yourname@stacker.news for free!', 'no-more-comment-spam'),
            'https://loveisbitcoin.com/stackernews'
        ) . '</p>';
    }

    public function nostr_section_description() {
        echo '<p>' . __('Configure Nostr authentication settings. Add your preferred Nostr relays for better connectivity.', 'no-more-comment-spam') . '</p>';
    }

    public function spam_section_description() {
        echo '<p>' . __('Configure automatic spam protection settings. The plugin will monitor comment frequency and adjust prices accordingly.', 'no-more-comment-spam') . '</p>';
    }

    public function field_lightning_price() {
        $price = $this->opt('lightning_price', 1);
        echo '<input type="number" name="' . self::OPTION_KEY . '[lightning_price]" value="' . esc_attr($price) . '" min="1" step="1" />';
        echo '<p class="description">' . __('Price in satoshis for commenting via Lightning Network.', 'no-more-comment-spam') . '</p>';
    }

    public function field_lightning_address() {
        $address = $this->opt('lightning_address', '');
        echo '<input type="text" name="' . self::OPTION_KEY . '[lightning_address]" value="' . esc_attr($address) . '" class="regular-text" placeholder="yourname@getalby.com" />';
        echo '<p class="description">' . __('Your Lightning address where payments will be sent. Example: yourname@getalby.com or yourname@stacker.news', 'no-more-comment-spam') . '</p>';
    }

    public function field_nostr_relays() {
        $default_relays = $this->get_default_relays();
        
        // Get saved relays or use defaults if none are saved
        $options = get_option(self::OPTION_KEY, []);
        $relays = isset($options['nostr_relays']) && !empty($options['nostr_relays']) 
            ? $options['nostr_relays'] 
            : $default_relays;

        echo '<textarea name="' . self::OPTION_KEY . '[nostr_relays]" rows="5" class="large-text">';
        if (is_array($relays)) {
            echo esc_textarea(implode("\n", $relays));
        } else {
            echo esc_textarea($relays);
        }
        echo '</textarea>';
        echo '<p class="description">' . __('One relay URL per line. These are used for Nostr authentication.', 'no-more-comment-spam') . '</p>';
    }

    public function field_spam_threshold() {
        $threshold = $this->opt('spam_threshold', 5);
        echo '<input type="number" name="' . self::OPTION_KEY . '[spam_threshold]" value="' . esc_attr($threshold) . '" min="1" step="1" />';
        echo '<p class="description">' . __('Number of comments per hour that triggers spam protection.', 'no-more-comment-spam') . '</p>';
    }

    public function field_spam_multiplier() {
        $multiplier = $this->opt('spam_multiplier', 10);
        echo '<input type="number" name="' . self::OPTION_KEY . '[spam_multiplier]" value="' . esc_attr($multiplier) . '" min="1" step="1" />';
        echo '<p class="description">' . __('Multiply Lightning price by this amount during spam attacks.', 'no-more-comment-spam') . '</p>';
    }

    public function field_spam_duration() {
        $duration = $this->opt('spam_duration', 1);
        echo '<input type="number" name="' . self::OPTION_KEY . '[spam_duration]" value="' . esc_attr($duration) . '" min="1" step="1" />';
        echo '<p class="description">' . __('How long (in hours) to keep increased prices after spam is detected.', 'no-more-comment-spam') . '</p>';
    }

    public function field_auth_methods() {
        $methods = $this->opt('auth_methods', ['lightning', 'nostr_browser', 'nostr_connect']);
        $available_methods = [
            'lightning' => __('Lightning Network', 'no-more-comment-spam'),
            'nostr_browser' => __('Nostr Browser Extension', 'no-more-comment-spam'),
            'nostr_connect' => __('Nostr Connect', 'no-more-comment-spam')
        ];

        foreach ($available_methods as $key => $label) {
            $checked = in_array($key, $methods) ? 'checked' : '';
            echo '<label><input type="checkbox" name="' . self::OPTION_KEY . '[auth_methods][]" value="' . esc_attr($key) . '" ' . $checked . ' /> ' . esc_html($label) . '</label><br>';
        }
        echo '<p class="description">' . __('Select which authentication methods to enable for comments.', 'no-more-comment-spam') . '</p>';
    }

    public function sanitize_options($input) {
        $sanitized = [];
        
        try {
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
            $default_relays = $this->get_default_relays();

            if (isset($input['nostr_relays'])) {
                $relays_text = sanitize_textarea_field($input['nostr_relays']);
                $relays_array = array_filter(array_map('trim', explode("\n", $relays_text)));
                $sanitized['nostr_relays'] = !empty($relays_array) ? $relays_array : $default_relays;
            } else {
                $sanitized['nostr_relays'] = $default_relays;
            }
            
            // Spam protection settings
            if (isset($input['spam_threshold'])) {
                $sanitized['spam_threshold'] = absint($input['spam_threshold']);
            }
            
            if (isset($input['spam_multiplier'])) {
                $sanitized['spam_multiplier'] = absint($input['spam_multiplier']);
            }
            
            if (isset($input['spam_duration'])) {
                $sanitized['spam_duration'] = absint($input['spam_duration']);
            }
            
            error_log('NMCS: Settings sanitized successfully');
            
        } catch (Exception $e) {
            error_log('NMCS: Error sanitizing options: ' . $e->getMessage());
            add_settings_error(self::OPTION_KEY, 'sanitize_error', 'Error saving settings: ' . $e->getMessage());
        }
        
        return $sanitized;
    }

    public function add_settings_page() {
        add_options_page(
            __('No More Comment Spam Settings', 'no-more-comment-spam'),
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
            <form action="options.php" method="post">
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
        // Don't enqueue scripts in admin area except for specific pages
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }
        
        error_log('NMCS: Starting script enqueue');

        // Enqueue CSS
        wp_enqueue_style(
            'no-more-comment-spam-style',
            plugin_dir_url(dirname(__FILE__)) . 'admin/css/no-more-comment-spam.css',
            [],
            self::VERSION
        );

        // Enqueue JavaScript
        wp_enqueue_script(
            'no-more-comment-spam-script',
            plugin_dir_url(dirname(__FILE__)) . 'admin/js/no-more-comment-spam.js',
            ['jquery'],
            self::VERSION,
            true
        );

        // Load nostr-tools from CDN
        wp_enqueue_script(
            'nostr-tools',
            'https://unpkg.com/nostr-tools/lib/nostr.bundle.js',
            [],
            '2.7.0',
            true
        );

        // Get settings
        $auth_methods = $this->opt('auth_methods', ['lightning', 'nostr_browser', 'nostr_connect']);
        $lightning_price = $this->opt('lightning_price', 1);
        $lightning_address = $this->opt('lightning_address', '');
        
        error_log('NMCS: Auth methods: ' . print_r($auth_methods, true));
        
        $default_relays = $this->get_default_relays();

        // Localize script with settings
        wp_localize_script('no-more-comment-spam-script', 'nmcsAjax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('nmcs_nonce'),
            'authMethods' => $auth_methods,
            'lightningPrice' => $lightning_price,
            'lightningAddress' => $lightning_address,
            'nostrRelays' => $this->opt('nostr_relays', $default_relays),
            'strings' => [
                'authenticating' => __('Authenticating...', 'no-more-comment-spam'),
                'authSuccess' => __('Authentication successful!', 'no-more-comment-spam'),
                'authFailed' => __('Authentication failed. Please try again.', 'no-more-comment-spam'),
                'paymentRequired' => __('Payment required to comment.', 'no-more-comment-spam'),
                'nostrRequired' => __('Nostr authentication required to comment.', 'no-more-comment-spam'),
                'nostrNotFound' => __('Nostr extension not found. Please install a Nostr browser extension.', 'no-more-comment-spam'),
                'connectingNostr' => __('Connecting to Nostr...', 'no-more-comment-spam'),
                'generatingInvoice' => __('Generating Lightning invoice...', 'no-more-comment-spam'),
                'paymentPending' => __('Waiting for payment...', 'no-more-comment-spam'),
                'paymentSuccess' => __('Payment confirmed!', 'no-more-comment-spam'),
                'paymentFailed' => __('Payment failed or cancelled.', 'no-more-comment-spam'),
                'nostrConnectFailed' => __('Nostr Connect login failed. Please try again.', 'no-more-comment-spam')
            ]
        ]);

        error_log('NMCS: Scripts enqueued successfully');
    }

    private function get_buttons_html() {
        $auth_methods = $this->opt('auth_methods', ['lightning', 'nostr_browser', 'nostr_connect']);
        $lightning_price = $this->opt('lightning_price', 1);
        
        if (empty($auth_methods)) {
            return '';
        }

        $html = '<div id="nmcs-auth-container" style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 5px; background-color: #f9f9f9;">';
        $html .= '<p><strong>' . __('Authentication required to comment:', 'no-more-comment-spam') . '</strong></p>';
        $html .= '<div id="nmcs-auth-buttons" style="display: flex; gap: 10px; flex-wrap: wrap;">';

        if (in_array('lightning', $auth_methods)) {
            $html .= '<button type="button" id="nmcs-lightning-btn" class="nmcs-auth-btn" style="padding: 10px 15px; background-color: #f7931a; color: white; border: none; border-radius: 3px; cursor: pointer;">';
            $html .= '⚡ ' . sprintf(__('Pay %d sats', 'no-more-comment-spam'), $lightning_price);
            $html .= '</button>';
        }

        if (in_array('nostr_browser', $auth_methods)) {
            $html .= '<button type="button" id="nmcs-nostr-btn" class="nmcs-auth-btn" style="padding: 10px 15px; background-color: #8b5cf6; color: white; border: none; border-radius: 3px; cursor: pointer;">';
            $html .= '🔑 ' . __('Nostr Extension', 'no-more-comment-spam');
            $html .= '</button>';
        }

        if (in_array('nostr_connect', $auth_methods)) {
            $html .= '<button type="button" id="nmcs-nostr-connect-btn" class="nmcs-auth-btn" style="padding: 10px 15px; background-color: #6366f1; color: white; border: none; border-radius: 3px; cursor: pointer;">';
            $html .= '🔗 ' . __('Nostr Connect', 'no-more-comment-spam');
            $html .= '</button>';
        }

        $html .= '</div>';
        $html .= '<div id="nmcs-auth-status" style="margin-top: 10px; padding: 10px; border-radius: 3px; display: none;"></div>';
        $html .= '<input type="hidden" id="nmcs-auth-token" name="nmcs_auth_token" value="" />';
        $html .= '<input type="hidden" id="nmcs-auth-method" name="nmcs_auth_method" value="" />';
        $html .= '</div>';

        return $html;
    }

    public function add_buttons_before_comment_field($field) { 
        return $this->get_buttons_html() . $field;
    }

    public function handle_comment_submission($commentdata) {
        error_log('NMCS: Processing comment submission');
        
        // Skip for admin users
        if (current_user_can('moderate_comments')) {
            error_log('NMCS: Skipping auth for admin user');
            return $commentdata;
        }

        // Check if authentication is required
        $auth_methods = $this->opt('auth_methods', []);
        if (empty($auth_methods)) {
            error_log('NMCS: No auth methods enabled, allowing comment');
            return $commentdata;
        }

        // Check for authentication token
        $auth_token = isset($_POST['nmcs_auth_token']) ? sanitize_text_field($_POST['nmcs_auth_token']) : '';
        $auth_method = isset($_POST['nmcs_auth_method']) ? sanitize_text_field($_POST['nmcs_auth_method']) : '';

        error_log('NMCS: Auth token: ' . $auth_token);
        error_log('NMCS: Auth method: ' . $auth_method);

        if (empty($auth_token) || empty($auth_method)) {
            error_log('NMCS: No auth token or method provided');
            wp_die(__('Authentication required. Please authenticate before commenting.', 'no-more-comment-spam'));
        }

        // Verify the authentication token
        $stored_token = get_transient('nmcs_auth_' . $auth_token);
        if (!$stored_token) {
            error_log('NMCS: Invalid or expired auth token');
            wp_die(__('Authentication token invalid or expired. Please authenticate again.', 'no-more-comment-spam'));
        }

        // Verify the authentication method matches
        if ($stored_token['method'] !== $auth_method) {
            error_log('NMCS: Auth method mismatch');
            wp_die(__('Authentication method mismatch. Please authenticate again.', 'no-more-comment-spam'));
        }

        // Auto-fill comment form fields based on auth method
        if ($auth_method === 'nostr_browser' || $auth_method === 'nostr_connect') {
            if (empty($commentdata['comment_author'])) {
                $pubkey = isset($stored_token['pubkey']) ? substr($stored_token['pubkey'], 0, 8) : 'unknown';
                $commentdata['comment_author'] = 'Nostr User ' . $pubkey;
            }
            if (empty($commentdata['comment_author_email'])) {
                $pubkey = isset($stored_token['pubkey']) ? $stored_token['pubkey'] : 'unknown';
                $commentdata['comment_author_email'] = $pubkey . '@nostr.local';
            }
        } elseif ($auth_method === 'lightning') {
            if (empty($commentdata['comment_author'])) {
                $commentdata['comment_author'] = 'Lightning User';
            }
            if (empty($commentdata['comment_author_email'])) {
                $commentdata['comment_author_email'] = 'lightning@authenticated.local';
            }
        }

        // Clean up the token
        delete_transient('nmcs_auth_' . $auth_token);

        error_log('NMCS: Authentication successful, allowing comment');
        return $commentdata;
    }

    public function handle_nostr_verification() {
        error_log('NMCS: Nostr verification handler called');
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'nmcs_nonce')) {
            error_log('NMCS: Nonce verification failed');
            wp_send_json_error('Invalid nonce');
        }

        $event_data = $_POST['event'] ?? null;
        if (!$event_data) {
            error_log('NMCS: No event data provided');
            wp_send_json_error('No event data provided');
        }

        error_log('NMCS: Received event data: ' . print_r($event_data, true));

        // Basic event validation
        $required_fields = ['id', 'pubkey', 'created_at', 'kind', 'tags', 'content', 'sig'];
        foreach ($required_fields as $field) {
            if (!isset($event_data[$field])) {
                error_log('NMCS: Missing required field: ' . $field);
                wp_send_json_error('Invalid event: missing ' . $field);
            }
        }

        // Verify event kind (should be 22242 for auth)
        if ($event_data['kind'] != 22242) {
            error_log('NMCS: Invalid event kind: ' . $event_data['kind']);
            wp_send_json_error('Invalid event kind');
        }

        // Verify the event is recent (within 10 minutes)
        $event_time = intval($event_data['created_at']);
        $current_time = time();
        if (abs($current_time - $event_time) > 600) {
            error_log('NMCS: Event too old or in future');
            wp_send_json_error('Event timestamp invalid');
        }

        // Generate authentication token
        $auth_token = wp_generate_password(32, false);
        
        // Store authentication data
        $auth_data = [
            'method' => 'nostr_browser',
            'pubkey' => $event_data['pubkey'],
            'timestamp' => time(),
            'verified' => true
        ];
        
        set_transient('nmcs_auth_' . $auth_token, $auth_data, 300); // 5 minutes

        error_log('NMCS: Nostr verification successful');
        wp_send_json_success([
            'token' => $auth_token,
            'method' => 'nostr_browser'
        ]);
    }

    public function handle_nmcs_generate_lnurl_data() {
        error_log('NMCS: LNURL data generation handler called');
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'nmcs_nonce')) {
            error_log('NMCS: Nonce verification failed');
            wp_send_json_error('Invalid nonce');
        }

        try {
            $lightning_price = $this->opt('lightning_price', 1);
            $lightning_address = $this->opt('lightning_address', '');
            
            if (empty($lightning_address)) {
                error_log('NMCS: No Lightning address configured');
                wp_send_json_error('Lightning address not configured');
            }

            // Generate simple LNURL data
            $k1 = $this->generateK1();
            $callback_url = admin_url('admin-ajax.php?action=nmcs_lightning_callback&k1=' . $k1);
            
            // Store k1 for verification
            set_transient('nmcs_k1_' . $k1, [
                'created' => time(),
                'amount' => $lightning_price,
                'address' => $lightning_address
            ], 300);

            $lnurl_data = [
                'tag' => 'payRequest',
                'callback' => $callback_url,
                'minSendable' => $lightning_price * 1000, // Convert sats to millisats
                'maxSendable' => $lightning_price * 1000,
                'metadata' => json_encode([['text/plain', 'Comment authentication payment']])
            ];

            error_log('NMCS: LNURL data generated successfully');
            wp_send_json_success($lnurl_data);
            
        } catch (Exception $e) {
            error_log('NMCS: Error generating LNURL data: ' . $e->getMessage());
            wp_send_json_error('Error generating payment request: ' . $e->getMessage());
        }
    }

    private function generateK1() {
        return bin2hex(random_bytes(32));
    }
} 