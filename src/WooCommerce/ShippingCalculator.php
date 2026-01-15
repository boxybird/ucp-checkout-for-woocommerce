<?php

namespace UcpCheckout\WooCommerce;

use WC_Shipping_Rate;

/**
 * Calculates shipping rates using WooCommerce shipping zones API.
 */
class ShippingCalculator
{
    /**
     * Calculate shipping with selected method.
     *
     * @param array $destination Shipping address
     * @param array $lineItems UCP line items
     * @param string|null $selectedMethodId Optional selected shipping method ID
     * @return array{options: array, selected: string|null, amount: int}
     */
    public function calculate(array $destination, array $lineItems, ?string $selectedMethodId = null): array
    {
        $methods = $this->getAvailableMethods($destination, $lineItems);

        if (empty($methods)) {
            return [
                'options' => [],
                'selected' => null,
                'amount' => 0,
            ];
        }

        // Find selected method or default to first
        $selected = null;
        $selectedAmount = 0;

        if ($selectedMethodId) {
            foreach ($methods as $method) {
                if ($method['id'] === $selectedMethodId) {
                    $selected = $selectedMethodId;
                    $selectedAmount = $method['amount'];
                    break;
                }
            }
        }

        // Default to first method if none selected
        if (!$selected && count($methods) > 0) {
            $selected = $methods[0]['id'];
            $selectedAmount = $methods[0]['amount'];
        }

        return [
            'options' => $methods,
            'selected' => $selected,
            'amount' => $selectedAmount,
        ];
    }

    /**
     * Get available shipping methods for a destination.
     *
     * @param array $destination Shipping address
     * @param array $lineItems UCP line items
     * @return array<array{id: string, name: string, amount: int}>
     */
    public function getAvailableMethods(array $destination, array $lineItems): array
    {
        if (!function_exists('WC')) {
            return [];
        }

        $shipping = WC()->shipping();
        if (!$shipping) {
            return [];
        }

        // Ensure shipping is enabled
        if (!wc_shipping_enabled()) {
            return [];
        }

        // Build shipping package from line items
        $package = $this->buildShippingPackage($destination, $lineItems);

        // Calculate shipping rates
        $shipping->calculate_shipping([$package]);
        $packages = $shipping->get_packages();

        if (empty($packages) || empty($packages[0]['rates'])) {
            return [];
        }

        $methods = [];
        /** @var WC_Shipping_Rate $rate */
        foreach ($packages[0]['rates'] as $rate) {
            $methods[] = [
                'id' => $rate->get_id(),
                'name' => $rate->get_label(),
                'amount' => $this->convertToMinorUnits($rate->get_cost()),
            ];
        }

        return $methods;
    }

    /**
     * Get shipping cost for a specific method.
     *
     * @param string $methodId Shipping method ID
     * @param array $destination Shipping address
     * @param array $lineItems UCP line items
     * @return int Shipping cost in minor units (cents)
     */
    public function getMethodCost(string $methodId, array $destination, array $lineItems): int
    {
        $methods = $this->getAvailableMethods($destination, $lineItems);

        foreach ($methods as $method) {
            if ($method['id'] === $methodId) {
                return $method['amount'];
            }
        }

        return 0;
    }

    /**
     * Build a WooCommerce shipping package from UCP line items and destination.
     *
     * @param array $destination UCP address
     * @param array $lineItems UCP line items
     * @return array WC shipping package
     */
    private function buildShippingPackage(array $destination, array $lineItems): array
    {
        $contents = [];
        $subtotal = 0;

        foreach ($lineItems as $key => $lineItem) {
            $productId = (int) ($lineItem['item']['id'] ?? 0);
            $product = wc_get_product($productId);

            if (!$product) {
                continue;
            }

            $quantity = (int) ($lineItem['quantity'] ?? 1);
            $lineSubtotal = ((float) $product->get_price()) * $quantity;

            $contents[$key] = [
                'key' => (string) $key,
                'product_id' => $productId,
                'variation_id' => 0,
                'variation' => [],
                'quantity' => $quantity,
                'data' => $product,
                'line_total' => $lineSubtotal,
                'line_subtotal' => $lineSubtotal,
                'line_tax' => 0,
                'line_subtotal_tax' => 0,
            ];

            $subtotal += $lineSubtotal;
        }

        return [
            'contents' => $contents,
            'contents_cost' => $subtotal,
            'applied_coupons' => [],
            'user' => [
                'ID' => get_current_user_id(),
            ],
            'destination' => [
                'country' => $destination['address_country'] ?? $destination['country'] ?? '',
                'state' => $destination['address_region'] ?? $destination['state'] ?? '',
                'postcode' => $destination['postal_code'] ?? $destination['zip'] ?? '',
                'city' => $destination['address_locality'] ?? $destination['city'] ?? '',
                'address' => $destination['street_address'] ?? $destination['address'] ?? '',
                'address_1' => $destination['street_address'] ?? $destination['address'] ?? '',
                'address_2' => $destination['extended_address'] ?? '',
            ],
            'cart_subtotal' => $subtotal,
        ];
    }

    /**
     * Convert a price to minor units (cents).
     *
     * @param string|float|int $amount Amount in major units
     * @return int Amount in minor units
     */
    private function convertToMinorUnits(string|float|int $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
