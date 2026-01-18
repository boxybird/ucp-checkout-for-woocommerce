<?php

namespace UcpCheckout\WooCommerce\Payment\Contracts;

use UcpCheckout\WooCommerce\Payment\PaymentResult;
use UcpCheckout\WooCommerce\Payment\PrepareResult;
use WC_Order;
use WC_Payment_Gateway;

/**
 * Contract for payment gateway handlers.
 *
 * Handlers implement the strategy pattern for processing payments
 * through different WooCommerce payment gateways.
 */
interface PaymentHandlerInterface
{
    /**
     * Determine if this handler supports the given gateway.
     *
     * @param WC_Payment_Gateway $gateway The WooCommerce payment gateway
     * @return bool True if this handler can process payments for the gateway
     */
    public function supports(WC_Payment_Gateway $gateway): bool;

    /**
     * Get the priority of this handler.
     *
     * Higher priority handlers are checked first.
     * Used when multiple handlers could support the same gateway.
     *
     * @return int Handler priority (higher = checked first)
     */
    public function getPriority(): int;

    /**
     * Prepare the order and environment for payment processing.
     *
     * Sets up order meta, $_POST data, and any other state required
     * by the gateway before calling process_payment().
     *
     * @param WC_Order $order The WooCommerce order
     * @param WC_Payment_Gateway $gateway The payment gateway
     * @param array $ucpPaymentData UCP payment data with handler_id and credential
     * @return PrepareResult Result indicating success or failure
     */
    public function prepare(WC_Order $order, WC_Payment_Gateway $gateway, array $ucpPaymentData): PrepareResult;

    /**
     * Process the payment through the gateway.
     *
     * Calls the gateway's process_payment() method and handles the result.
     *
     * @param WC_Order $order The WooCommerce order
     * @param WC_Payment_Gateway $gateway The payment gateway
     * @return PaymentResult Result of payment processing
     */
    public function process(WC_Order $order, WC_Payment_Gateway $gateway): PaymentResult;

    /**
     * Perform any post-processing after payment completion.
     *
     * Called after successful payment to perform any cleanup or
     * additional operations (e.g., clearing notices, updating meta).
     *
     * @param WC_Order $order The WooCommerce order
     * @param WC_Payment_Gateway $gateway The payment gateway
     * @param PaymentResult $result The payment result
     */
    public function finalize(WC_Order $order, WC_Payment_Gateway $gateway, PaymentResult $result): void;
}
