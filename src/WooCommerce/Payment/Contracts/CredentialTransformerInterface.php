<?php

namespace UcpCheckout\WooCommerce\Payment\Contracts;

use WC_Order;
use WC_Payment_Gateway;

/**
 * Contract for transforming UCP credentials into gateway-specific format.
 *
 * Some payment handlers may need to transform the UCP credential
 * (token, payment method ID, etc.) into a format expected by their gateway.
 */
interface CredentialTransformerInterface
{
    /**
     * Transform UCP credential data into gateway-specific format.
     *
     * @param array $credential UCP credential data (token, card_last_four, card_brand, etc.)
     * @param WC_Order $order The WooCommerce order
     * @param WC_Payment_Gateway $gateway The payment gateway
     * @return array Transformed credential data for the gateway
     */
    public function transform(array $credential, WC_Order $order, WC_Payment_Gateway $gateway): array;
}
