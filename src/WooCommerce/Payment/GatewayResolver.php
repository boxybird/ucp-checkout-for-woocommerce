<?php

namespace UcpCheckout\WooCommerce\Payment;

use WC_Payment_Gateway;

/**
 * Resolves UCP handler IDs to WooCommerce payment gateways.
 */
class GatewayResolver
{
    /**
     * Mapping of UCP handler IDs to WooCommerce gateway IDs.
     */
    private const array HANDLER_TO_GATEWAY_MAP = [
        'ucp_stripe' => 'stripe',
        'ucp_paypal' => 'ppcp-gateway',
        'ucp_square' => 'square_credit_card',
        'ucp_braintree' => 'braintree_credit_card',
    ];

    public function __construct(
        private readonly PaymentHandlerFactory $handlerFactory
    ) {
    }

    /**
     * Resolve a UCP payment handler ID to a WooCommerce gateway.
     *
     * @param string $handlerId UCP payment handler ID
     * @return WC_Payment_Gateway|null
     */
    public function resolve(string $handlerId): ?WC_Payment_Gateway
    {
        if (!function_exists('WC')) {
            return null;
        }

        $paymentGateways = WC()->payment_gateways();
        if (!$paymentGateways) {
            return null;
        }

        $gateways = $paymentGateways->get_available_payment_gateways();

        if (empty($gateways)) {
            return null;
        }

        // Allow filtering of the handler-to-gateway map
        $handlerMap = apply_filters('ucp_payment_handler_map', self::HANDLER_TO_GATEWAY_MAP);

        // Direct match from map
        if (isset($handlerMap[$handlerId]) && isset($gateways[$handlerMap[$handlerId]])) {
            return $gateways[$handlerMap[$handlerId]];
        }

        // Generic ucp_agent handler - return the best available gateway
        if ($handlerId === 'ucp_agent') {
            return $this->resolveAgentHandler($gateways);
        }

        // Try direct gateway ID match
        return $gateways[$handlerId] ?? null;
    }

    /**
     * Resolve the ucp_agent handler to the best available gateway.
     *
     * Prioritizes gateways with dedicated handlers and tokenization support.
     *
     * @param array<string, WC_Payment_Gateway> $gateways Available gateways
     * @return WC_Payment_Gateway|null
     */
    private function resolveAgentHandler(array $gateways): ?WC_Payment_Gateway
    {
        // First, try to find a gateway with a dedicated handler that supports tokenization
        foreach ($gateways as $gateway) {
            if ($this->handlerFactory->hasHandler($gateway) && $this->gatewaySupportsTokenization($gateway)) {
                return $gateway;
            }
        }

        // Fall back to any gateway with tokenization support
        foreach ($gateways as $gateway) {
            if ($this->gatewaySupportsTokenization($gateway)) {
                return $gateway;
            }
        }

        // Fall back to first available gateway
        return reset($gateways) ?: null;
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
        $tokenGateways = [
            'stripe',
            'stripe_cc',
            'woocommerce_payments',
            'ppcp-gateway',
            'square_credit_card',
            'braintree_credit_card',
        ];

        return in_array($gateway->id, $tokenGateways, true);
    }

    /**
     * Get all available payment gateways.
     *
     * @return array<string, WC_Payment_Gateway>
     */
    public function getAvailableGateways(): array
    {
        if (!function_exists('WC')) {
            return [];
        }

        $paymentGateways = WC()->payment_gateways();
        if (!$paymentGateways) {
            return [];
        }

        return $paymentGateways->get_available_payment_gateways();
    }
}
