<?php

namespace UcpCheckout\WooCommerce;

use WC_Order;
use WC_Payment_Gateway;

/**
 * Facade service for WooCommerce integration.
 * Coordinates payment processing, shipping, tax, and inventory management.
 */
class WooCommerceService
{
    public function __construct(
        private readonly TaxCalculator $taxCalculator = new TaxCalculator(),
        private readonly ShippingCalculator $shippingCalculator = new ShippingCalculator(),
        private readonly PaymentGatewayAdapter $paymentAdapter = new PaymentGatewayAdapter()
    ) {
    }

    /**
     * Get available WooCommerce payment gateways.
     *
     * @return array<string, array{id: string, name: string, description: string, instrument_types: array}>
     */
    public function getAvailablePaymentGateways(): array
    {
        if (!function_exists('WC')) {
            return [];
        }

        $paymentGateways = WC()->payment_gateways();
        if (!$paymentGateways) {
            return [];
        }

        $gateways = $paymentGateways->get_available_payment_gateways();
        $available = [];

        foreach ($gateways as $gateway) {
            $available[$gateway->id] = [
                'id' => $gateway->id,
                'name' => $gateway->get_title(),
                'description' => $gateway->get_description(),
                'instrument_types' => $this->paymentAdapter->getInstrumentTypes($gateway),
            ];
        }

        return $available;
    }

    /**
     * Process payment through a WooCommerce gateway.
     *
     * @param WC_Order $order The order to process payment for
     * @param array $paymentData UCP payment data with handler_id and credential
     * @return array{success: bool, message: string, redirect?: string}
     */
    public function processPayment(WC_Order $order, array $paymentData): array
    {
        return $this->paymentAdapter->processPayment($order, $paymentData);
    }

    /**
     * Calculate shipping rates for the given destination and line items.
     *
     * @param array $destination Shipping address
     * @param array $lineItems UCP line items
     * @return array{options: array, selected?: string}
     */
    public function calculateShipping(array $destination, array $lineItems): array
    {
        return $this->shippingCalculator->calculate($destination, $lineItems);
    }

    /**
     * Get available shipping methods for a destination.
     *
     * @param array $destination Shipping address
     * @param array $lineItems UCP line items
     * @return array<array{id: string, name: string, amount: int}>
     */
    public function getAvailableShippingMethods(array $destination, array $lineItems): array
    {
        return $this->shippingCalculator->getAvailableMethods($destination, $lineItems);
    }

    /**
     * Calculate tax for the given destination and line items.
     *
     * @param array $destination Shipping/billing address
     * @param array $lineItems UCP line items
     * @return int Tax amount in minor units (cents)
     */
    public function calculateTax(array $destination, array $lineItems): int
    {
        return $this->taxCalculator->calculate($destination, $lineItems);
    }

    /**
     * Reduce stock levels for an order.
     *
     * @param WC_Order $order The completed order
     */
    public function reduceStock(WC_Order $order): void
    {
        if (function_exists('wc_reduce_stock_levels')) {
            wc_reduce_stock_levels($order->get_id());
        }
    }

    /**
     * Verify stock is available for all line items.
     *
     * @param array $lineItems UCP line items
     * @return array<string, string> Map of item ID to error message for out-of-stock items
     */
    public function verifyStock(array $lineItems): array
    {
        $errors = [];

        foreach ($lineItems as $lineItem) {
            $productId = (int) ($lineItem['item']['id'] ?? 0);
            $quantity = (int) ($lineItem['quantity'] ?? 1);

            $product = wc_get_product($productId);
            if (!$product) {
                $errors[(string) $productId] = "Product not found: {$productId}";
                continue;
            }

            if (!$product->is_in_stock()) {
                $errors[(string) $productId] = "Product out of stock: {$product->get_name()}";
                continue;
            }

            if ($product->managing_stock() && !$product->has_enough_stock($quantity)) {
                $stockQty = $product->get_stock_quantity();
                $errors[(string) $productId] = "Insufficient stock for {$product->get_name()}. Available: {$stockQty}, Requested: {$quantity}";
            }
        }

        return $errors;
    }

    /**
     * Map a UCP payment handler ID to a WooCommerce gateway.
     *
     * @param string $handlerId UCP payment handler ID
     * @return WC_Payment_Gateway|null
     */
    public function mapPaymentHandler(string $handlerId): ?WC_Payment_Gateway
    {
        return $this->paymentAdapter->mapToGateway($handlerId);
    }

    /**
     * Build payment handlers for UCP manifest from available WC gateways.
     *
     * @return array UCP spec-compliant payment handlers
     */
    public function buildPaymentHandlersForManifest(): array
    {
        return $this->paymentAdapter->buildManifestHandlers();
    }
}
