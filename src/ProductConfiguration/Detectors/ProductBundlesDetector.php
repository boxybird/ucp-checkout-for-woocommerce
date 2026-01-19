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

        foreach (self::PLUGIN_FILES as $pluginFile) {
            if (is_plugin_active($pluginFile)) {
                return true;
            }
        }

        // Also check for the bundle product class as a fallback
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
        // If the product is a WC_Product_Bundle instance, use its methods
        // @phpstan-ignore class.notFound (WC_Product_Bundle is from optional premium plugin)
        if ($product instanceof \WC_Product_Bundle && method_exists($product, 'get_bundled_items')) {
            /** @phpstan-ignore class.notFound */
            $bundledItems = $product->get_bundled_items();

            if (empty($bundledItems)) {
                return false;
            }

            foreach ($bundledItems as $bundledItem) {
                // Check for optional items
                if (method_exists($bundledItem, 'is_optional') && $bundledItem->is_optional()) {
                    return true;
                }

                // Check for variable quantity
                if (method_exists($bundledItem, 'get_quantity_min') && method_exists($bundledItem, 'get_quantity_max')) {
                    $minQty = $bundledItem->get_quantity_min();
                    $maxQty = $bundledItem->get_quantity_max();
                    if ($minQty !== $maxQty) {
                        return true;
                    }
                }

                // Check for variable products in bundle
                if (method_exists($bundledItem, 'get_product') && $bundledItem->get_product()) {
                    $bundledProduct = $bundledItem->get_product();
                    if ($bundledProduct->is_type('variable')) {
                        return true;
                    }
                }
            }

            return false;
        }

        // Fallback: Read bundle items from meta
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
