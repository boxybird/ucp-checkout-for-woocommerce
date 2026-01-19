<?php

namespace UcpCheckout\ProductConfiguration;

/**
 * Interface for detecting products that require configuration beyond ID + quantity.
 *
 * Each implementation handles a specific WooCommerce plugin (add-ons, bundles, etc.)
 * and determines if products have required configuration that cannot be handled by UCP.
 */
interface ProductConfigurationDetectorInterface
{
    /**
     * Check if this detector's plugin is active.
     *
     * Returns true if the plugin this detector handles is installed and active.
     * Used to skip detection when the plugin isn't present.
     */
    public function isApplicable(): bool;

    /**
     * Check if product requires configuration beyond ID + quantity.
     *
     * @param \WC_Product $product The product to check
     * @return bool True if the product has required configuration fields/options
     */
    public function requiresConfiguration(\WC_Product $product): bool;

    /**
     * Get human-readable reason for the error message.
     *
     * Explains what configuration is required (e.g., "Please select add-on options on the product page.")
     *
     * @param \WC_Product $product The product being checked
     * @return string User-friendly message explaining what needs to be configured
     */
    public function getRequirementReason(\WC_Product $product): string;

    /**
     * Get the plugin name this detector handles.
     *
     * Used in error messages to identify which plugin is requiring configuration.
     * Example: "WooCommerce Product Add-Ons", "WooCommerce Composite Products"
     */
    public function getPluginName(): string;
}
