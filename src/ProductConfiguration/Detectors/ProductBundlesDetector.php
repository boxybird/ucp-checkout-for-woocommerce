<?php

namespace UcpCheckout\ProductConfiguration\Detectors;

use UcpCheckout\ProductConfiguration\ProductConfigurationDetectorInterface;

/**
 * Detector for WooCommerce Product Bundles.
 *
 * Product bundles allow merchants to create grouped products where customers
 * can configure quantities or optional items. Bundles with configurable items
 * require user configuration and cannot be purchased via UCP.
 *
 * @see https://woocommerce.com/products/product-bundles/
 */
class ProductBundlesDetector implements ProductConfigurationDetectorInterface
{
    /**
     * Plugin file paths to check for activation.
     */
    private const array PLUGIN_FILES = [
        'woocommerce-product-bundles/woocommerce-product-bundles.php',
    ];

    public function isApplicable(): bool
    {
        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (array_any(self::PLUGIN_FILES, fn($pluginFile) => is_plugin_active($pluginFile))) {
            return true;
        }

        return class_exists('WC_Product_Bundle');
    }

    public function requiresConfiguration(\WC_Product $product): bool
    {
        // Bundle products are a specific product type
        if ($product->get_type() !== 'bundle') {
            return false;
        }

        // Check if the bundle has configurable items
        return $this->hasConfigurableItems($product);
    }

    public function getRequirementReason(\WC_Product $product): string
    {
        return 'This product bundle requires selecting item options on the product page.';
    }

    public function getPluginName(): string
    {
        return 'WooCommerce Product Bundles';
    }

    /**
     * Check if the bundle has items that require configuration.
     *
     * Bundles require configuration if they have:
     * - Optional items (customer can include/exclude)
     * - Variable quantity items (customer can change quantities)
     * - Items with variations (customer must select variation)
     *
     * @param \WC_Product $product
     * @return bool
     */
    private function hasConfigurableItems(\WC_Product $product): bool
    {
        // Read bundle items from post meta (defensive approach - no instanceof checks)
        $bundledItemsData = get_post_meta($product->get_id(), '_bundle_data', true);

        if (!is_array($bundledItemsData) || empty($bundledItemsData)) {
            return false;
        }

        foreach ($bundledItemsData as $itemData) {
            // Check for optional items
            if (!empty($itemData['optional']) && $itemData['optional'] === 'yes') {
                return true;
            }

            // Check for variable quantity
            $minQty = $itemData['quantity_min'] ?? 1;
            $maxQty = $itemData['quantity_max'] ?? 1;
            if ($minQty !== $maxQty) {
                return true;
            }

            // Check if bundled product is variable
            if (!empty($itemData['product_id'])) {
                $bundledProduct = wc_get_product($itemData['product_id']);
                if ($bundledProduct && $bundledProduct->is_type('variable')) {
                    return true;
                }
            }
        }

        return false;
    }
}
