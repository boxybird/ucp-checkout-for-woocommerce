<?php

namespace UcpCheckout\WooCommerce\Payment\Contracts;

use WC_Payment_Gateway;

/**
 * Contract for handlers that contribute to the UCP manifest.
 *
 * Handlers implementing this interface can provide custom manifest
 * entries for the gateways they support.
 */
interface ManifestContributorInterface
{
    /**
     * Build a UCP manifest handler entry for the given gateway.
     *
     * @param WC_Payment_Gateway $gateway The WooCommerce payment gateway
     * @param string $ucpVersion The UCP version string
     * @return array UCP spec-compliant payment handler entry
     */
    public function buildManifestEntry(WC_Payment_Gateway $gateway, string $ucpVersion): array;

    /**
     * Get the instrument types supported by this handler.
     *
     * @param WC_Payment_Gateway $gateway The WooCommerce payment gateway
     * @return array<string> Instrument types (e.g., 'card', 'paypal', 'bank_account')
     */
    public function getInstrumentTypes(WC_Payment_Gateway $gateway): array;
}
