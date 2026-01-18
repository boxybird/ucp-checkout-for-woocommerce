<?php

namespace UcpCheckout\WooCommerce\Payment\Traits;

use WC_Order;

/**
 * Provides common order meta operations for payment handlers.
 */
trait HandlesOrderMeta
{
    /**
     * Store UCP payment token on the order.
     *
     * @param WC_Order $order The order
     * @param string $token The payment token
     */
    protected function storePaymentToken(WC_Order $order, string $token): void
    {
        $order->update_meta_data('_ucp_payment_token', $token);
    }

    /**
     * Store card display information on the order.
     *
     * @param WC_Order $order The order
     * @param array $credential Credential array with optional card_last_four and card_brand
     */
    protected function storeCardDisplayInfo(WC_Order $order, array $credential): void
    {
        if (!empty($credential['card_last_four'])) {
            $order->update_meta_data('_ucp_card_last_four', $credential['card_last_four']);
        }

        if (!empty($credential['card_brand'])) {
            $order->update_meta_data('_ucp_card_brand', $credential['card_brand']);
        }
    }

    /**
     * Set the payment method on an order.
     *
     * @param WC_Order $order The order
     * @param \WC_Payment_Gateway $gateway The payment gateway
     */
    protected function setPaymentMethod(WC_Order $order, \WC_Payment_Gateway $gateway): void
    {
        $order->set_payment_method($gateway->id);
        $order->set_payment_method_title($gateway->get_title());
    }

    /**
     * Store arbitrary meta data on the order.
     *
     * @param WC_Order $order The order
     * @param string $key Meta key (will be prefixed with underscore if not already)
     * @param mixed $value Meta value
     */
    protected function storeMeta(WC_Order $order, string $key, mixed $value): void
    {
        $key = str_starts_with($key, '_') ? $key : '_' . $key;
        $order->update_meta_data($key, $value);
    }

    /**
     * Get meta data from the order.
     *
     * @param WC_Order $order The order
     * @param string $key Meta key
     * @return mixed
     */
    protected function getMeta(WC_Order $order, string $key): mixed
    {
        $key = str_starts_with($key, '_') ? $key : '_' . $key;
        return $order->get_meta($key, true);
    }

    /**
     * Save the order with all pending meta changes.
     *
     * @param WC_Order $order The order
     */
    protected function saveOrder(WC_Order $order): void
    {
        $order->save();
    }
}
