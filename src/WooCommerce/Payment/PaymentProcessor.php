<?php

namespace UcpCheckout\WooCommerce\Payment;

use UcpCheckout\WooCommerce\WooCommerceContextInitializer;
use WC_Order;

/**
 * Orchestrates payment processing through the handler architecture.
 *
 * This is the main entry point for processing payments via UCP.
 * It coordinates gateway resolution, handler selection, and the
 * prepare -> process -> finalize workflow.
 */
class PaymentProcessor
{
    public function __construct(
        private readonly GatewayResolver $gatewayResolver,
        private readonly PaymentHandlerFactory $handlerFactory
    ) {
    }

    /**
     * Process payment for an order using UCP payment data.
     *
     * @param WC_Order $order The WooCommerce order
     * @param array $paymentData UCP payment data with handler_id and credential
     * @return array{success: bool, message: string, redirect?: string, transaction_id?: string}
     */
    public function processPayment(WC_Order $order, array $paymentData): array
    {
        $handlerId = $paymentData['handler_id'] ?? 'ucp_agent';

        // Resolve handler ID to WooCommerce gateway
        $gateway = $this->gatewayResolver->resolve($handlerId);

        if (!$gateway) {
            return [
                'success' => false,
                'message' => "No payment gateway available for handler: {$handlerId}",
            ];
        }

        // Get the appropriate handler for this gateway
        $handler = $this->handlerFactory->getHandler($gateway);

        if (!$handler) {
            return [
                'success' => false,
                'message' => "No payment handler available for gateway: {$gateway->id}",
            ];
        }

        // Initialize WooCommerce context (session, customer, cart)
        WooCommerceContextInitializer::initialize();

        // Phase 1: Prepare
        $prepareResult = $handler->prepare($order, $gateway, $paymentData);

        if ($prepareResult->isFailure()) {
            return [
                'success' => false,
                'message' => $prepareResult->message,
            ];
        }

        // Phase 2: Process
        $paymentResult = $handler->process($order, $gateway);

        // Phase 3: Finalize (always runs, even on failure)
        $handler->finalize($order, $gateway, $paymentResult);

        return $paymentResult->toArray();
    }

    /**
     * Get the gateway resolver for direct access.
     *
     * @return GatewayResolver
     */
    public function getGatewayResolver(): GatewayResolver
    {
        return $this->gatewayResolver;
    }

    /**
     * Get the handler factory for direct access.
     *
     * @return PaymentHandlerFactory
     */
    public function getHandlerFactory(): PaymentHandlerFactory
    {
        return $this->handlerFactory;
    }
}
