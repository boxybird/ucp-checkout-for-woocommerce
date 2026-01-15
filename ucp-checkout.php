<?php

/**
 * Plugin Name: UCP Checkout for WooCommerce
 * Description: Enable AI agents like ChatGPT, Gemini, and Claude to discover and purchase products from your WooCommerce store using the Universal Commerce Protocol (UCP).
 * Version: 1.0
 * Author: Andrew Rhyand
 * Requires PHP: 8.0
 * Requires at least: 6.0
 * WC requires at least: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('UCP_PLUGIN_VERSION', '1.0.0');
define('UCP_PLUGIN_FILE', __FILE__);
define('UCP_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Load Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

// Initialize the plugin
function ucp_plugin_init(): void
{
    $plugin = new UcpCheckout\Plugin();
    $plugin->init();
}

add_action('plugins_loaded', 'ucp_plugin_init');
