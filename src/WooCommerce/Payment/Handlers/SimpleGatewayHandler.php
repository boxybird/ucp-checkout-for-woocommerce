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
 * Handler for simple payment gateways (cheque, bacs, cod).
 *
 * These gateways don't require tokenization or external payment processing.
 * They simply mark the order as pending/on-hold for manual processing.
 */
class SimpleGatewayHandler implements PaymentHandlerInterface, ManifestContributorInterface
{
    use HandlesOrderMeta;
    use HandlesGatewayErrors;

    /**
     * Simple gateway IDs that this handler supports.
     */
    private const array SUPPORTED_GATEWAYS = [
        'cheque',
        'bacs',
        'cod',
    ];

    /**
     * {@inheritdoc}
     */
    public function supports(WC_Payment_Gateway $gateway): bool
    {
        return in_array($gateway->id, self::SUPPORTED_GATEWAYS, true);
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority(): int
    {
        return 10;
    }

    /**
     * {@inheritdoc}
     */
    public function prepare(WC_Order $order, WC_Payment_Gateway $gateway, array $ucpPaymentData): PrepareResult
    {
        // Set payment method on order
        $this->setPaymentMethod($order, $gateway);

        // Set up $_POST for gateway expectations
        $_POST['payment_method'] = $gateway->id;

        $this->saveOrder($order);

        return PrepareResult::success('Order prepared for simple gateway payment');
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
                return PaymentResult::success(
                    'Payment processed successfully',
                    $this->getRedirectFromResult($result)
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
        return [
            'id' => $gateway->id,
            'name' => 'dev.ucp.payment.' . $this->normalizeGatewayName($gateway->id),
            'version' => $ucpVersion,
            'spec' => 'https://ucp.dev/specification/payment-handlers/offline',
            'config_schema' => 'https://ucp.dev/schemas/payment/offline-config.json',
            'instrument_schemas' => [],
            'config' => [
                'gateway_id' => $gateway->id,
                'gateway_title' => $gateway->get_title(),
                'payment_type' => $this->getPaymentType($gateway->id),
                'requires_online_payment' => false,
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getInstrumentTypes(WC_Payment_Gateway $gateway): array
    {
        // Simple gateways don't use instruments
        return [];
    }

    /**
     * Get the payment type description for the gateway.
     *
     * @param string $gatewayId Gateway ID
     * @return string
     */
    private function getPaymentType(string $gatewayId): string
    {
        return match ($gatewayId) {
            'cheque' => 'cheque',
            'bacs' => 'bank_transfer',
            'cod' => 'cash_on_delivery',
            default => 'offline',
        };
    }

    /**
     * Normalize gateway name for UCP handler naming.
     *
     * @param string $gatewayId WC gateway ID
     * @return string
     */
    private function normalizeGatewayName(string $gatewayId): string
    {
        $name = str_replace(['wc_', 'woocommerce_', '_gateway'], '', $gatewayId);
        return str_replace(['-', ' '], '_', strtolower($name));
    }
}
