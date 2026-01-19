<?php

namespace UcpCheckout\ProductConfiguration\Detectors;

use UcpCheckout\ProductConfiguration\ProductConfigurationDetectorInterface;

/**
 * Detector for YITH WooCommerce Product Add-Ons (free and premium versions).
 *
 * YITH's popular add-ons plugin allows merchants to add custom options to products.
 * Products with required YITH add-ons cannot be purchased via UCP without user configuration.
 *
 * @see https://yithemes.com/themes/plugins/yith-woocommerce-product-add-ons/
 */
class YithProductAddOnsDetector implements ProductConfigurationDetectorInterface
{
    /**
     * Plugin file paths to check for activation.
     * Covers both free and premium versions.
     */
    private const array PLUGIN_FILES = [
        'yith-woocommerce-product-add-ons/init.php',
        'yith-woocommerce-advanced-product-options-premium/init.php',
        'yith-woocommerce-advanced-product-options/init.php',
    ];

    public function isApplicable(): bool
    {
        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (array_any(self::PLUGIN_FILES, fn($pluginFile) => is_plugin_active($pluginFile))) {
            return true;
        }

        return class_exists('YITH_WAPO') || class_exists('YITH_WAPO_Premium');
    }

    public function requiresConfiguration(\WC_Product $product): bool
    {
        // Check product-specific add-ons
        $productAddons = $this->getProductAddons($product);
        if ($this->hasRequiredAddons($productAddons)) {
            return true;
        }

        // Check global add-ons that apply to this product
        $globalAddons = $this->getGlobalAddons($product);
        if ($this->hasRequiredAddons($globalAddons)) {
            return true;
        }

        return false;
    }

    public function getRequirementReason(\WC_Product $product): string
    {
        return 'Please select the required product options on the product page.';
    }

    public function getPluginName(): string
    {
        return 'YITH WooCommerce Product Add-Ons';
    }

    /**
     * Get product-specific add-ons.
     *
     * @param \WC_Product $product
     * @return array
     */
    private function getProductAddons(\WC_Product $product): array
    {
        // YITH stores add-ons in _ywapo_meta_data post meta
        $addonsData = get_post_meta($product->get_id(), '_ywapo_meta_data', true);

        if (is_array($addonsData)) {
            return $addonsData;
        }

        // Premium version may use different meta key
        $premiumData = get_post_meta($product->get_id(), '_yith_wapo_product_blocks', true);
        if (is_array($premiumData)) {
            return $premiumData;
        }

        return [];
    }

    /**
     * Get global add-ons that apply to this product.
     *
     * YITH uses a custom post type for global add-on blocks.
     *
     * @param \WC_Product $product
     * @return array
     */
    private function getGlobalAddons(\WC_Product $product): array
    {
        // YITH WAPO uses 'yith_wapo_type' post type for blocks
        $blocksQuery = get_posts([
            'post_type' => 'yith_wapo_type',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        if (empty($blocksQuery)) {
            return [];
        }

        $productCategories = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'ids']);
        $applicableAddons = [];

        foreach ($blocksQuery as $block) {
            // Check if block applies to this product
            $showIn = get_post_meta($block->ID, '_ywapo_show_in', true);
            $categories = get_post_meta($block->ID, '_ywapo_categories', true);
            $products = get_post_meta($block->ID, '_ywapo_products', true);

            $applies = false;

            if ($showIn === 'all') {
                $applies = true;
            } elseif ($showIn === 'categories' && is_array($categories)) {
                $applies = !empty(array_intersect($productCategories, $categories));
            } elseif ($showIn === 'products' && is_array($products)) {
                $applies = in_array($product->get_id(), $products);
            }

            if ($applies) {
                $options = get_post_meta($block->ID, '_ywapo_options', true);
                if (is_array($options)) {
                    $applicableAddons = array_merge($applicableAddons, $options);
                }
            }
        }

        return $applicableAddons;
    }

    /**
     * Check if any add-ons in the array are required.
     *
     * @param array $addons
     * @return bool
     */
    private function hasRequiredAddons(array $addons): bool
    {
        foreach ($addons as $addon) {
            // YITH uses 'required' key in addon configuration
            if (isset($addon['required']) && $addon['required']) {
                return true;
            }

            // Premium version may use different structure
            if (isset($addon['options']) && is_array($addon['options'])) {
                foreach ($addon['options'] as $option) {
                    if (isset($option['required']) && $option['required']) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
