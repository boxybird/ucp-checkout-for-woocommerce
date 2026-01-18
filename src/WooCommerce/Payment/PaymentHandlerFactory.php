<?php

namespace UcpCheckout\WooCommerce\Payment;

use UcpCheckout\WooCommerce\Payment\Contracts\PaymentHandlerInterface;
use WC_Payment_Gateway;

/**
 * Factory for selecting the appropriate payment handler for a gateway.
 *
 * Uses the handler registry to find the best matching handler
 * based on gateway support and handler priority.
 */
class PaymentHandlerFactory
{
    public function __construct(
        private readonly PaymentHandlerRegistry $registry
    ) {
    }

    /**
     * Get the appropriate handler for a payment gateway.
     *
     * Returns the highest-priority handler that supports the gateway.
     *
     * @param WC_Payment_Gateway $gateway The WooCommerce payment gateway
     * @return PaymentHandlerInterface|null The matching handler, or null if none found
     */
    public function getHandler(WC_Payment_Gateway $gateway): ?PaymentHandlerInterface
    {
        foreach ($this->registry->getHandlers() as $handler) {
            if ($handler->supports($gateway)) {
                return $handler;
            }
        }

        return null;
    }

    /**
     * Get all handlers that support a gateway.
     *
     * @param WC_Payment_Gateway $gateway The WooCommerce payment gateway
     * @return PaymentHandlerInterface[]
     */
    public function getSupportingHandlers(WC_Payment_Gateway $gateway): array
    {
        return array_filter(
            $this->registry->getHandlers(),
            fn($handler) => $handler->supports($gateway)
        );
    }

    /**
     * Check if any handler supports the gateway.
     *
     * @param WC_Payment_Gateway $gateway The WooCommerce payment gateway
     * @return bool
     */
    public function hasHandler(WC_Payment_Gateway $gateway): bool
    {
        return $this->getHandler($gateway) !== null;
    }

    /**
     * Get the handler registry.
     *
     * @return PaymentHandlerRegistry
     */
    public function getRegistry(): PaymentHandlerRegistry
    {
        return $this->registry;
    }
}
