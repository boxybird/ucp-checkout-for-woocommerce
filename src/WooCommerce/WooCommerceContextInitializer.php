<?php

namespace UcpCheckout\WooCommerce;

/**
 * Initializes WooCommerce context for REST API requests.
 *
 * WooCommerce was designed for traditional page-load checkout, not headless REST API.
 * Many WC functions and payment gateways assume session, customer, and cart globals exist.
 * This class provides safe initialization of these objects for REST API context.
 *
 * Security considerations:
 * - Sessions are in-memory only (not persisted to database)
 * - Customer is initialized as guest (user ID 0) unless authenticated
 * - Cart is empty (only needed to prevent null pointer errors in some gateways)
 * - All objects are request-scoped and don't leak between requests
 */
class WooCommerceContextInitializer
{
    private static bool $initialized = false;

    /**
     * Initialize WooCommerce context for REST API usage.
     *
     * Safe to call multiple times - will only initialize once per request.
     *
     * @param array|null $shippingDestination Optional shipping address to set on customer
     */
    public static function initialize(?array $shippingDestination = null): void
    {
        if (!function_exists('WC')) {
            return;
        }

        self::initializeSession();
        self::initializeCustomer($shippingDestination);
        self::initializeCart();

        self::$initialized = true;
    }

    /**
     * Check if context has been initialized this request.
     */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    /**
     * Initialize WooCommerce session handler.
     *
     * The session is in-memory only and not persisted to database.
     * This is appropriate for stateless REST API requests.
     */
    private static function initializeSession(): void
    {
        if (!is_null(WC()->session)) {
            return;
        }

        WC()->session = new \WC_Session_Handler();
        WC()->session->init();
    }

    /**
     * Initialize WooCommerce customer object.
     *
     * For REST API requests, this will typically be a guest customer (user ID 0).
     * If the request is authenticated, it will use that user's ID.
     *
     * @param array|null $shippingDestination Optional address to set as shipping location
     */
    private static function initializeCustomer(?array $shippingDestination = null): void
    {
        if (is_null(WC()->customer)) {
            WC()->customer = new \WC_Customer(get_current_user_id(), true);
        }

        if ($shippingDestination) {
            self::setCustomerShippingLocation($shippingDestination);
        }
    }

    /**
     * Set customer shipping location from address array.
     *
     * @param array $address Address with UCP or WC format keys
     */
    private static function setCustomerShippingLocation(array $address): void
    {
        if (is_null(WC()->customer)) {
            return;
        }

        // Sanitize and extract address components
        $country = self::sanitizeAddressField($address['address_country'] ?? $address['country'] ?? '');
        $state = self::sanitizeAddressField($address['address_region'] ?? $address['state'] ?? '');
        $postcode = self::sanitizeAddressField($address['postal_code'] ?? $address['zip'] ?? '');
        $city = self::sanitizeAddressField($address['address_locality'] ?? $address['city'] ?? '');

        WC()->customer->set_shipping_location($country, $state, $postcode, $city);
    }

    /**
     * Initialize WooCommerce cart.
     *
     * Some payment gateways call WC()->cart->empty_cart() after processing.
     * The cart is initialized empty since we're not using the cart system.
     */
    private static function initializeCart(): void
    {
        if (!is_null(WC()->cart)) {
            return;
        }

        WC()->cart = new \WC_Cart();
    }

    /**
     * Sanitize address field to prevent injection.
     *
     * @param mixed $value Raw field value
     * @return string Sanitized string
     */
    private static function sanitizeAddressField(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        // Use WooCommerce's sanitization if available, otherwise WordPress
        if (function_exists('wc_clean')) {
            return wc_clean($value);
        }

        return sanitize_text_field($value);
    }

    /**
     * Reset initialization state (for testing).
     */
    public static function reset(): void
    {
        self::$initialized = false;
    }
}
