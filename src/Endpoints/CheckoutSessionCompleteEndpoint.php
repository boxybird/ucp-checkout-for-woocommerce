<?php

namespace UcpCheckout\Endpoints;

use UcpCheckout\Checkout\CheckoutSession;
use UcpCheckout\Checkout\CheckoutSessionRepository;
use UcpCheckout\Config\PluginConfig;
use UcpCheckout\Http\ErrorHandler;
use WP_REST_Request;
use WP_REST_Response;

class CheckoutSessionCompleteEndpoint extends AbstractEndpoint
{
    public function __construct(
        ?PluginConfig $config = null,
        private readonly ?CheckoutSessionRepository $repository = new CheckoutSessionRepository()
    ) {
        parent::__construct($config);
    }

    public function getRoute(): string
    {
        return '/checkout-sessions/(?P<id>[a-zA-Z0-9_-]+)/complete';
    }

    public function getMethods(): string
    {
        return 'POST';
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $sessionId = $request->get_param('id');
        $params = $request->get_json_params();

        if (empty($sessionId)) {
            return $this->validationError([
                'id' => 'Session ID is required',
            ]);
        }

        $session = $this->repository->find($sessionId);

        if (!$session) {
            return $this->notFound('checkout_session', $sessionId);
        }

        // Check if session can be completed
        if (!$session->canComplete()) {
            if ($session->isExpired()) {
                return ErrorHandler::createError(
                    'session_expired',
                    'session_expired',
                    'This checkout session has expired',
                    'error',
                    400
                );
            }

            return ErrorHandler::createError(
                'invalid_session_status',
                'invalid_status',
                "Session cannot be completed. Current status: {$session->getStatus()}",
                'error',
                400
            );
        }

        // Validate completion data
        $errors = $this->validateCompletionData($params, $session);
        if (!empty($errors)) {
            return $this->validationError($errors);
        }

        // Update session with shipping and payment info
        if (!empty($params['shipping'])) {
            $session->setShippingAddress($params['shipping']);
        }

        if (!empty($params['shipping_method'])) {
            $session->setSelectedShippingMethod($params['shipping_method']);
        }

        if (!empty($params['payment_token'])) {
            $session->setPaymentInfo([
                'token' => $params['payment_token'],
                'method' => $params['payment_method'] ?? 'ucp_agent',
            ]);
        }

        // Mark as processing
        $session->markProcessing();
        $this->repository->save($session);

        try {
            $order = $this->createOrder($session, $params);

            // Mark as completed
            $session->markCompleted($order->get_id());
            $this->repository->save($session);

            return $this->success([
                'session_id' => $session->getId(),
                'status' => $session->getStatus(),
                'order_id' => $order->get_id(),
                'order_status' => $order->get_status(),
                'total' => $order->get_total(),
                'currency' => $order->get_currency(),
            ]);
        } catch (\Exception $e) {
            // Revert to pending on failure
            $session = CheckoutSession::fromArray(array_merge(
                $session->toArray(),
                ['status' => CheckoutSession::STATUS_PENDING]
            ));
            $this->repository->save($session);

            return ErrorHandler::fromException($e);
        }
    }

    /**
     * Validate data required to complete the checkout.
     */
    private function validateCompletionData(array $params, CheckoutSession $session): array
    {
        $errors = [];

        // Payment token is required
        if (empty($params['payment_token'])) {
            $errors['payment_token'] = 'Payment token is required';
        }

        // Shipping address required if not already set
        if (!$session->getShippingAddress() && empty($params['shipping'])) {
            $errors['shipping'] = 'Shipping address is required';
        } elseif (!empty($params['shipping'])) {
            $requiredFields = ['first_name', 'last_name', 'address', 'city', 'zip', 'country'];
            foreach ($requiredFields as $field) {
                if (empty($params['shipping'][$field])) {
                    $errors["shipping.{$field}"] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
                }
            }
        }

        return $errors;
    }

    /**
     * Create WooCommerce order from session.
     */
    private function createOrder(CheckoutSession $session, array $params): \WC_Order
    {
        $order = wc_create_order();

        // Add items
        foreach ($session->getItems() as $item) {
            $product = wc_get_product($item['product_id']);
            if ($product) {
                $order->add_product($product, $item['quantity']);
            }
        }

        // Set shipping address
        $shipping = $params['shipping'] ?? $session->getShippingAddress();
        if ($shipping) {
            $order->set_address([
                'first_name' => $shipping['first_name'] ?? '',
                'last_name' => $shipping['last_name'] ?? '',
                'address_1' => $shipping['address'] ?? '',
                'address_2' => $shipping['address_2'] ?? '',
                'city' => $shipping['city'] ?? '',
                'state' => $shipping['state'] ?? '',
                'postcode' => $shipping['zip'] ?? '',
                'country' => $shipping['country'] ?? '',
                'email' => $shipping['email'] ?? '',
                'phone' => $shipping['phone'] ?? '',
            ], 'shipping');

            // Also set as billing address
            $order->set_address([
                'first_name' => $shipping['first_name'] ?? '',
                'last_name' => $shipping['last_name'] ?? '',
                'address_1' => $shipping['address'] ?? '',
                'address_2' => $shipping['address_2'] ?? '',
                'city' => $shipping['city'] ?? '',
                'state' => $shipping['state'] ?? '',
                'postcode' => $shipping['zip'] ?? '',
                'country' => $shipping['country'] ?? '',
                'email' => $shipping['email'] ?? '',
                'phone' => $shipping['phone'] ?? '',
            ], 'billing');
        }

        // Set payment method
        $order->set_payment_method($params['payment_method'] ?? 'ucp_agent');
        $order->set_transaction_id($params['payment_token']);

        // Add metadata
        $order->add_meta_data('_ucp_session_id', $session->getId());
        $order->add_meta_data('_ucp_agent_checkout', '1');

        // Calculate totals and complete payment
        $order->calculate_totals();
        $order->payment_complete();

        return $order;
    }
}
