<?php

namespace UcpCheckout\WooCommerce;

use UcpCheckout\Config\PluginConfig;
use WC_Order;
use WC_Payment_Gateway;

/**
 * Adapts UCP payment data to WooCommerce payment gateways.
 */
class PaymentGatewayAdapter
{
    /**
     * Mapping of UCP handler IDs to WooCommerce gateway IDs.
     * Can be extended via WordPress filters.
     */
    private const array HANDLER_TO_GATEWAY_MAP = [
        'ucp_stripe' => 'stripe',
        'ucp_paypal' => 'ppcp-gateway',
        'ucp_square' => 'square_credit_card',
        'ucp_braintree' => 'braintree_credit_card',
    ];

    /**
     * Map a UCP payment handler ID to a WooCommerce gateway.
     *
     * @param string $handlerId UCP payment handler ID
     * @return WC_Payment_Gateway|null
     */
    public function mapToGateway(string $handlerId): ?WC_Payment_Gateway
    {
        if (!function_exists('WC')) {
            return null;
        }

        $paymentGateways = WC()->payment_gateways();
        if (!$paymentGateways) {
            return null;
        }

        $gateways = $paymentGateways->get_available_payment_gateways();

        // Allow filtering of the handler-to-gateway map
        $handlerMap = apply_filters('ucp_payment_handler_map', self::HANDLER_TO_GATEWAY_MAP);

        // Direct match from map
        if (isset($handlerMap[$handlerId]) && isset($gateways[$handlerMap[$handlerId]])) {
            return $gateways[$handlerMap[$handlerId]];
        }

        // Generic ucp_agent handler - return the first available gateway that supports tokenization
        if ($handlerId === 'ucp_agent') {
            foreach ($gateways as $gateway) {
                if ($this->gatewaySupportsTokenization($gateway)) {
                    return $gateway;
                }
            }
            // Fall back to first available gateway
            return reset($gateways) ?: null;
        }

        return $gateways[$handlerId] ?? null;
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
        $handlerId = $paymentData['handler_id'] ?? 'ucp_agent';
        $gateway = $this->mapToGateway($handlerId);

        if (!$gateway) {
            return [
                'success' => false,
                'message' => "No payment gateway available for handler: {$handlerId}",
            ];
        }

        // Initialize WC session, customer, and cart (required for REST API context)
        WooCommerceContextInitializer::initialize();

        // Set the payment method on the order
        $order->set_payment_method($gateway->id);
        $order->set_payment_method_title($gateway->get_title());

        // Store the token/credential for gateway processing
        $this->preparePaymentData($order, $gateway, $paymentData);

        try {
            // Process the payment through the gateway
            /** @var array{result?: string, redirect?: string}|null $result */
            $result = $gateway->process_payment($order->get_id());

            if ($result !== null && ($result['result'] ?? '') === 'success') {
                return [
                    'success' => true,
                    'message' => 'Payment processed successfully',
                    'redirect' => $result['redirect'] ?? null,
                ];
            }

            // Get error message from gateway
            $errorMessage = 'Payment processing failed';
            if (function_exists('wc_get_notices')) {
                $notices = wc_get_notices('error');
                if (!empty($notices)) {
                    $firstNotice = $notices[0];
                    $errorMessage = $firstNotice['notice'] ?? $errorMessage;
                    wc_clear_notices();
                }
            }

            return [
                'success' => false,
                'message' => $errorMessage,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Prepare payment data for the gateway.
     * Sets up session variables and order meta that gateways expect.
     *
     * @param WC_Order $order The order
     * @param WC_Payment_Gateway $gateway The gateway
     * @param array $paymentData UCP payment data
     */
    private function preparePaymentData(WC_Order $order, WC_Payment_Gateway $gateway, array $paymentData): void
    {
        $credential = $paymentData['credential'] ?? [];

        // Store token on order for gateway access
        if (!empty($credential['token'])) {
            $order->add_meta_data('_ucp_payment_token', $credential['token']);

            // For Stripe-like gateways, set up expected data
            if ($this->isStripeGateway($gateway)) {
                $this->prepareStripePayment($order, $credential);
            }
        }

        // Store card details if provided (for display purposes only, not actual card data)
        if (!empty($credential['card_last_four'])) {
            $order->add_meta_data('_ucp_card_last_four', $credential['card_last_four']);
        }

        if (!empty($credential['card_brand'])) {
            $order->add_meta_data('_ucp_card_brand', $credential['card_brand']);
        }

        $order->save();

        // Set up POST data that gateways might expect
        $_POST['payment_method'] = $gateway->id;

        // Allow custom data preparation via filter
        do_action('ucp_prepare_payment_data', $order, $gateway, $paymentData);
    }

    /**
     * Prepare Stripe-specific payment data.
     *
     * @param WC_Order $order The order
     * @param array $credential Payment credential
     */
    private function prepareStripePayment(WC_Order $order, array $credential): void
    {
        $token = $credential['token'] ?? '';

        // Store as Stripe expects
        $order->update_meta_data('_stripe_source_id', $token);
        $order->update_meta_data('_stripe_payment_method', $token);

        // Also set in POST for some Stripe versions
        $_POST['stripe_source'] = $token;
        $_POST['wc-stripe-payment-token'] = $token;
    }

    /**
     * Check if a gateway is Stripe-based.
     *
     * @param WC_Payment_Gateway $gateway The gateway
     * @return bool
     */
    private function isStripeGateway(WC_Payment_Gateway $gateway): bool
    {
        $stripeIds = ['stripe', 'stripe_cc', 'woocommerce_payments'];
        return in_array($gateway->id, $stripeIds, true)
            || str_contains(strtolower($gateway::class), 'stripe');
    }

    /**
     * Check if a gateway supports tokenization.
     *
     * @param WC_Payment_Gateway $gateway The gateway
     * @return bool
     */
    public function gatewaySupportsTokenization(WC_Payment_Gateway $gateway): bool
    {
        // Check for tokenization support via gateway's supports method
        if ($gateway->supports('tokenization')) {
            return true;
        }

        // Known gateways that support tokens
        $tokenGateways = ['stripe', 'stripe_cc', 'woocommerce_payments', 'ppcp-gateway', 'square_credit_card', 'braintree_credit_card'];
        return in_array($gateway->id, $tokenGateways, true);
    }

    /**
     * Get instrument types for a gateway.
     *
     * @param WC_Payment_Gateway $gateway The gateway
     * @return array<string>
     */
    public function getInstrumentTypes(WC_Payment_Gateway $gateway): array
    {
        // Determine instrument types based on gateway
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

        // Default to card if we couldn't determine
        if (empty($types)) {
            $types[] = 'card';
        }

        return $types;
    }

    /**
     * Build payment handlers for UCP manifest from available WC gateways.
     *
     * @return array UCP spec-compliant payment handlers
     */
    public function buildManifestHandlers(): array
    {
        if (!function_exists('WC')) {
            return $this->getDefaultHandler();
        }

        $paymentGateways = WC()->payment_gateways();
        if (!$paymentGateways) {
            return $this->getDefaultHandler();
        }

        $gateways = $paymentGateways->get_available_payment_gateways();

        if (empty($gateways)) {
            return $this->getDefaultHandler();
        }

        $handlers = [];
        $ucpVersion = PluginConfig::getInstance()->getUcpVersion();

        foreach ($gateways as $gateway) {
            $instrumentTypes = $this->getInstrumentTypes($gateway);

            $handlers[] = [
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

        // Add the generic ucp_agent handler that can route to any gateway
        $handlers[] = $this->getAgentHandler($gateways, $ucpVersion);

        return $handlers;
    }

    /**
     * Get the default UCP agent handler when no WC gateways available.
     *
     * @return array
     */
    private function getDefaultHandler(): array
    {
        $ucpVersion = PluginConfig::getInstance()->getUcpVersion();

        return [
            [
                'id' => 'ucp_agent',
                'name' => 'dev.ucp.payment.agent',
                'version' => $ucpVersion,
                'spec' => 'https://ucp.dev/specification/payment-handlers/agent',
                'config_schema' => 'https://ucp.dev/schemas/payment/agent-config.json',
                'instrument_schemas' => [
                    'https://ucp.dev/schemas/payment/card-instrument.json',
                ],
                'config' => [
                    'supported_networks' => ['visa', 'mastercard', 'amex', 'discover'],
                ],
            ],
        ];
    }

    /**
     * Get the UCP agent handler that routes to available gateways.
     *
     * @param array $gateways Available WC gateways
     * @param string $ucpVersion UCP version
     * @return array
     */
    private function getAgentHandler(array $gateways, string $ucpVersion): array
    {
        // Collect all supported types from all gateways
        $allTypes = [];
        foreach ($gateways as $gateway) {
            $allTypes = array_merge($allTypes, $this->getInstrumentTypes($gateway));
        }
        $allTypes = array_unique($allTypes);

        return [
            'id' => 'ucp_agent',
            'name' => 'dev.ucp.payment.agent',
            'version' => $ucpVersion,
            'spec' => 'https://ucp.dev/specification/payment-handlers/agent',
            'config_schema' => 'https://ucp.dev/schemas/payment/agent-config.json',
            'instrument_schemas' => $this->getInstrumentSchemas($allTypes),
            'config' => [
                'supported_networks' => ['visa', 'mastercard', 'amex', 'discover'],
                'available_gateways' => array_keys($gateways),
            ],
        ];
    }

    /**
     * Normalize gateway name for UCP handler naming.
     *
     * @param string $gatewayId WC gateway ID
     * @return string Normalized name
     */
    private function normalizeGatewayName(string $gatewayId): string
    {
        // Remove common prefixes/suffixes and normalize
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
