<?php

namespace UcpCheckout\Endpoints;

use UcpCheckout\Checkout\CheckoutSessionRepository;
use UcpCheckout\Config\PluginConfig;
use WP_REST_Request;
use WP_REST_Response;

class CheckoutSessionGetEndpoint extends AbstractEndpoint
{
    private CheckoutSessionRepository $repository;

    public function __construct(
        ?PluginConfig $config = null,
        ?CheckoutSessionRepository $repository = null
    ) {
        parent::__construct($config);
        $this->repository = $repository ?? new CheckoutSessionRepository();
    }

    public function getRoute(): string
    {
        return '/checkout-sessions/(?P<id>[a-zA-Z0-9_-]+)';
    }

    public function getMethods(): string
    {
        return 'GET';
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $sessionId = $request->get_param('id');

        if (empty($sessionId)) {
            return $this->validationError([
                'id' => 'Session ID is required',
            ]);
        }

        $session = $this->repository->find($sessionId);

        if (!$session) {
            return $this->notFound('checkout_session', $sessionId);
        }

        return $this->success($session->toApiResponse());
    }
}
