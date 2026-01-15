<?php

namespace UcpCheckout\WooCommerce;

use WC_Tax;

/**
 * Calculates taxes using WooCommerce tax API.
 */
class TaxCalculator
{
    /**
     * Calculate total tax for line items based on destination address.
     *
     * @param array $destination Shipping/billing address with address_country, address_region, postal_code, address_locality
     * @param array $lineItems UCP line items with item.id, item.unit_price (in cents), quantity
     * @return int Total tax amount in minor units (cents)
     */
    public function calculate(array $destination, array $lineItems): int
    {
        if (!class_exists('WC_Tax') || !wc_tax_enabled()) {
            return 0;
        }

        $taxTotal = 0;
        $taxLocation = $this->buildTaxLocation($destination);

        foreach ($lineItems as $lineItem) {
            $productId = (int) ($lineItem['item']['id'] ?? 0);
            $product = wc_get_product($productId);

            if (!$product) {
                continue;
            }

            // Get line item subtotal in major units (dollars)
            $subtotalCents = $this->getLineItemSubtotal($lineItem);
            $subtotal = $subtotalCents / 100;

            // Get tax class for this product
            $taxClass = $product->get_tax_class();

            // Get applicable tax rates
            $taxRates = WC_Tax::get_rates_for_tax_class($taxClass);

            // If no rates for product's tax class, try with location
            if (empty($taxRates)) {
                $taxRates = WC_Tax::find_rates([
                    'country' => $taxLocation['country'],
                    'state' => $taxLocation['state'],
                    'postcode' => $taxLocation['postcode'],
                    'city' => $taxLocation['city'],
                    'tax_class' => $taxClass,
                ]);
            }

            if (empty($taxRates)) {
                continue;
            }

            // Calculate tax for this line item
            $pricesIncludeTax = wc_prices_include_tax();
            $taxes = WC_Tax::calc_tax($subtotal, $taxRates, $pricesIncludeTax);
            $lineTax = array_sum($taxes);

            $taxTotal += $lineTax;
        }

        // Convert back to minor units (cents)
        return (int) round($taxTotal * 100);
    }

    /**
     * Get tax rate for a specific address.
     *
     * @param array $destination Address
     * @param string $taxClass Tax class (empty string for standard)
     * @return float Combined tax rate as percentage (e.g., 8.25 for 8.25%)
     */
    public function getTaxRateForAddress(array $destination, string $taxClass = ''): float
    {
        if (!class_exists('WC_Tax') || !wc_tax_enabled()) {
            return 0.0;
        }

        $taxLocation = $this->buildTaxLocation($destination);

        $taxRates = WC_Tax::find_rates([
            'country' => $taxLocation['country'],
            'state' => $taxLocation['state'],
            'postcode' => $taxLocation['postcode'],
            'city' => $taxLocation['city'],
            'tax_class' => $taxClass,
        ]);

        if (empty($taxRates)) {
            return 0.0;
        }

        // Sum all applicable rates
        $combinedRate = 0.0;
        foreach ($taxRates as $rate) {
            $combinedRate += (float) ($rate['rate'] ?? 0);
        }

        return $combinedRate;
    }

    /**
     * Build WooCommerce tax location array from UCP address format.
     *
     * @param array $destination UCP address
     * @return array WC tax location
     */
    private function buildTaxLocation(array $destination): array
    {
        return [
            'country' => $destination['address_country'] ?? $destination['country'] ?? '',
            'state' => $destination['address_region'] ?? $destination['state'] ?? '',
            'postcode' => $destination['postal_code'] ?? $destination['zip'] ?? '',
            'city' => $destination['address_locality'] ?? $destination['city'] ?? '',
        ];
    }

    /**
     * Get subtotal for a line item in cents.
     *
     * @param array $lineItem UCP line item
     * @return int Subtotal in cents
     */
    private function getLineItemSubtotal(array $lineItem): int
    {
        // First try to get from totals array
        foreach ($lineItem['totals'] ?? [] as $total) {
            if ($total['type'] === 'subtotal') {
                return (int) $total['amount'];
            }
        }

        // Fall back to unit_price * quantity
        $unitPrice = (int) ($lineItem['item']['unit_price'] ?? 0);
        $quantity = (int) ($lineItem['quantity'] ?? 1);

        return $unitPrice * $quantity;
    }
}
