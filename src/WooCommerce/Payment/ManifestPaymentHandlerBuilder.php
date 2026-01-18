<?php

namespace UcpCheckout\WooCommerce\Payment;

use UcpCheckout\Config\PluginConfig;
use UcpCheckout\WooCommerce\Payment\Contracts\ManifestContributorInterface;

/**
 * Builds UCP manifest payment handlers from the handler registry.
 */
class ManifestPaymentHandlerBuilder
{
    public function __construct(
        private readonly PaymentHandlerRegistry $registry,
        private readonly PaymentHandlerFactory $factory,
        private readonly GatewayResolver $resolver
    ) {
    }

    /**
     * Build payment handlers for the UCP manifest.
     *
     * @return array UCP spec-compliant payment handlers
     */
    public function build(): array
    {
        $gateways = $this->resolver->getAvailableGateways();

        if (empty($gateways)) {
            return $this->getDefaultHandler();
        }

        $handlers = [];
        $ucpVersion = PluginConfig::getInstance()->getUcpVersion();

        foreach ($gateways as $gateway) {
            $handler = $this->factory->getHandler($gateway);

            if ($handler instanceof ManifestContributorInterface) {
                $handlers[] = $handler->buildManifestEntry($gateway, $ucpVersion);
            } else {
                // Use generic manifest entry for handlers without manifest contribution
                $handlers[] = $this->buildGenericManifestEntry($gateway, $ucpVersion);
            }
        }

        // Add the generic ucp_agent handler
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
     * Build a generic manifest entry for gateways without custom manifest contribution.
     *
     * @param \WC_Payment_Gateway $gateway The gateway
     * @param string $ucpVersion UCP version
     * @return array
     */
    private function buildGenericManifestEntry(\WC_Payment_Gateway $gateway, string $ucpVersion): array
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
            $handler = $this->factory->getHandler($gateway);
            if ($handler instanceof ManifestContributorInterface) {
                $allTypes = array_merge($allTypes, $handler->getInstrumentTypes($gateway));
            } else {
                $allTypes = array_merge($allTypes, $this->getInstrumentTypes($gateway));
            }
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
     * Get instrument types for a gateway (fallback logic).
     *
     * @param \WC_Payment_Gateway $gateway The gateway
     * @return array<string>
     */
    private function getInstrumentTypes(\WC_Payment_Gateway $gateway): array
    {
        $types = [];
        $gatewayId = strtolower($gateway->id);

        if (str_contains($gatewayId, 'stripe')
            || str_contains($gatewayId, 'square')
            || str_contains($gatewayId, 'braintree')
            || str_contains($gatewayId, 'card')
            || str_contains($gatewayId, 'credit')) {
            $types[] = 'card';
        }

        if (str_contains($gatewayId, 'paypal') || str_contains($gatewayId, 'ppcp')) {
            $types[] = 'paypal';
        }

        if (str_contains($gatewayId, 'bank') || str_contains($gatewayId, 'ach')) {
            $types[] = 'bank_account';
        }

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
