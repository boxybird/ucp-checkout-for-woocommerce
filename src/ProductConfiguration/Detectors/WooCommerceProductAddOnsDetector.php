<?php

namespace UcpCheckout\ProductConfiguration\Detectors;

use UcpCheckout\ProductConfiguration\ProductConfigurationDetectorInterface;

/**
 * Detector for WooCommerce Product Add-Ons (official WooCommerce extension).
 *
 * This plugin allows merchants to add extra options like text fields, checkboxes,
 * dropdowns, etc. to products. Products with required add-ons cannot be purchased
 * via UCP without user configuration.
 *
 * @see https://woocommerce.com/products/product-add-ons/
 */
class WooCommerceProductAddOnsDetector implements ProductConfigurationDetectorInterface
{
    /**
     * Plugin file paths to check for activation.
     */
    private const array PLUGIN_FILES = [
        'woocommerce-product-addons/woocommerce-product-addons.php',
        'product-addons/product-addons.php',
    ];

    public function isApplicable(): bool
    {
        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return array_any(self::PLUGIN_FILES, fn($pluginFile) => is_plugin_active($pluginFile));
    }

    public function requiresConfiguration(\WC_Product $product): bool
    {
        $addons = $this->getProductAddons($product);

        if (empty($addons)) {
            return false;
        }
        return array_any($addons, fn($addon) => !empty($addon['required']));
    }

    public function getRequirementReason(\WC_Product $product): string
    {
        return 'Please select the required add-on options on the product page.';
    }

    public function getPluginName(): string
    {
        return 'WooCommerce Product Add-Ons';
    }

    /**
     * Get add-ons for a product.
     *
     * Uses the official WC_Product_Addons_Helper if available,
     * falls back to reading product meta directly.
     *
     * @param \WC_Product $product
     * @return array
     */
    private function getProductAddons(\WC_Product $product): array
    {
        // Try using the official helper class
        if (class_exists('WC_Product_Addons_Helper')) {
            $addons = \WC_Product_Addons_Helper::get_product_addons($product->get_id());
            if (is_array($addons)) {
                return $addons;
            }
        }

        // Fallback: Read from product meta directly
        $productAddons = get_post_meta($product->get_id(), '_product_addons', true);
        if (is_array($productAddons)) {
            return $productAddons;
        }

        // Check for global add-ons that might apply to this product
        $globalAddons = $this->getGlobalAddons($product);
        if (!empty($globalAddons)) {
            return $globalAddons;
        }

        return [];
    }

    /**
     * Get global add-ons that apply to this product.
     *
     * Global add-ons can be applied by category or to all products.
     *
     * @param \WC_Product $product
     * @return array
     */
    private function getGlobalAddons(\WC_Product $product): array
    {
        // Global add-ons are stored as a custom post type
        $globalAddonsPosts = get_posts([
            'post_type' => 'global_product_addon',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        if (empty($globalAddonsPosts)) {
            return [];
        }

        $productCategories = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'ids']);
        $applicableAddons = [];

        foreach ($globalAddonsPosts as $post) {
            $addonData = get_post_meta($post->ID, '_product_addons', true);
            if (!is_array($addonData)) {
                continue;
            }

            // Check if this global add-on applies to this product
            $applyToAll = get_post_meta($post->ID, '_all_products', true);
            $addonCategories = get_post_meta($post->ID, '_product_categories', true);

            if ($applyToAll === '1') {
                $applicableAddons = array_merge($applicableAddons, $addonData);
            } elseif (is_array($addonCategories) && !empty(array_intersect($productCategories, $addonCategories))) {
                $applicableAddons = array_merge($applicableAddons, $addonData);
            }
        }

        return $applicableAddons;
    }
}
