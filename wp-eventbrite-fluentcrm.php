<?php
/**
 * Plugin Name: Eventbrite to FluentCRM Integration
 * Plugin URI: https://github.com/andriy-f/wp-eventbrite-fluentcrm
 * Description: Receive webhooks from Eventbrite and automatically add/update contacts in FluentCRM
 * Version: 1.0.0
 * Author: Andriy Fetsyuk
 * Author URI: https://github.com/andriy-f
 * License: MIT
 * Text Domain: wp-eventbrite-fluentcrm
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WP_EVENTBRITE_FLUENTCRM_VERSION', '1.0.0');
define('WP_EVENTBRITE_FLUENTCRM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_EVENTBRITE_FLUENTCRM_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main plugin class
 */
class WP_Eventbrite_FluentCRM {
    
    /**
     * Plugin instance
     */
    private static $instance = null;
    
    /**
     * Get plugin instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Register REST API endpoint for webhook
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));
        
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Register REST API endpoint for receiving Eventbrite webhooks
     */
    public function register_webhook_endpoint() {
        register_rest_route('eventbrite-fluentcrm/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => array($this, 'verify_webhook_signature'),
        ));
    }
    
    /**
     * Verify webhook signature from Eventbrite
     */
    public function verify_webhook_signature($request) {
        // Get the webhook secret from settings
        $webhook_secret = get_option('eventbrite_fluentcrm_webhook_secret', '');
        
        // If no secret is set, allow the request (for initial setup)
        // In production, you should always use a secret
        if (empty($webhook_secret)) {
            $this->log_debug('Warning: No webhook secret configured');
            return true;
        }
        
        // Verify the signature if provided
        $signature = $request->get_header('x-eventbrite-signature');
        if (!empty($signature)) {
            $payload = $request->get_body();
            $expected_signature = hash_hmac('sha256', $payload, $webhook_secret);
            
            if (hash_equals($expected_signature, $signature)) {
                return true;
            }
            
            $this->log_debug('Webhook signature verification failed');
            return new WP_Error('invalid_signature', 'Invalid webhook signature', array('status' => 403));
        }
        
        return true;
    }
    
    /**
     * Handle incoming webhook from Eventbrite
     */
    public function handle_webhook($request) {
        $body = $request->get_json_params();
        
        $this->log_debug('Received webhook: ' . print_r($body, true));
        
        // Check if we have the required data
        if (empty($body['api_url'])) {
            return new WP_Error('invalid_data', 'Missing api_url in webhook payload', array('status' => 400));
        }
        
        // Fetch the full event data from Eventbrite API
        $event_data = $this->fetch_eventbrite_data($body['api_url']);
        
        if (is_wp_error($event_data)) {
            $this->log_debug('Error fetching Eventbrite data: ' . $event_data->get_error_message());
            return $event_data;
        }
        
        // Process the webhook based on the event type
        $config = $body['config'] ?? array();
        $action = $config['action'] ?? 'order.placed';
        
        switch ($action) {
            case 'order.placed':
            case 'attendee.updated':
                $result = $this->process_attendee_data($event_data);
                break;
            
            default:
                $this->log_debug('Unhandled webhook action: ' . $action);
                return rest_ensure_response(array(
                    'success' => true,
                    'message' => 'Webhook received but not processed'
                ));
        }
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Contact synced to FluentCRM',
            'data' => $result
        ));
    }
    
    /**
     * Fetch data from Eventbrite API
     */
    private function fetch_eventbrite_data($api_url) {
        $api_token = get_option('eventbrite_fluentcrm_api_token', '');
        
        if (empty($api_token)) {
            return new WP_Error('no_api_token', 'Eventbrite API token not configured', array('status' => 500));
        }
        
        $response = wp_remote_get($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_token,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 15,
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (empty($data)) {
            return new WP_Error('invalid_response', 'Invalid response from Eventbrite API', array('status' => 500));
        }
        
        return $data;
    }
    
    /**
     * Process attendee data and sync to FluentCRM
     */
    private function process_attendee_data($data) {
        // Extract contact information from Eventbrite data
        $profile = $data['profile'] ?? array();
        $email = $profile['email'] ?? '';
        $first_name = $profile['first_name'] ?? '';
        $last_name = $profile['last_name'] ?? '';
        
        if (empty($email)) {
            return new WP_Error('no_email', 'No email address found in attendee data', array('status' => 400));
        }
        
        // Prepare contact data for FluentCRM
        $contact_data = array(
            'email' => $email,
            'first_name' => $first_name,
            'last_name' => $last_name,
        );
        
        // Add custom fields if available
        if (!empty($profile['cell_phone'])) {
            $contact_data['phone'] = $profile['cell_phone'];
        }
        
        // Add tags from settings
        $default_tags = get_option('eventbrite_fluentcrm_default_tags', array());
        if (!empty($default_tags)) {
            $contact_data['tags'] = $default_tags;
        }
        
        // Add lists from settings
        $default_lists = get_option('eventbrite_fluentcrm_default_lists', array());
        if (!empty($default_lists)) {
            $contact_data['lists'] = $default_lists;
        }
        
        // Sync to FluentCRM
        return $this->sync_to_fluentcrm($contact_data);
    }
    
    /**
     * Sync contact to FluentCRM
     */
    private function sync_to_fluentcrm($contact_data) {
        // Check if FluentCRM is active
        if (!function_exists('FluentCrmApi')) {
            return new WP_Error('fluentcrm_not_active', 'FluentCRM plugin is not active', array('status' => 500));
        }
        
        try {
            $contactApi = FluentCrmApi('contacts');
            
            // Create or update contact
            $contact = $contactApi->createOrUpdate($contact_data);
            
            $this->log_debug('Contact synced to FluentCRM: ' . $contact->email);
            
            return array(
                'contact_id' => $contact->id,
                'email' => $contact->email,
                'status' => $contact->status
            );
            
        } catch (Exception $e) {
            $this->log_debug('FluentCRM sync error: ' . $e->getMessage());
            return new WP_Error('fluentcrm_error', $e->getMessage(), array('status' => 500));
        }
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            'Eventbrite to FluentCRM Settings',
            'Eventbrite FluentCRM',
            'manage_options',
            'eventbrite-fluentcrm',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('eventbrite_fluentcrm_settings', 'eventbrite_fluentcrm_api_token');
        register_setting('eventbrite_fluentcrm_settings', 'eventbrite_fluentcrm_webhook_secret');
        register_setting('eventbrite_fluentcrm_settings', 'eventbrite_fluentcrm_default_tags');
        register_setting('eventbrite_fluentcrm_settings', 'eventbrite_fluentcrm_default_lists');
        register_setting('eventbrite_fluentcrm_settings', 'eventbrite_fluentcrm_debug_mode');
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get the webhook URL
        $webhook_url = rest_url('eventbrite-fluentcrm/v1/webhook');
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="notice notice-info">
                <p><strong>Webhook URL:</strong> <code><?php echo esc_html($webhook_url); ?></code></p>
                <p>Use this URL when configuring webhooks in your Eventbrite account.</p>
            </div>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('eventbrite_fluentcrm_settings');
                ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="eventbrite_fluentcrm_api_token">Eventbrite API Token</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="eventbrite_fluentcrm_api_token" 
                                   name="eventbrite_fluentcrm_api_token" 
                                   value="<?php echo esc_attr(get_option('eventbrite_fluentcrm_api_token')); ?>" 
                                   class="regular-text">
                            <p class="description">Your Eventbrite Private Token for API access</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="eventbrite_fluentcrm_webhook_secret">Webhook Secret</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="eventbrite_fluentcrm_webhook_secret" 
                                   name="eventbrite_fluentcrm_webhook_secret" 
                                   value="<?php echo esc_attr(get_option('eventbrite_fluentcrm_webhook_secret')); ?>" 
                                   class="regular-text">
                            <p class="description">Secret key for verifying Eventbrite webhook signatures (optional but recommended)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="eventbrite_fluentcrm_default_tags">Default Tags</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="eventbrite_fluentcrm_default_tags" 
                                   name="eventbrite_fluentcrm_default_tags" 
                                   value="<?php echo esc_attr(implode(',', (array) get_option('eventbrite_fluentcrm_default_tags', array()))); ?>" 
                                   class="regular-text">
                            <p class="description">Comma-separated list of tags to apply to all contacts from Eventbrite (e.g., "eventbrite,attendee")</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="eventbrite_fluentcrm_default_lists">Default Lists</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="eventbrite_fluentcrm_default_lists" 
                                   name="eventbrite_fluentcrm_default_lists" 
                                   value="<?php echo esc_attr(implode(',', (array) get_option('eventbrite_fluentcrm_default_lists', array()))); ?>" 
                                   class="regular-text">
                            <p class="description">Comma-separated list of list IDs to add contacts to (e.g., "1,2,3")</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="eventbrite_fluentcrm_debug_mode">Debug Mode</label>
                        </th>
                        <td>
                            <input type="checkbox" 
                                   id="eventbrite_fluentcrm_debug_mode" 
                                   name="eventbrite_fluentcrm_debug_mode" 
                                   value="1" 
                                   <?php checked(get_option('eventbrite_fluentcrm_debug_mode'), '1'); ?>>
                            <label for="eventbrite_fluentcrm_debug_mode">Enable debug logging</label>
                            <p class="description">When enabled, webhook activities will be logged to the WordPress debug log</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2>Setup Instructions</h2>
            <ol>
                <li>Install and activate FluentCRM plugin</li>
                <li>Get your Eventbrite Private Token from <a href="https://www.eventbrite.com/account-settings/apps" target="_blank">Eventbrite Account Settings</a></li>
                <li>Enter your API token and webhook secret above</li>
                <li>Copy the Webhook URL shown above</li>
                <li>Go to your Eventbrite event → Manage → Webhooks</li>
                <li>Create a new webhook with the URL and select the events you want to track (e.g., "Order Placed", "Attendee Updated")</li>
                <li>Test the webhook by creating a test order in Eventbrite</li>
            </ol>
            
            <h2>FluentCRM Status</h2>
            <p>
                <?php
                if (function_exists('FluentCrmApi')) {
                    echo '<span style="color: green;">✓ FluentCRM is active and ready</span>';
                } else {
                    echo '<span style="color: red;">✗ FluentCRM plugin is not active. Please install and activate it.</span>';
                }
                ?>
            </p>
        </div>
        <?php
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
        add_option('eventbrite_fluentcrm_api_token', '');
        add_option('eventbrite_fluentcrm_webhook_secret', '');
        add_option('eventbrite_fluentcrm_default_tags', array());
        add_option('eventbrite_fluentcrm_default_lists', array());
        add_option('eventbrite_fluentcrm_debug_mode', '0');
        
        // Flush rewrite rules to register REST API endpoint
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Log debug message
     */
    private function log_debug($message) {
        if (get_option('eventbrite_fluentcrm_debug_mode') === '1' && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[Eventbrite FluentCRM] ' . $message);
        }
    }
}

// Sanitization callback for tags and lists
add_filter('sanitize_option_eventbrite_fluentcrm_default_tags', function($value) {
    if (is_string($value)) {
        $tags = array_map('trim', explode(',', $value));
        return array_filter($tags);
    }
    return $value;
});

add_filter('sanitize_option_eventbrite_fluentcrm_default_lists', function($value) {
    if (is_string($value)) {
        $lists = array_map('trim', explode(',', $value));
        return array_filter($lists);
    }
    return $value;
});

// Initialize the plugin
function wp_eventbrite_fluentcrm_init() {
    return WP_Eventbrite_FluentCRM::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'wp_eventbrite_fluentcrm_init');
