<?php

namespace UcpCheckout\WooCommerce;

use UcpCheckout\WooCommerce\Payment\Contracts\ManifestContributorInterface;
use UcpCheckout\WooCommerce\Payment\GatewayResolver;
use UcpCheckout\WooCommerce\Payment\ManifestPaymentHandlerBuilder;
use UcpCheckout\WooCommerce\Payment\PaymentHandlerFactory;
use UcpCheckout\WooCommerce\Payment\PaymentHandlerRegistry;
use UcpCheckout\WooCommerce\Payment\PaymentProcessor;
use WC_Order;
use WC_Payment_Gateway;

/**
 * Facade service for WooCommerce integration.
 * Coordinates payment processing, shipping, tax, and inventory management.
 */
readonly class WooCommerceService
{
    private PaymentProcessor $paymentProcessor;
    private ManifestPaymentHandlerBuilder $manifestBuilder;

    public function __construct(
        private TaxCalculator       $taxCalculator = new TaxCalculator(),
        private ShippingCalculator  $shippingCalculator = new ShippingCalculator(),
        ?PaymentProcessor           $paymentProcessor = null,
        ?PaymentHandlerRegistry     $handlerRegistry = null
    ) {
        // Create PaymentProcessor if not provided (backwards compatibility)
        $registry = $handlerRegistry ?? new PaymentHandlerRegistry();
        $factory = new PaymentHandlerFactory($registry);
        $resolver = new GatewayResolver($factory);

        if ($paymentProcessor === null) {
            $this->paymentProcessor = new PaymentProcessor($resolver, $factory);
        } else {
            $this->paymentProcessor = $paymentProcessor;
        }

        $this->manifestBuilder = new ManifestPaymentHandlerBuilder($factory, $resolver);
    }

    /**
     * Get available WooCommerce payment gateways.
     *
     * @return array<string, array{id: string, name: string, description: string, instrument_types: array}>
     */
    public function getAvailablePaymentGateways(): array
    {
        $gateways = $this->paymentProcessor->getGatewayResolver()->getAvailableGateways();
        $handlerFactory = $this->paymentProcessor->getHandlerFactory();
        $available = [];

        foreach ($gateways as $gateway) {
            $handler = $handlerFactory->getHandler($gateway);
            $instrumentTypes = ['card']; // default

            if ($handler instanceof ManifestContributorInterface) {
                $instrumentTypes = $handler->getInstrumentTypes($gateway);
            }

            $available[$gateway->id] = [
                'id' => $gateway->id,
                'name' => $gateway->get_title(),
                'description' => $gateway->get_description(),
                'instrument_types' => $instrumentTypes,
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
        return $this->paymentProcessor->processPayment($order, $paymentData);
    }

    /**
     * Get the payment processor instance.
     *
     * @return PaymentProcessor
     */
    public function getPaymentProcessor(): PaymentProcessor
    {
        return $this->paymentProcessor;
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
        return $this->paymentProcessor->getGatewayResolver()->resolve($handlerId);
    }

    /**
     * Build payment handlers for UCP manifest from available WC gateways.
     *
     * @return array UCP spec-compliant payment handlers
     */
    public function buildPaymentHandlersForManifest(): array
    {
        return $this->manifestBuilder->build();
    }

    /**
     * Get the handler registry for direct access.
     *
     * @return PaymentHandlerRegistry
     */
    public function getHandlerRegistry(): PaymentHandlerRegistry
    {
        // Access registry through the processor's factory
        // This is a bit indirect but maintains encapsulation
        return $this->paymentProcessor->getHandlerFactory()->getRegistry();
    }
}
