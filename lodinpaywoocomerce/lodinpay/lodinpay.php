<?php
/**
 * Plugin Name: Lodin RTP Payment Gateway
 * Plugin URI: https://lodinpay.com
 * Description: Generate instant RTP payment links via Effyis API
 * Version: 1.0.0
 * Author: Lodin
 * Author URI: https://lodinpay.com
 * Text Domain: lodin
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

/**
 * Initialize the gateway
 */
add_action('plugins_loaded', 'lodin_init_gateway_class', 11);

function lodin_init_gateway_class() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_Lodin extends WC_Payment_Gateway {
        const RTP_API_URL = 'https://api-preprod.lodinpay.com/merchant-service/extensions/pay/rtp';

        public $client_id;
        public $client_secret;

        public function __construct() {
            $this->id = 'lodin';
            $this->icon = plugins_url('assets/logo.png', __FILE__);
            $this->has_fields = false;
            $this->method_title = __('Lodin RTP', 'lodin');
            $this->method_description = __('Generate instant RTP payment links', 'lodin');
            
            // Declare what features this gateway supports
            $this->supports = array('products');

            // Load settings
            $this->init_form_fields();
            $this->init_settings();

            // Get settings
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->client_id = $this->get_option('client_id');
            $this->client_secret = $this->get_option('client_secret');
            $this->enabled = $this->get_option('enabled');

            // Hooks
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
        }

        public function is_available() {
            $is_available = parent::is_available();
            
            // Must be enabled
            if ('yes' !== $this->enabled) {
                return false;
            }
            
            // Must have credentials configured
            if (empty($this->client_id) || empty($this->client_secret)) {
                return false;
            }
            
            return $is_available;
        }

        /**
         * Initialize gateway settings form fields
         */
        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'lodin'),
                    'type' => 'checkbox',
                    'label' => __('Enable Lodin RTP Payment', 'lodin'),
                    'default' => 'no'
                ),
                'title' => array(
                    'title' => __('Title', 'lodin'),
                    'type' => 'text',
                    'description' => __('Payment method title displayed to customers', 'lodin'),
                    'default' => __('Pay with Lodin RTP', 'lodin'),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Description', 'lodin'),
                    'type' => 'textarea',
                    'description' => __('Payment method description displayed to customers', 'lodin'),
                    'default' => __('Pay securely via instant bank transfer', 'lodin'),
                    'desc_tip' => true,
                ),
                'client_id' => array(
                    'title' => __('Client ID', 'lodin'),
                    'type' => 'text',
                    'description' => __('Enter your Lodin Client ID', 'lodin'),
                    'default' => '',
                    'desc_tip' => true,
                ),
                'client_secret' => array(
                    'title' => __('Client Secret', 'lodin'),
                    'type' => 'password',
                    'description' => __('Enter your Lodin Client Secret', 'lodin'),
                    'default' => '',
                    'desc_tip' => true,
                ),
            );
        }

        /**
         * Process the payment
         */
        public function process_payment($order_id) {
            $order = wc_get_order($order_id);

            if (!$order) {
                wc_add_notice(__('Order not found', 'lodin'), 'error');
                return array('result' => 'failure');
            }

            try {
                // Generate payment link
                $payment_link = $this->generate_payment_link($order);

                if (!$payment_link) {
                    throw new Exception(__('Failed to generate payment link', 'lodin'));
                }

                // Mark as pending payment
                $order->update_status('pending', __('Awaiting Lodin RTP payment', 'lodin'));

                // Reduce stock levels
                wc_reduce_stock_levels($order_id);

                // Empty cart
                WC()->cart->empty_cart();

                // Redirect to payment link
                return array(
                    'result' => 'success',
                    'redirect' => $payment_link
                );

            } catch (Exception $e) {
                wc_add_notice(__('Payment error: ', 'lodin') . $e->getMessage(), 'error');
                return array('result' => 'failure');
            }
        }

        /**
         * Generate payment link via API
         */
        private function generate_payment_link($order) {
            if (empty($this->client_id) || empty($this->client_secret)) {
                throw new Exception(__('Lodin payment gateway is not properly configured', 'lodin'));
            }

            $invoice_id = 'ORDER-' . $order->get_id() . '-' . time();
            $amount = number_format($order->get_total(), 2, '.', '');

            // Generate signature
            $timestamp = gmdate('Y-m-d\TH:i:s\Z');
            $payload = $this->client_id . $timestamp . $amount . $invoice_id;
            $signature = $this->generate_signature($payload, $this->client_secret);

            // Generate webhook and return URLs
            $webhook_url = WC()->api_request_url('lodin_webhook');
            $return_url = $order->get_checkout_order_received_url();

            // Prepare API request
            $headers = array(
                'Content-Type' => 'application/json',
                'X-Extension-Code' => 'WOOCOMMERCE',
                'X-Client-Id' => $this->client_id,
                'X-Timestamp' => $timestamp,
                'X-Signature' => $signature,
            );

            $body = array(
                'amount' => (float) $amount,
                'invoiceId' => $invoice_id,
                'paymentType' => 'INST',
                'description' => 'WooCommerce Order #' . $order->get_order_number(),
                'callbackUrl' => $webhook_url,
                'returnUrl' => $return_url,
            );

            // Make API request
            $response = wp_remote_post(self::RTP_API_URL, array(
                'headers' => $headers,
                'body' => json_encode($body),
                'timeout' => 20,
            ));

            // Handle response
            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $http_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if ($http_code === 200) {
                $data = json_decode($response_body, true);
                $payment_link = isset($data['url']) ? $data['url'] : null;

                if ($payment_link) {
                    // Store payment link in order meta
                    $order->update_meta_data('_lodin_payment_link', $payment_link);
                    $order->update_meta_data('_lodin_invoice_id', $invoice_id);
                    $order->save();

                    return $payment_link;
                } else {
                    throw new Exception(__('No payment URL in API response', 'lodin'));
                }
            }

            throw new Exception(__('API error: ', 'lodin') . $response_body);
        }

        /**
         * Generate HMAC signature
         */
        private function generate_signature($payload, $secret) {
            $raw_hmac = hash_hmac('sha256', $payload, $secret, true);
            $base64 = base64_encode($raw_hmac);
            $url_safe = strtr($base64, array('+' => '-', '/' => '_'));
            $signature = rtrim($url_safe, '=');

            return $signature;
        }

        /**
         * Custom thank you page message
         */
        public function thankyou_page($order_id) {
            $order = wc_get_order($order_id);
            
            if ($order && $order->get_payment_method() === $this->id) {
                if ($order->has_status('pending')) {
                    echo '<div class="woocommerce-info">';
                    echo '<p>' . __('Your payment is being processed. You will receive a confirmation email once the payment is complete.', 'lodin') . '</p>';
                    echo '</div>';
                } elseif ($order->has_status('processing') || $order->has_status('completed')) {
                    echo '<div class="woocommerce-message">';
                    echo '<p>' . __('Thank you! Your payment has been received successfully.', 'lodin') . '</p>';
                    echo '</div>';
                }
            }
        }
    }
}

