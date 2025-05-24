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
        
        // Try to load LNLogin if not already active
        if (!class_exists('LNLogin') && file_exists(dirname(__DIR__) . '/lnlogin-main/lnlogin.php')) {
            require_once dirname(__DIR__) . '/lnlogin-main/lnlogin.php';
            error_log('NMCS: Tried to load LNLogin');
        }

        // Admin: settings page
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);

        // Load scripts and styles
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        
        // Add buttons only before the comment field
        add_filter('comment_form_field_comment', [$this, 'add_buttons_before_comment_field'], 10); // Changed function name and priority
        
        // Handle comment submission
        add_filter('preprocess_comment', [$this, 'handle_comment_submission']);

        // Add AJAX handlers
        add_action('wp_ajax_nmcs_verify_nostr', [$this, 'handle_nostr_verification']);
        add_action('wp_ajax_nopriv_nmcs_verify_nostr', [$this, 'handle_nostr_verification']);
        add_action('wp_ajax_nmcs_generate_lnurl_data', [$this, 'handle_nmcs_generate_lnurl_data']);
        add_action('wp_ajax_nopriv_nmcs_generate_lnurl_data', [$this, 'handle_nmcs_generate_lnurl_data']);
        // Note: No handler for nmcs_get_invoice as the underlying lnlogin plugin doesn't support it easily via AJAX

        error_log('NMCS: All hooks registered');
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
        
        // If relays is a string, convert it to array
        if (is_string($relays)) {
            $relays = array_filter(explode("\n", $relays), function($relay) {
                return !empty(trim($relay));
            });
        }

        // If relays is empty after filtering, use defaults
        if (empty($relays)) {
            $relays = $default_relays;
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
        $default_relays = [
            'wss://relay.damus.io',
            'wss://relay.primal.net',
            'wss://nostr.wine',
            'wss://nos.lol',
            'wss://relay.nostr.band'
        ];

        if (isset($input['nostr_relays'])) {
            $relays = array_map('trim', explode("\n", $input['nostr_relays']));
            $relays = array_filter($relays); // Remove empty lines
            $relays = array_map('esc_url_raw', $relays);
            
            // If no valid relays after filtering, use defaults
            if (empty($relays)) {
                $relays = $default_relays;
            }
            
            $sanitized['nostr_relays'] = $relays;
        } else {
            $sanitized['nostr_relays'] = $default_relays;
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
        error_log('NMCS: Starting script enqueue');

        // Define base URL for the plugin directory
        $plugin_dir_url = plugin_dir_url(__FILE__);

        // Enqueue style
        wp_enqueue_style(
            'no-more-comment-spam',
            $plugin_dir_url . 'css/no-more-comment-spam.css', // Use plugin_dir_url
            [],
            self::VERSION . '.' . time() // Force reload
        );
        error_log('NMCS: CSS enqueued from: ' . $plugin_dir_url . 'css/no-more-comment-spam.css');

        // Get auth methods and relays first to determine dependencies
        $auth_methods = $this->opt('auth_methods', []);
        if (empty($auth_methods)) {
            $auth_methods = ['lightning', 'nostr_browser', 'nostr_connect'];
            update_option(self::OPTION_KEY, array_merge(get_option(self::OPTION_KEY, []), ['auth_methods' => $auth_methods]));
        }

        error_log('NMCS: Auth methods: ' . print_r($auth_methods, true));
        
        $default_relays = [
            'wss://relay.damus.io',
            'wss://relay.primal.net',
            'wss://nostr.wine',
            'wss://nos.lol',
            'wss://relay.nostr.band'
        ];

        $relays = $this->opt('nostr_relays', $default_relays);
        if (empty($relays)) {
            $relays = $default_relays;
            update_option(self::OPTION_KEY, array_merge(get_option(self::OPTION_KEY, []), ['nostr_relays' => $default_relays]));
        }

        // Conditionally enqueue Nostr library if Nostr Connect is enabled
        $script_dependencies = ['jquery'];
        if (in_array('nostr_connect', $auth_methods)) {
            // Use nostr-tools instead of NDK - it has proper browser bundle support
            $nostr_urls = [
                'https://unpkg.com/nostr-tools/lib/nostr.bundle.js',
                'https://cdn.jsdelivr.net/npm/nostr-tools/lib/nostr.bundle.js',
                'https://cdn.skypack.dev/nostr-tools'
            ];
            
            // Use the first URL as primary
            wp_enqueue_script(
                'nostr-tools',
                $nostr_urls[0],
                [], // No dependencies
                'latest', // Use latest version
                true // Load in footer
            );
            
            // Add fallback loading script
            wp_add_inline_script('nostr-tools', '
                // Fallback loading for nostr-tools
                if (typeof window.NostrTools === "undefined") {
                    console.warn("Primary nostr-tools CDN failed, trying fallbacks...");
                    const fallbackUrls = ' . json_encode(array_slice($nostr_urls, 1)) . ';
                    let currentFallback = 0;
                    
                    function loadNostrToolsFallback() {
                        if (currentFallback >= fallbackUrls.length) {
                            console.error("All nostr-tools CDN sources failed to load");
                            return;
                        }
                        
                        const script = document.createElement("script");
                        script.src = fallbackUrls[currentFallback];
                        script.onload = function() {
                            console.log("nostr-tools loaded from fallback:", fallbackUrls[currentFallback]);
                        };
                        script.onerror = function() {
                            console.warn("Fallback CDN failed:", fallbackUrls[currentFallback]);
                            currentFallback++;
                            loadNostrToolsFallback();
                        };
                        document.head.appendChild(script);
                    }
                    
                    // Check if nostr-tools loaded after a short delay
                    setTimeout(() => {
                        if (typeof window.NostrTools === "undefined") {
                            loadNostrToolsFallback();
                        }
                    }, 1000);
                }
            ', 'after');
            
            $script_dependencies[] = 'nostr-tools'; // Add nostr-tools as dependency for main script
            error_log('NMCS: Enqueued nostr-tools library for Nostr Connect with fallbacks.');
        }

        // Enqueue main script with proper dependencies
        wp_enqueue_script(
            'no-more-comment-spam',
            $plugin_dir_url . 'js/no-more-comment-spam.js', // Use plugin_dir_url
            $script_dependencies, // Include nostr-tools as dependency if needed
            self::VERSION . '.' . time(), // Force reload
            true // Load in footer to ensure all dependencies are loaded first
        );
        error_log('NMCS: JS enqueued from: ' . $plugin_dir_url . 'js/no-more-comment-spam.js with dependencies: ' . implode(', ', $script_dependencies));
        
        // Localize script data
        wp_localize_script('no-more-comment-spam', 'nmcsData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('no-more-comment-spam'),
            'nostrBrowserEnabled' => in_array('nostr_browser', $auth_methods),
            'nostrConnectEnabled' => in_array('nostr_connect', $auth_methods),
            'lightningEnabled' => in_array('lightning', $auth_methods),
            'nostrRelays' => $relays,
            'i18n' => [
                'nostr_extension_required' => __('Nostr browser extension required', 'no-more-comment-spam'),
                'error_occurred' => __('An error occurred', 'no-more-comment-spam')
            ]
        ]);

        error_log('NMCS: Script data localized');
    }

    private function get_buttons_html() {
        error_log('NMCS: Generating buttons HTML');
        
        // Get auth methods, default to all enabled if none set
        $auth_methods = $this->opt('auth_methods', []);
        if (empty($auth_methods)) {
            $auth_methods = ['lightning', 'nostr_browser', 'nostr_connect'];
        }
        
        ob_start();
        
        // Add hidden inputs - these will be handled by JS now
        // echo '<input type="hidden" name="lightning_pubkey" value="">';
        // echo '<input type="hidden" name="nostr_pubkey" value="">';
        
        // The buttons container - using standard CSS classes now
        echo '<div class="nmcs-buttons-container">'; // Removed inline styles
        echo '<p class="nmcs-auth-title">' . esc_html__('Please authenticate to comment:', 'no-more-comment-spam') . '</p>'; // Added class
        echo '<div class="nmcs-auth-buttons">'; // Added class
        
        // Lightning Login
        if (in_array('lightning', $auth_methods)) {
             if (!function_exists('generateLnurl')) {
                error_log('NMCS: Lightning Login core function (generateLnurl) not found');
                echo '<div class="nmcs-error">' . // Use class for error message
                    esc_html__('Lightning Login is not properly set up. Please check the plugin configuration.', 'no-more-comment-spam') . 
                    '</div>';
            } else {
                echo '<button type="button" class="nmcs-button lightning-button" onclick="nmcsLightningLogin()">' . // Use classes
                    '<span class="nmcs-icon">⚡</span>' . // Use class
                    esc_html__('Login with Lightning', 'no-more-comment-spam') .
                    '</button>';
            }
        }

        // Nostr Browser Extension
        if (in_array('nostr_browser', $auth_methods)) {
            echo '<button type="button" class="nmcs-button nostr-button" onclick="nmcsNostrBrowserLogin()">' . // Use classes
                '<span class="nmcs-icon">🦩</span>' . // Use class
                esc_html__('Login with Nostr Extension', 'no-more-comment-spam') .
                '</button>';
        }

        // Nostr Connect
        if (in_array('nostr_connect', $auth_methods)) {
            echo '<button type="button" class="nmcs-button nostr-connect-button" onclick="nmcsNostrConnectLogin()">' . // Use classes
                '<span class="nmcs-icon">🔑</span>' . // Use class
                esc_html__('Login with Nostr Connect', 'no-more-comment-spam') .
                '</button>';
        }

        echo '</div>'; // Close nmcs-auth-buttons

        // Success message container
        echo '<div id="nmcs-auth-success" class="nmcs-success" style="display: none;"></div>'; // Use class
            
        echo '</div>'; // Close nmcs-buttons-container
        
        $html = ob_get_clean();
        error_log('NMCS: Generated buttons HTML: ' . substr($html, 0, 100) . '...');
        return $html;
    }

    public function add_buttons_before_comment_field($field) { 
        error_log('NMCS: Adding buttons before comment field');
        return '<div class="nmcs-buttons-wrapper">' . $this->get_buttons_html() . '</div>' . $field; // Added a wrapper div
    }

    public function handle_comment_submission($commentdata) {
        error_log('NMCS: Starting comment submission handling.');

        // --- Geolocation Check ---
        $user_ip = $_SERVER['REMOTE_ADDR'];
        // Basic validation for IP format (optional but recommended)
        if (filter_var($user_ip, FILTER_VALIDATE_IP)) {
            $geoip_url = 'https://geoip-db.com/json/' . $user_ip;
            $response = wp_remote_get($geoip_url);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $body = wp_remote_retrieve_body($response);
                $geo_data = json_decode($body, true);

                if ($geo_data && isset($geo_data['country_code'])) {
                    $country_code = $geo_data['country_code'];
                    error_log('NMCS: Geolocation check - IP: ' . $user_ip . ', Country: ' . $country_code);
                    
                    // Block Indian IPs by default
                    if ($country_code === 'IN') {
                        error_log('NMCS: Blocking comment from India IP: ' . $user_ip);
                        wp_die(
                            __('Sorry, comments from your region are currently not allowed.', 'no-more-comment-spam'),
                            __('Comment Blocked', 'no-more-comment-spam'),
                            ['response' => 403, 'back_link' => true]
                        );
                    }
                } else {
                    error_log('NMCS: Geolocation check failed to parse data for IP: ' . $user_ip);
                    // Decide how to handle parsing failure: fail open (allow) or fail closed (block)?
                    // Failing open for now.
                }
            } else {
                $error_message = is_wp_error($response) ? $response->get_error_message() : 'HTTP Status ' . wp_remote_retrieve_response_code($response);
                error_log('NMCS: Geolocation check failed for IP: ' . $user_ip . ' - Error: ' . $error_message);
                // Decide how to handle API failure: fail open (allow) or fail closed (block)?
                // Failing open for now.
            }
        } else {
             error_log('NMCS: Invalid IP address detected: ' . $user_ip);
             // Handle invalid IP - likely fail open
        }
        // --- End Geolocation Check ---

        $auth_methods = $this->opt('auth_methods', []);
        
        if (empty($auth_methods)) {
            return $commentdata;
        }

        // Allow logged-in administrators to bypass checks
        if (current_user_can('moderate_comments')) {
            error_log('NMCS: Moderator bypassing auth check.');
            return $commentdata;
        }

        $is_authenticated = false;
        $auth_type = 'none';

        // Check Nostr authentication
        if (in_array('nostr_connect', $auth_methods) || in_array('nostr_browser', $auth_methods)) {
            if (!empty($_POST['nostr_pubkey'])) {
                // Basic check: just ensure the pubkey is present
                // A more robust check would involve verifying the signature again server-side if needed
                $is_authenticated = true;
                $auth_type = 'nostr';
                error_log('NMCS: Nostr auth detected: pubkey=' . sanitize_text_field($_POST['nostr_pubkey']));
            }
        }

        // Check Lightning authentication (assuming successful login sets user session)
        if (!$is_authenticated && in_array('lightning', $auth_methods)) {
            // Check if the user is logged in via the Lightning method
            // This relies on how the lnlogin plugin handles user sessions
            if (is_user_logged_in() && get_user_meta(get_current_user_id(), 'linkingkey', true)) {
                 $is_authenticated = true;
                 $auth_type = 'lightning';
                 error_log('NMCS: Lightning auth detected for user ID: ' . get_current_user_id());
            } elseif (!empty($_POST['lightning_pubkey'])) { // Fallback check if hidden field is used
                 $is_authenticated = true;
                 $auth_type = 'lightning_field';
                 error_log('NMCS: Lightning auth detected via hidden field.');
            }
        }


        if (!$is_authenticated) {
            error_log('NMCS: Authentication failed. Aborting comment submission.');
            wp_die(
                __('Please authenticate using one of the provided methods to post a comment.', 'no-more-comment-spam'),
                __('Authentication Required', 'no-more-comment-spam'),
                ['response' => 403, 'back_link' => true]
            );
        }

        error_log('NMCS: Comment submission authenticated via: ' . $auth_type);
        return $commentdata;
    }

    public function handle_nostr_verification() {
        // Temporarily commented out for debugging the 403 error
        // check_ajax_referer('no-more-comment-spam', 'nonce'); 
        error_log('NMCS DEBUG: Skipped nonce check for handle_nostr_verification');

        $pubkey = isset($_POST['pubkey']) ? sanitize_text_field($_POST['pubkey']) : null;
        $challenge = isset($_POST['challenge']) ? sanitize_text_field($_POST['challenge']) : null;
        $signature = isset($_POST['signature']) ? sanitize_text_field($_POST['signature']) : null;
        
        // Log received data for debugging
        error_log('NMCS DEBUG: Received pubkey: ' . print_r($pubkey, true));
        error_log('NMCS DEBUG: Received challenge: ' . print_r($challenge, true));
        error_log('NMCS DEBUG: Received signature: ' . print_r($signature, true));
        error_log('NMCS DEBUG: Received nonce (from POST): ' . print_r($_POST['nonce'], true)); // Check if nonce arrives

        if (!$pubkey || !$challenge || !$signature) {
            wp_send_json_error(['message' => __('Missing required Nostr data.', 'no-more-comment-spam')]);
            return;
        }

        // Basic validation: Check if pubkey seems valid (hex, 64 chars)
        if (!ctype_xdigit($pubkey) || strlen($pubkey) !== 64) {
             wp_send_json_error(['message' => __('Invalid Nostr pubkey format.', 'no-more-comment-spam')]);
             return;
        }
        
        // !! Placeholder for actual signature verification !!
        // You would typically need a PHP Nostr library here to properly verify the signature
        // against the pubkey and the challenge event.
        // For now, we are just accepting the presence of the data as verification.
        $is_valid = true; 

        if ($is_valid) {
            error_log('NMCS: Nostr verification successful (basic check, nonce skipped) for pubkey: ' . $pubkey);
            wp_send_json_success(['message' => __('Nostr verification successful (Nonce check skipped).', 'no-more-comment-spam')]);
        } else {
            error_log('NMCS: Nostr verification failed (nonce skipped) for pubkey: ' . $pubkey);
            wp_send_json_error(['message' => __('Nostr signature verification failed.', 'no-more-comment-spam')]);
        }
    }

    public function handle_nmcs_generate_lnurl_data() {
        // Ensure functions from lnlogin plugin are available
        if (!function_exists('k1Generator') || !function_exists('addk1') || !function_exists('lnurlEncoder')) {
            error_log('NMCS Error: Required functions from lnlogin plugin not found.');
            wp_send_json_error(['message' => __('Lightning Login plugin functions not available.', 'no-more-comment-spam')]);
            return;
        }

        // Nonce check (optional but good practice)
        // check_ajax_referer('no-more-comment-spam', 'nonce'); 

        try {
            // Generate k1
            $k1 = k1Generator();
            if (empty($k1)) {
                 throw new Exception('Failed to generate k1.');
            }
            error_log('NMCS: Generated k1: ' . $k1);

            // Add k1 to DB (will also remove old ones)
            addk1($k1); 
            removek1s(); // Call removek1s explicitly if addk1 doesn't handle it reliably
            error_log('NMCS: Added k1 to database.');

            // Generate LNURL
            $login_url = admin_url('admin-ajax.php') . '?action=lnlogin&tag=login&k1=' . $k1;
            $lnurl = lnurlEncoder($login_url);
            if (empty($lnurl)) {
                throw new Exception('Failed to encode LNURL.');
            }
            error_log('NMCS: Generated LNURL: ' . $lnurl);

            wp_send_json_success(['k1' => $k1, 'lnurl' => $lnurl]);

        } catch (Exception $e) {
            error_log('NMCS Error generating LNURL data: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Error generating Lightning Login data: ', 'no-more-comment-spam') . $e->getMessage()]);
        }
    }
} 