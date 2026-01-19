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

        foreach (self::PLUGIN_FILES as $pluginFile) {
            if (is_plugin_active($pluginFile)) {
                return true;
            }
        }

        // Also check for the composite product class as a fallback
        return class_exists('WC_Product_Composite');
    }

    public function requiresConfiguration(\WC_Product $product): bool
    {
        // Composite products are a specific product type
        if ($product->get_type() === 'composite') {
            return true;
        }

        // Also check if it's an instance of the composite product class
        // @phpstan-ignore class.notFound (WC_Product_Composite is from optional premium plugin)
        if ($product instanceof \WC_Product_Composite) {
            return true;
        }

        return false;
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