/**
 * Block-based checkout support for Lodin
 */
add_action('woocommerce_blocks_loaded', 'lodin_register_block_support');

function lodin_register_block_support() {
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    class WC_Gateway_Lodin_Blocks_Support extends Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
        protected $name = 'lodin';
        private $gateway;

        public function initialize() {
            $this->settings = get_option('woocommerce_lodin_settings', array());
            $this->gateway = new WC_Gateway_Lodin();
            
            // Register scripts
            $script_path = __DIR__ . '/build/index.asset.php';
            $script_dependencies = file_exists($script_path) 
                ? require $script_path 
                : array('dependencies' => array(), 'version' => '1.0.0');
            
            wp_register_script(
                'lodin-blocks-integration',
                plugins_url('build/index.js', __FILE__),
                $script_dependencies['dependencies'],
                $script_dependencies['version'],
                true
            );
            
            wp_set_script_translations('lodin-blocks-integration', 'lodin');
        }

        public function get_name() {
            return 'lodin';
        }

        public function is_active() {
            return $this->gateway->is_available();
        }

        public function get_script_data() {
            return $this->get_payment_method_data();
        }

        public function get_payment_method_script_handles() {
            return array('lodin-blocks-integration');
        }

        public function get_payment_method_data() {
            return array(
                'id' => $this->gateway->id,
                'title' => $this->gateway->title,
                'description' => $this->gateway->description,
            );
        }
    }

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function($payment_method_registry) {
            $payment_method_registry->register(new WC_Gateway_Lodin_Blocks_Support());
        }
    );
}

/**
 * Handle Lodin payment webhook/callback
 */
add_action('woocommerce_api_lodin_webhook', 'lodin_handle_webhook');

