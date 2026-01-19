<?php

namespace UcpCheckout\ProductConfiguration\Detectors;

use UcpCheckout\ProductConfiguration\ProductConfigurationDetectorInterface;

/**
 * Detector for WooCommerce Composite Products.
 *
 * Composite products allow merchants to create kits or bundles where customers
 * must select component options. These products always require user configuration
 * and cannot be purchased via UCP.
 *
 * @see https://woocommerce.com/products/composite-products/
 */
class CompositeProductsDetector implements ProductConfigurationDetectorInterface
{
    /**
     * Plugin file paths to check for activation.
     */
    private const array PLUGIN_FILES = [
        'woocommerce-composite-products/woocommerce-composite-products.php',
    ];

    public function isApplicable(): bool
    {
        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (array_any(self::PLUGIN_FILES, fn($pluginFile) => is_plugin_active($pluginFile))) {
            return true;
        }

        return class_exists('WC_Product_Composite');
    }

    public function requiresConfiguration(\WC_Product $product): bool
    {
        // Composite products are identified by their product type
        // All composite products require configuration (component selection)
        return $product->get_type() === 'composite';
    }

    public function getRequirementReason(\WC_Product $product): string
    {
        return 'This composite product requires selecting component options on the product page.';
    }

    public function getPluginName(): string
    {
        return 'WooCommerce Composite Products';
    }
}
