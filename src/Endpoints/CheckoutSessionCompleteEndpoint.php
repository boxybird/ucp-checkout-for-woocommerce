<?php

namespace UcpCheckout\Endpoints;

use UcpCheckout\Checkout\CheckoutSession;
use UcpCheckout\Checkout\CheckoutSessionRepository;
use UcpCheckout\Config\PluginConfig;
use UcpCheckout\Http\ErrorHandler;
use UcpCheckout\WooCommerce\WooCommerceService;
use WP_REST_Request;
use WP_REST_Response;

class CheckoutSessionCompleteEndpoint extends AbstractEndpoint
{
    public function __construct(
        ?PluginConfig $config = null,
        private readonly ?CheckoutSessionRepository $repository = new CheckoutSessionRepository(),
        private readonly ?WooCommerceService $wcService = new WooCommerceService()
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
                    ErrorHandler::SEVERITY_RECOVERABLE,
                    400
                );
            }

            return ErrorHandler::createError(
                'invalid_session_status',
                'invalid_status',
                "Session cannot be completed. Current status: {$session->getStatus()}",
                ErrorHandler::SEVERITY_RECOVERABLE,
                400
            );
        }

        // Validate completion data per UCP spec
        $errors = $this->validateCompletionData($params, $session);
        if (!empty($errors)) {
            return $this->validationError($errors);
        }

        // Verify stock is still available before completing
        $stockErrors = $this->wcService->verifyStock($session->getLineItems());
        if (!empty($stockErrors)) {
            return $this->validationError($stockErrors);
        }

        // Update session with shipping and payment data
        if (!empty($params['buyer']['shipping_address'])) {
            $session->setShippingAddress($params['buyer']['shipping_address']);
        }

        if (!empty($params['fulfillment']['shipping_method'])) {
            $session->setSelectedShippingMethod($params['fulfillment']['shipping_method']);
        }

        if (!empty($params['payment_data'])) {
            $session->setPaymentData($params['payment_data']);
        }

        // Mark as complete_in_progress
        $session->markCompleteInProgress();
        $this->repository->save($session);

        try {
            $order = $this->createOrder($session, $params);

            // Mark as completed
            $session->markCompleted($order->get_id());
            $this->repository->save($session);

            return $this->success($session->toApiResponse());
        } catch (\Exception $e) {
            // Revert to incomplete on failure
            $session = CheckoutSession::fromArray(array_merge(
                $session->toArray(),
                ['status' => CheckoutSession::STATUS_INCOMPLETE]
            ));
            $this->repository->save($session);

            return ErrorHandler::fromException($e);
        }
    }

    /**
     * Validate data required to complete the checkout per UCP spec.
     */
    private function validateCompletionData(array $params, CheckoutSession $session): array
    {
        $errors = [];

        // Payment data is required per UCP spec
        if (empty($params['payment_data'])) {
            $errors['payment_data'] = 'Payment data is required';
        } else {
            // Validate payment data has required fields
            if (empty($params['payment_data']['handler_id'])) {
                $errors['payment_data.handler_id'] = 'Payment handler ID is required';
            }
            if (empty($params['payment_data']['credential'])) {
                $errors['payment_data.credential'] = 'Payment credential is required';
            }
        }

        // Shipping address required if not already set
        $shippingAddress = $params['buyer']['shipping_address'] ?? $session->getShippingAddress();
        if (empty($shippingAddress)) {
            $errors['buyer.shipping_address'] = 'Shipping address is required';
        }

        return $errors;
    }

    /**
     * Create WooCommerce order from session.
     */
    private function createOrder(CheckoutSession $session, array $params): \WC_Order
    {
        $order = wc_create_order();

        // Add items from line_items (UCP spec format)
        foreach ($session->getLineItems() as $lineItem) {
            $productId = (int) $lineItem['item']['id'];
            $product = wc_get_product($productId);
            if ($product) {
                $order->add_product($product, $lineItem['quantity']);
            }
        }

        // Set shipping address
        $shipping = $params['buyer']['shipping_address'] ?? $session->getShippingAddress();
        if ($shipping) {
            $addressData = [
                'first_name' => $shipping['first_name'] ?? '',
                'last_name' => $shipping['last_name'] ?? '',
                'address_1' => $shipping['street_address'] ?? $shipping['address'] ?? '',
                'address_2' => $shipping['extended_address'] ?? $shipping['address_2'] ?? '',
                'city' => $shipping['address_locality'] ?? $shipping['city'] ?? '',
                'state' => $shipping['address_region'] ?? $shipping['state'] ?? '',
                'postcode' => $shipping['postal_code'] ?? $shipping['zip'] ?? '',
                'country' => $shipping['address_country'] ?? $shipping['country'] ?? '',
                'email' => $shipping['email'] ?? '',
                'phone' => $shipping['phone'] ?? '',
            ];
            $order->set_address($addressData, 'shipping');
            $order->set_address($addressData, 'billing');
        }

        // Add UCP metadata before payment processing
        $order->add_meta_data('_ucp_session_id', $session->getId());
        $order->add_meta_data('_ucp_agent_checkout', '1');

        // Calculate totals before payment
        $order->calculate_totals();
        $order->save();

        // Process payment through WooCommerce gateway
        $paymentData = $params['payment_data'] ?? [];
        $paymentResult = $this->wcService->processPayment($order, $paymentData);

        if (!$paymentResult['success']) {
            // Payment failed - cancel the order and throw exception
            $order->update_status('failed', $paymentResult['message']);
            throw new \Exception($paymentResult['message'], 400);
        }

        // Reduce stock levels after successful payment
        $this->wcService->reduceStock($order);

        // Fire UCP-specific action for integrations
        do_action('ucp_checkout_order_created', $order, $session);

        return $order;
    }
}
