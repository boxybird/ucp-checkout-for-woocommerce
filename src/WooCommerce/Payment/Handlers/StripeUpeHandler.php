<?php

namespace UcpCheckout\WooCommerce\Payment\Handlers;

use UcpCheckout\WooCommerce\Payment\Contracts\CredentialTransformerInterface;
use UcpCheckout\WooCommerce\Payment\Contracts\ManifestContributorInterface;
use UcpCheckout\WooCommerce\Payment\Contracts\PaymentHandlerInterface;
use UcpCheckout\WooCommerce\Payment\PaymentResult;
use UcpCheckout\WooCommerce\Payment\PrepareResult;
use UcpCheckout\WooCommerce\Payment\Traits\HandlesGatewayErrors;
use UcpCheckout\WooCommerce\Payment\Traits\HandlesOrderMeta;
use WC_Order;
use WC_Payment_Gateway;

/**
 * Handler for Stripe UPE (Universal Payment Element) gateways.
 *
 * Supports the modern Stripe for WooCommerce plugin using PaymentMethod IDs
 * and deferred payment intents.
 */
class StripeUpeHandler implements PaymentHandlerInterface, ManifestContributorInterface, CredentialTransformerInterface
{
    use HandlesOrderMeta;
    use HandlesGatewayErrors;

    /**
     * Stripe gateway IDs.
     */
    private const array STRIPE_GATEWAY_IDS = [
        'stripe',
        'stripe_cc',
        'woocommerce_payments',
    ];

    /**
     * {@inheritdoc}
     */
    public function supports(WC_Payment_Gateway $gateway): bool
    {
        // Check by ID
        if (in_array($gateway->id, self::STRIPE_GATEWAY_IDS, true)) {
            return true;
        }

        // Check by class name for variations
        $className = strtolower($gateway::class);
        return str_contains($className, 'stripe');
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority(): int
    {
        return 100; // High priority - check Stripe first
    }

    /**
     * {@inheritdoc}
     */
    public function prepare(WC_Order $order, WC_Payment_Gateway $gateway, array $ucpPaymentData): PrepareResult
    {
        $credential = $ucpPaymentData['credential'] ?? [];
        $token = $credential['token'] ?? '';

        if (empty($token)) {
            return PrepareResult::failure('No payment token provided for Stripe payment');
        }

        // Set payment method on order
        $this->setPaymentMethod($order, $gateway);

        // Store UCP token
        $this->storePaymentToken($order, $token);

        // Transform and store credential data
        $transformedCredential = $this->transform($credential, $order, $gateway);

        // Store Stripe-specific meta
        $order->update_meta_data('_stripe_payment_method', $transformedCredential['payment_method_id']);

        // Store card display info
        $this->storeCardDisplayInfo($order, $credential);

        $this->saveOrder($order);

        // Set up $_POST for Stripe gateway
        $this->setupStripePostData($gateway, $transformedCredential);

        return PrepareResult::success('Stripe payment prepared successfully', [
            'payment_method_id' => $transformedCredential['payment_method_id'],
        ]);
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
                // Get transaction ID if available
                $transactionId = $order->get_transaction_id() ?: null;

                return PaymentResult::success(
                    'Payment processed successfully',
                    $this->getRedirectFromResult($result),
                    $transactionId
                );
            }

            $errorMessage = $this->extractErrorFromNotices('Stripe payment processing failed');
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

        // Clean up POST data
        $this->cleanupStripePostData();
    }

    /**
     * {@inheritdoc}
     */
    public function transform(array $credential, WC_Order $order, WC_Payment_Gateway $gateway): array
    {
        $token = $credential['token'] ?? '';

        // The token should be a Stripe PaymentMethod ID (pm_xxx) or legacy source (src_xxx)
        $isPaymentMethod = str_starts_with($token, 'pm_');
        $isSource = str_starts_with($token, 'src_');
        $isConfirmationToken = str_starts_with($token, 'ctoken_');

        return [
            'payment_method_id' => $token,
            'is_payment_method' => $isPaymentMethod,
            'is_source' => $isSource,
            'is_confirmation_token' => $isConfirmationToken,
            'confirmation_token' => $isConfirmationToken ? $token : ($credential['confirmation_token'] ?? null),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function buildManifestEntry(WC_Payment_Gateway $gateway, string $ucpVersion): array
    {
        return [
            'id' => $gateway->id,
            'name' => 'dev.ucp.payment.stripe',
            'version' => $ucpVersion,
            'spec' => 'https://ucp.dev/specification/payment-handlers/stripe',
            'config_schema' => 'https://ucp.dev/schemas/payment/stripe-config.json',
            'instrument_schemas' => [
                'https://ucp.dev/schemas/payment/card-instrument.json',
            ],
            'config' => [
                'gateway_id' => $gateway->id,
                'gateway_title' => $gateway->get_title(),
                'supported_types' => ['card'],
                'supported_networks' => ['visa', 'mastercard', 'amex', 'discover'],
                'requires_payment_method_id' => true,
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getInstrumentTypes(WC_Payment_Gateway $gateway): array
    {
        return ['card'];
    }

    /**
     * Set up $_POST data expected by Stripe for WooCommerce.
     *
     * @param WC_Payment_Gateway $gateway The gateway
     * @param array $transformedCredential Transformed credential data
     */
    private function setupStripePostData(WC_Payment_Gateway $gateway, array $transformedCredential): void
    {
        $_POST['payment_method'] = $gateway->id;

        // Modern Stripe UPE expects wc-stripe-payment-method (PaymentMethod ID)
        $_POST['wc-stripe-payment-method'] = $transformedCredential['payment_method_id'];

        // Signal that this is a deferred intent payment
        $_POST['wc-stripe-is-deferred-intent'] = 'true';

        // If we have a confirmation token, set it
        if (!empty($transformedCredential['confirmation_token'])) {
            $_POST['wc-stripe-confirmation-token'] = $transformedCredential['confirmation_token'];
        }

        // Legacy support - some versions still check these
        if ($transformedCredential['is_payment_method']) {
            $_POST['wc-stripe-payment-token'] = $transformedCredential['payment_method_id'];
        }

        // Don't set stripe_source for modern UPE - it causes issues
        // Legacy source support only if explicitly a source
        if ($transformedCredential['is_source']) {
            $_POST['stripe_source'] = $transformedCredential['payment_method_id'];
        }
    }

    /**
     * Clean up $_POST data after processing.
     */
    private function cleanupStripePostData(): void
    {
        unset(
            $_POST['wc-stripe-payment-method'],
            $_POST['wc-stripe-payment-token'],
            $_POST['wc-stripe-is-deferred-intent'],
            $_POST['wc-stripe-confirmation-token'],
            $_POST['stripe_source']
        );
    }
}
