<?php

namespace UcpCheckout\WooCommerce\Payment;

use UcpCheckout\WooCommerce\Payment\Contracts\PaymentHandlerInterface;
use UcpCheckout\WooCommerce\Payment\Handlers\GenericTokenHandler;
use UcpCheckout\WooCommerce\Payment\Handlers\SimpleGatewayHandler;
use UcpCheckout\WooCommerce\Payment\Handlers\StripeUpeHandler;

/**
 * Registry for payment handlers.
 *
 * Manages the collection of available payment handlers and allows
 * third-party extension through WordPress filters.
 */
class PaymentHandlerRegistry
{
    /**
     * @var PaymentHandlerInterface[]
     */
    private array $handlers = [];

    /**
     * @var bool
     */
    private bool $initialized = false;

    /**
     * Initialize the registry with default handlers.
     */
    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        // Register built-in handlers
        $this->register(new StripeUpeHandler());
        $this->register(new SimpleGatewayHandler());
        $this->register(new GenericTokenHandler());

        // Allow third-party handlers via filter
        $customHandlers = apply_filters('ucp_payment_handlers', []);

        foreach ($customHandlers as $handler) {
            if ($handler instanceof PaymentHandlerInterface) {
                $this->register($handler);
            }
        }

        $this->initialized = true;
    }

    /**
     * Register a payment handler.
     *
     * @param PaymentHandlerInterface $handler The handler to register
     */
    public function register(PaymentHandlerInterface $handler): void
    {
        $this->handlers[] = $handler;

        // Re-sort handlers by priority (highest first)
        usort($this->handlers, fn($a, $b) => $b->getPriority() <=> $a->getPriority());
    }

    /**
     * Get all registered handlers, sorted by priority.
     *
     * @return PaymentHandlerInterface[]
     */
    public function getHandlers(): array
    {
        $this->initialize();
        return $this->handlers;
    }

    /**
     * Get handler class names (for testing/debugging).
     *
     * @return string[]
     */
    public function getHandlerClasses(): array
    {
        return array_map(fn($handler) => $handler::class, $this->getHandlers());
    }

    /**
     * Clear all handlers (primarily for testing).
     */
    public function clear(): void
    {
        $this->handlers = [];
        $this->initialized = false;
    }
}
