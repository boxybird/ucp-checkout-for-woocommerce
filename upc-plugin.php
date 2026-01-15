<?php

/**
 * Plugin Name: WooCommerce UCP Bridge
 * Description: Standalone implementation of the Universal Commerce Protocol (UCP). Makes WooCommerce products accessible and purchasable by AI agents (Gemini, ChatGPT, Claude).
 * Version: 1.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) exit;

class Woo_UCP_Bridge
{

    public function __construct()
    {
        // 1. Discovery: The Manifest Handshake
        add_action('init', [$this, 'handle_manifest_request']);

        // 2. Action: API Endpoints for AI Agents
        add_action('rest_api_init', [$this, 'register_ucp_api_routes']);
    }

    /**
     * THE MANIFEST
     * AI agents look here first to see what your store is capable of.
     */
    public function handle_manifest_request()
    {
        // Intercepts yourdomain.com/.well-known/ucp
        if (strpos($_SERVER['REQUEST_URI'], '.well-known/ucp') !== false) {
            header('Content-Type: application/json');
            echo json_encode([
                "protocol_version" => "2026-01-01",
                "capabilities" => [
                    "product_search" => true,
                    "real_time_availability" => true,
                    "shipping_estimation" => true,
                    "native_checkout" => true
                ],
                "endpoints" => [
                    "search" => rest_url('ucp/v1/search'),
                    "availability" => rest_url('ucp/v1/availability'),
                    "estimate" => rest_url('ucp/v1/estimate'),
                    "checkout" => rest_url('ucp/v1/checkout')
                ]
            ]);
            exit;
        }
    }

    /**
     * REGISTER API ROUTES
     */
    public function register_ucp_api_routes()
    {
        $namespace = 'ucp/v1';

        // Endpoint: Search Catalog
        register_rest_route($namespace, '/search', [
            'methods' => 'GET',
            'callback' => [$this, 'search_products'],
            'permission_callback' => '__return_true'
        ]);

        // Endpoint: Real-time Stock/Price
        register_rest_route($namespace, '/availability', [
            'methods' => 'GET',
            'callback' => [$this, 'get_availability'],
            'permission_callback' => '__return_true'
        ]);

        // Endpoint: Shipping & Tax Estimation
        register_rest_route($namespace, '/estimate', [
            'methods' => 'POST',
            'callback' => [$this, 'get_estimate'],
            'permission_callback' => '__return_true'
        ]);

        // Endpoint: Final Purchase
        register_rest_route($namespace, '/checkout', [
            'methods' => 'POST',
            'callback' => [$this, 'process_checkout'],
            'permission_callback' => [$this, 'verify_agent_request']
        ]);
    }

    /**
     * LOGIC: SEARCH
     */
    public function search_products($request)
    {
        $query = $request->get_param('q');
        $products = wc_get_products(['s' => $query, 'limit' => 5, 'status' => 'publish']);

        $results = [];
        foreach ($products as $product) {
            $results[] = [
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'price' => $product->get_price(),
                'currency' => get_woocommerce_currency(),
                'image' => wp_get_attachment_image_url($product->get_image_id(), 'full'),
                'url' => $product->get_permalink()
            ];
        }
        return rest_ensure_response($results);
    }

    /**
     * LOGIC: AVAILABILITY
     */
    public function get_availability($request)
    {
        $sku = $request->get_param('sku');
        $id = wc_get_product_id_by_sku($sku);
        if (!$id) return new WP_Error('no_product', 'Product not found', ['status' => 404]);

        $product = wc_get_product($id);
        return rest_ensure_response([
            'sku' => $sku,
            'in_stock' => $product->is_in_stock(),
            'price' => $product->get_price(),
            'stock_quantity' => $product->get_stock_quantity()
        ]);
    }

    /**
     * LOGIC: ESTIMATE (Tax & Shipping)
     */
    public function get_estimate($request)
    {
        $params = $request->get_json_params();
        $id = wc_get_product_id_by_sku($params['sku']);
        $postcode = $params['zip'] ?? '';
        $country = $params['country'] ?? 'US';

        if (!$id) return new WP_Error('no_product', 'Product not found', ['status' => 404]);

        // Set simulated location
        WC()->customer->set_billing_location($country, '', $postcode);
        WC()->customer->set_shipping_location($country, '', $postcode);

        $product = wc_get_product($id);

        // Calculate Shipping
        $package = [['contents' => [$id => ['data' => $product, 'quantity' => 1]], 'destination' => ['country' => $country, 'postcode' => $postcode]]];
        $shipping_methods = WC()->shipping->calculate_shipping($package);

        $rates = [];
        foreach ($shipping_methods[0]['rates'] as $rate) {
            $rates[] = ['id' => $rate->id, 'label' => $rate->label, 'cost' => $rate->cost];
        }

        return rest_ensure_response([
            'subtotal' => $product->get_price(),
            'tax' => wc_get_price_including_tax($product) - $product->get_price(),
            'shipping_options' => $rates
        ]);
    }

    /**
     * LOGIC: CHECKOUT
     */
    public function process_checkout($request)
    {
        $data = $request->get_json_params();

        try {
            $order = wc_create_order();
            $product_id = wc_get_product_id_by_sku($data['sku']);
            $order->add_product(wc_get_product($product_id), 1);

            $order->set_address([
                'first_name' => $data['shipping']['first_name'],
                'last_name' => $data['shipping']['last_name'],
                'address_1' => $data['shipping']['address'],
                'city' => $data['shipping']['city'],
                'postcode' => $data['shipping']['zip'],
                'country' => $data['shipping']['country'],
            ], 'shipping');

            // Handle payment token (Integration with Stripe/Google Pay happens here)
            $order->set_payment_method('ucp_agent');
            $order->set_transaction_id($data['payment_token']);
            $order->payment_complete();

            return rest_ensure_response(['status' => 'success', 'order_id' => $order->get_id()]);
        } catch (Exception $e) {
            return new WP_Error('checkout_error', $e->getMessage(), ['status' => 500]);
        }
    }

    public function verify_agent_request($request)
    {
        // In production, verify Google/OpenAI headers
        return true;
    }
}

new Woo_UCP_Bridge();