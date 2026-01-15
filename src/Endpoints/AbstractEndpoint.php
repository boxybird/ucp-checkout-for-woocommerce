<?php

namespace UcpPlugin\Endpoints;

use UcpPlugin\Config\PluginConfig;
use UcpPlugin\Http\ErrorHandler;
use UcpPlugin\Http\UcpResponse;
use WP_REST_Request;
use WP_REST_Response;

abstract class AbstractEndpoint
{
    protected PluginConfig $config;

    public function __construct(?PluginConfig $config = null)
    {
        $this->config = $config ?? PluginConfig::getInstance();
    }

    /**
     * Get the endpoint route (without namespace).
     */
    abstract public function getRoute(): string;

    /**
     * Get the HTTP methods for this endpoint.
     */
    abstract public function getMethods(): string|array;

    /**
     * Handle the request.
     */
    abstract public function handle(WP_REST_Request $request): WP_REST_Response;

    /**
     * Register this endpoint with WordPress REST API.
     */
    public function register(): void
    {
        register_rest_route(
            $this->config->getApiNamespace(),
            $this->getRoute(),
            [
                'methods' => $this->getMethods(),
                'callback' => [$this, 'handle'],
                'permission_callback' => [$this, 'permissionCallback'],
            ]
        );
    }

    /**
     * Permission callback for this endpoint.
     * Can be overridden in child classes for custom permission logic.
     */
    public function permissionCallback(WP_REST_Request $request): bool
    {
        return $this->verifyAgentRequest($request);
    }

    /**
     * Verify the agent request.
     */
    protected function verifyAgentRequest(WP_REST_Request $request): bool
    {
        if ($this->config->isHttpsRequired()) {
            $isHttps = is_ssl() ||
                (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
            $isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);

            if (!$isHttps && !$isLocalhost) {
                return false;
            }
        }

        $agentHeader = $request->get_header('UCP-Agent');
        if (empty($agentHeader)) {
            if (function_exists('error_log')) {
                error_log('UCP: Request without UCP-Agent header from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            }
        }

        return true;
    }

    /**
     * Create a success response with UCP envelope.
     */
    protected function success(array $data, int $statusCode = 200): WP_REST_Response
    {
        return UcpResponse::success($data, [], $statusCode);
    }

    /**
     * Create a not found error response.
     */
    protected function notFound(string $resource, string $identifier = ''): WP_REST_Response
    {
        return ErrorHandler::notFound($resource, $identifier);
    }

    /**
     * Create a validation error response.
     */
    protected function validationError(array $errors): WP_REST_Response
    {
        return ErrorHandler::validationError($errors);
    }

    /**
     * Validate required fields in data array.
     *
     * @param array $data The data to validate
     * @param array $required Array of required field names
     * @return array Array of validation errors (empty if valid)
     */
    protected function validateRequired(array $data, array $required): array
    {
        $errors = [];

        foreach ($required as $field) {
            if (str_contains($field, '.')) {
                // Handle nested fields like "shipping.first_name"
                $parts = explode('.', $field);
                $value = $data;
                foreach ($parts as $part) {
                    $value = $value[$part] ?? null;
                }
                if (empty($value)) {
                    $errors[$field] = ucfirst(str_replace(['_', '.'], ' ', $field)) . ' is required';
                }
            } elseif (empty($data[$field])) {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }

        return $errors;
    }
}