function lodin_handle_webhook() {
    // Get the raw POST data
    $raw_post = file_get_contents('php://input');
    $webhook_data = json_decode($raw_post, true);
    
    // Log the webhook for debugging
    wc_get_logger()->info('=== LODIN WEBHOOK RECEIVED ===', array('source' => 'lodin-webhook'));
    wc_get_logger()->info('Webhook data: ' . print_r($webhook_data, true), array('source' => 'lodin-webhook'));
    wc_get_logger()->info('Headers: ' . print_r(getallheaders(), true), array('source' => 'lodin-webhook'));
    
    // Validate webhook signature (IMPORTANT for security)
    $signature = isset($_SERVER['HTTP_X_SIGNATURE']) ? $_SERVER['HTTP_X_SIGNATURE'] : '';
    if (!lodin_verify_webhook_signature($raw_post, $signature)) {
        wc_get_logger()->error('Invalid webhook signature', array('source' => 'lodin-webhook'));
        status_header(401);
        exit('Invalid signature');
    }
    
    // Extract payment information
    $invoice_id = $webhook_data['invoiceId'] ?? '';
    $status = $webhook_data['status'] ?? '';
    $transaction_id = $webhook_data['transactionId'] ?? '';
    
    if (empty($invoice_id)) {
        wc_get_logger()->error('No invoice ID in webhook', array('source' => 'lodin-webhook'));
        status_header(400);
        exit('No invoice ID');
    }
    
    // Extract order ID from invoice ID (format: ORDER-123-1234567890)
    if (preg_match('/ORDER-(\d+)-/', $invoice_id, $matches)) {
        $order_id = $matches[1];
    } else {
        wc_get_logger()->error('Invalid invoice ID format: ' . $invoice_id, array('source' => 'lodin-webhook'));
        status_header(400);
        exit('Invalid invoice ID format');
    }
    
    // Get the order
    $order = wc_get_order($order_id);
    
    if (!$order) {
        wc_get_logger()->error('Order not found: ' . $order_id, array('source' => 'lodin-webhook'));
        status_header(404);
        exit('Order not found');
    }
    
    // Verify this order belongs to Lodin gateway
    if ($order->get_payment_method() !== 'lodin') {
        wc_get_logger()->error('Order payment method mismatch', array('source' => 'lodin-webhook'));
        status_header(400);
        exit('Invalid payment method');
    }
    
    // Verify invoice ID matches
    $stored_invoice_id = $order->get_meta('_lodin_invoice_id');
    if ($stored_invoice_id !== $invoice_id) {
        wc_get_logger()->error('Invoice ID mismatch. Expected: ' . $stored_invoice_id . ', Got: ' . $invoice_id, array('source' => 'lodin-webhook'));
        status_header(400);
        exit('Invoice ID mismatch');
    }
    
    // Process based on payment status
    switch (strtoupper($status)) {
        case 'COMPLETED':
        case 'SUCCESS':
        case 'PAID':
            // Payment successful
            if (!$order->is_paid()) {
                $order->payment_complete($transaction_id);
                $order->add_order_note(
                    sprintf(__('Lodin payment completed. Transaction ID: %s', 'lodin'), $transaction_id)
                );
                
                wc_get_logger()->info('Order #' . $order_id . ' marked as paid', array('source' => 'lodin-webhook'));
            } else {
                wc_get_logger()->info('Order #' . $order_id . ' already paid, skipping', array('source' => 'lodin-webhook'));
            }
            break;
            
        case 'FAILED':
        case 'CANCELLED':
        case 'REJECTED':
            // Payment failed
            $order->update_status('failed', __('Lodin payment failed', 'lodin'));
            $order->add_order_note(
                sprintf(__('Lodin payment failed with status: %s', 'lodin'), $status)
            );
            
            wc_get_logger()->info('Order #' . $order_id . ' marked as failed', array('source' => 'lodin-webhook'));
            break;
            
        case 'PENDING':
            // Payment still pending
            $order->add_order_note(__('Lodin payment still pending', 'lodin'));
            wc_get_logger()->info('Order #' . $order_id . ' still pending', array('source' => 'lodin-webhook'));
            break;
            
        default:
            wc_get_logger()->warning('Unknown payment status: ' . $status, array('source' => 'lodin-webhook'));
    }
    
    // Respond with 200 OK
    status_header(200);
    exit('OK');
}

/**
 * Verify webhook signature for security
 */
function lodin_verify_webhook_signature($payload, $signature) {
    // Get gateway settings
    $gateways = WC()->payment_gateways->payment_gateways();
    $gateway = isset($gateways['lodin']) ? $gateways['lodin'] : null;
    
    if (!$gateway) {
        wc_get_logger()->error('Gateway not found for signature verification', array('source' => 'lodin-webhook'));
        return false;
    }
    
    $client_secret = $gateway->client_secret;
    
    if (empty($client_secret) || empty($signature)) {
        wc_get_logger()->error('Missing client secret or signature', array('source' => 'lodin-webhook'));
        return false;
    }
    
    // Generate expected signature (same method as payment request)
    $raw_hmac = hash_hmac('sha256', $payload, $client_secret, true);
    $base64 = base64_encode($raw_hmac);
    $url_safe = strtr($base64, array('+' => '-', '/' => '_'));
    $expected_signature = rtrim($url_safe, '=');
    
    wc_get_logger()->debug('Expected signature: ' . $expected_signature, array('source' => 'lodin-webhook'));
    wc_get_logger()->debug('Received signature: ' . $signature, array('source' => 'lodin-webhook'));
    
    // Compare signatures
    return hash_equals($expected_signature, $signature);
}

/**
 * Add the gateway to WooCommerce
 */
add_filter('woocommerce_payment_gateways', 'lodin_add_gateway_class');

function lodin_add_gateway_class($gateways) {
    $gateways[] = 'WC_Gateway_Lodin';
    return $gateways;
}
