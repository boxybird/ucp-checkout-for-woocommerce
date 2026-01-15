<?php

namespace UcpCheckout\Endpoints;

use UcpCheckout\Checkout\CheckoutSessionRepository;
use UcpCheckout\Config\PluginConfig;
use UcpCheckout\Http\ErrorHandler;
use WP_REST_Request;
use WP_REST_Response;

class CheckoutSessionCancelEndpoint extends AbstractEndpoint
{
    public function __construct(
        ?PluginConfig $config = null,
        private readonly ?CheckoutSessionRepository $repository = new CheckoutSessionRepository()
    ) {
        parent::__construct($config);
    }

    public function getRoute(): string
    {
        return '/checkout-sessions/(?P<id>[a-zA-Z0-9_-]+)/cancel';
    }

    public function getMethods(): string
    {
        return 'POST';
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

        // Check if session can be canceled
        if (!$session->canCancel()) {
            return ErrorHandler::createError(
                'invalid_session_status',
                'invalid_status',
                "Session cannot be canceled. Current status: {$session->getStatus()}",
                ErrorHandler::SEVERITY_ERROR,
                400
            );
        }

        // Cancel the session
        $session->markCanceled();
        $this->repository->save($session);

        return $this->success($session->toApiResponse());
    }
}
