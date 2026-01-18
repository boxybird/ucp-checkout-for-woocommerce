<?php

namespace UcpCheckout\WooCommerce\Payment\Handlers;

use UcpCheckout\WooCommerce\Payment\Contracts\ManifestContributorInterface;
use UcpCheckout\WooCommerce\Payment\Contracts\PaymentHandlerInterface;
use UcpCheckout\WooCommerce\Payment\PaymentResult;
use UcpCheckout\WooCommerce\Payment\PrepareResult;
use UcpCheckout\WooCommerce\Payment\Traits\HandlesGatewayErrors;
use UcpCheckout\WooCommerce\Payment\Traits\HandlesOrderMeta;
use WC_Order;
use WC_Payment_Gateway;

/**
 * Fallback handler for tokenized payment gateways.
 *
 * Handles any gateway that supports tokenization but doesn't have
 * a dedicated handler. Uses generic token storage and processing.
 */
class GenericTokenHandler implements PaymentHandlerInterface, ManifestContributorInterface
{
    use HandlesOrderMeta;
    use HandlesGatewayErrors;

    /**
     * Known gateways that support tokenization.
     */
    private const array TOKEN_GATEWAYS = [
        'ppcp-gateway',          // PayPal Commerce Platform
        'square_credit_card',    // Square
        'braintree_credit_card', // Braintree
    ];

    /**
     * {@inheritdoc}
     */
    public function supports(WC_Payment_Gateway $gateway): bool
    {
        // Support known token gateways
        if (in_array($gateway->id, self::TOKEN_GATEWAYS, true)) {
            return true;
        }

        // Support any gateway that advertises tokenization
        if ($gateway->supports('tokenization')) {
            return true;
        }

        // Fallback: support any gateway as last resort
        // This makes it a true fallback handler
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority(): int
    {
        return 1; // Lowest priority - fallback handler
    }

    /**
     * {@inheritdoc}
     */
    public function prepare(WC_Order $order, WC_Payment_Gateway $gateway, array $ucpPaymentData): PrepareResult
    {
        $credential = $ucpPaymentData['credential'] ?? [];

        // Set payment method on order
        $this->setPaymentMethod($order, $gateway);

        // Store token if provided
        if (!empty($credential['token'])) {
            $this->storePaymentToken($order, $credential['token']);

            // Store as generic payment token for gateway access
            $order->update_meta_data('_payment_token', $credential['token']);
        }

        // Store card display info
        $this->storeCardDisplayInfo($order, $credential);

        $this->saveOrder($order);

        // Set up $_POST for gateway
        $_POST['payment_method'] = $gateway->id;

        if (!empty($credential['token'])) {
            // Generic token field that some gateways check
            $_POST['wc-' . $gateway->id . '-payment-token'] = $credential['token'];
        }

        // Fire action for custom preparation
        do_action('ucp_prepare_payment_data', $order, $gateway, $ucpPaymentData);

        return PrepareResult::success('Payment prepared for processing');
    }

    /**
     * {@inheritdoc}
     */
    public function process(WC_Order $order, WC_Payment_Gateway $gateway): PaymentResult
    {
        try {
            /** @var array{result?: string, redirect?: string}|null $result */
            $result = $gateway->process_payment($order->get_id());

            if ($this->isSuccessfulResult($result)) {
                $transactionId = $order->get_transaction_id() ?: null;

                return PaymentResult::success(
                    'Payment processed successfully',
                    $this->getRedirectFromResult($result),
                    $transactionId
                );
            }

            $errorMessage = $this->extractErrorFromNotices();
            return PaymentResult::failure($errorMessage);
        } catch (\Exception $e) {
            return PaymentResult::failure($e->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function finalize(WC_Order $order, WC_Payment_Gateway $gateway, PaymentResult $result): void
    {
        $this->clearNotices();
    }

    /**
     * {@inheritdoc}
     */
    public function buildManifestEntry(WC_Payment_Gateway $gateway, string $ucpVersion): array
    {
        $instrumentTypes = $this->getInstrumentTypes($gateway);

        return [
            'id' => $gateway->id,
            'name' => 'dev.ucp.payment.' . $this->normalizeGatewayName($gateway->id),
            'version' => $ucpVersion,
            'spec' => 'https://ucp.dev/specification/payment-handlers/gateway',
            'config_schema' => 'https://ucp.dev/schemas/payment/gateway-config.json',
            'instrument_schemas' => $this->getInstrumentSchemas($instrumentTypes),
            'config' => [
                'gateway_id' => $gateway->id,
                'gateway_title' => $gateway->get_title(),
                'supported_types' => $instrumentTypes,
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getInstrumentTypes(WC_Payment_Gateway $gateway): array
    {
        $types = [];
        $gatewayId = strtolower($gateway->id);

        // Card-based gateways
        if (str_contains($gatewayId, 'stripe')
            || str_contains($gatewayId, 'square')
            || str_contains($gatewayId, 'braintree')
            || str_contains($gatewayId, 'card')
            || str_contains($gatewayId, 'credit')) {
            $types[] = 'card';
        }

        // PayPal
        if (str_contains($gatewayId, 'paypal') || str_contains($gatewayId, 'ppcp')) {
            $types[] = 'paypal';
        }

        // Bank/ACH
        if (str_contains($gatewayId, 'bank') || str_contains($gatewayId, 'ach')) {
            $types[] = 'bank_account';
        }

        // Default to card if unknown
        if (empty($types)) {
            $types[] = 'card';
        }

        return $types;
    }

    /**
     * Normalize gateway name for UCP handler naming.
     *
     * @param string $gatewayId WC gateway ID
     * @return string
     */
    private function normalizeGatewayName(string $gatewayId): string
    {
        $name = str_replace(['wc_', 'woocommerce_', '_gateway', '_cc', '_credit_card'], '', $gatewayId);
        return str_replace(['-', ' '], '_', strtolower($name));
    }

    /**
     * Get instrument schemas for given types.
     *
     * @param array $types Instrument types
     * @return array Schema URLs
     */
    private function getInstrumentSchemas(array $types): array
    {
        $schemas = [];

        foreach ($types as $type) {
            $schemas[] = match ($type) {
                'card' => 'https://ucp.dev/schemas/payment/card-instrument.json',
                'paypal' => 'https://ucp.dev/schemas/payment/paypal-instrument.json',
                'bank_account' => 'https://ucp.dev/schemas/payment/bank-instrument.json',
                default => 'https://ucp.dev/schemas/payment/generic-instrument.json',
            };
        }

        return array_unique($schemas);
    }
}
