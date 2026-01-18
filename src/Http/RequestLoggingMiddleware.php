<?php

declare(strict_types=1);

namespace UcpCheckout\Http;

use UcpCheckout\Logging\UcpRequestLogger;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Middleware that hooks into the WordPress REST API lifecycle
 * to log UCP API requests and responses.
 */
class RequestLoggingMiddleware
{
    private const string UCP_NAMESPACE = 'ucp/v1';

    /**
     * Tracks request context for correlation.
     *
     * @var array<int, array{request_id: string, start_time: float, endpoint: string, method: string, session_id: ?string}>
     */
    private array $requestContext = [];

    public function __construct(private readonly UcpRequestLogger $logger)
    {
    }

    /**
     * Register the middleware hooks.
     */
    public function register(): void
    {
        // Hook before request processing
        add_filter('rest_request_before_callbacks', $this->beforeRequest(...), 10, 3);

        // Hook after response is ready
        add_filter('rest_post_dispatch', $this->afterResponse(...), 10, 3);

        // Schedule cleanup cron
        add_action('ucp_logs_cleanup', $this->runCleanup(...));

        if (!wp_next_scheduled('ucp_logs_cleanup')) {
            wp_schedule_event(time(), 'daily', 'ucp_logs_cleanup');
        }
    }

    /**
     * Unregister the middleware hooks.
     */
    public function unregister(): void
    {
        remove_filter('rest_request_before_callbacks', $this->beforeRequest(...));
        remove_filter('rest_post_dispatch', $this->afterResponse(...));

        $timestamp = wp_next_scheduled('ucp_logs_cleanup');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'ucp_logs_cleanup');
        }
    }

    /**
     * Called before request callbacks are executed.
     *
     * @param WP_REST_Response|null|\WP_Error $response
     * @param array $handler
     * @param WP_REST_Request $request
     * @return WP_REST_Response|null|\WP_Error
     */
    public function beforeRequest($response, array $handler, WP_REST_Request $request)
    {
        // Only log UCP endpoints
        if (!$this->isUcpRequest($request)) {
            return $response;
        }

        $endpoint = $this->extractEndpoint($request);
        $requestId = $this->logger->logRequest($request, $endpoint);

        // Store context for response logging
        $contextKey = spl_object_id($request);
        $this->requestContext[$contextKey] = [
            'request_id' => $requestId,
            'start_time' => microtime(true),
            'endpoint' => $endpoint,
            'method' => $request->get_method(),
            'session_id' => $this->extractSessionId($request),
        ];

        return $response;
    }

    /**
     * Called after the response is dispatched.
     *
     * @param WP_REST_Response $response
     * @param WP_REST_Server $server
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function afterResponse(WP_REST_Response $response, WP_REST_Server $server, WP_REST_Request $request): WP_REST_Response
    {
        // Only log UCP endpoints
        if (!$this->isUcpRequest($request)) {
            return $response;
        }

        $contextKey = spl_object_id($request);

        if (!isset($this->requestContext[$contextKey])) {
            // Request wasn't logged (shouldn't happen, but handle gracefully)
            return $response;
        }

        $context = $this->requestContext[$contextKey];
        $durationMs = (microtime(true) - $context['start_time']) * 1000;

        // Try to get session ID from response if not in request
        $sessionId = $context['session_id'];
        if ($sessionId === null) {
            $data = $response->get_data();
            if (is_array($data) && isset($data['id'])) {
                $sessionId = $data['id'];
            }
        }

        $this->logger->logResponse(
            requestId: $context['request_id'],
            response: $response,
            durationMs: $durationMs,
            endpoint: $context['endpoint'],
            method: $context['method'],
            sessionId: $sessionId,
        );

        // Clean up context
        unset($this->requestContext[$contextKey]);

        return $response;
    }

    /**
     * Run the log cleanup task.
     */
    public function runCleanup(): void
    {
        $deleted = $this->logger->cleanup();
        if ($deleted > 0) {
            error_log("[UCP Logger] Cleaned up {$deleted} old log entries");
        }
    }

    /**
     * Check if this is a UCP API request.
     */
    private function isUcpRequest(WP_REST_Request $request): bool
    {
        $route = $request->get_route();
        return str_starts_with($route, '/' . self::UCP_NAMESPACE);
    }

    /**
     * Extract the endpoint path from the request.
     */
    private function extractEndpoint(WP_REST_Request $request): string
    {
        $route = $request->get_route();
        // Remove the namespace prefix
        return preg_replace('#^/' . preg_quote(self::UCP_NAMESPACE, '#') . '#', '', $route) ?: '/';
    }

    /**
     * Extract session ID from request.
     */
    private function extractSessionId(WP_REST_Request $request): ?string
    {
        // Check URL parameters (for routes like /checkout-sessions/{id})
        $params = $request->get_url_params();
        if (!empty($params['id'])) {
            return $params['id'];
        }

        return null;
    }

    /**
     * Get the logger instance.
     */
    public function getLogger(): UcpRequestLogger
    {
        return $this->logger;
    }
}
