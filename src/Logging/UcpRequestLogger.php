<?php

declare(strict_types=1);

namespace UcpCheckout\Logging;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Core logging service for UCP API requests.
 */
class UcpRequestLogger
{
    public const OPTION_DEBUG_MODE = 'ucp_debug_mode';
    public const OPTION_RETENTION_DAYS = 'ucp_log_retention_days';

    /**
     * Sensitive fields to redact from logged data.
     */
    private const SENSITIVE_FIELDS = [
        'token',
        'credential',
        'card_number',
        'cvv',
        'cvc',
        'password',
        'secret',
        'api_key',
        'private_key',
    ];

    private LogRepository $repository;

    public function __construct(LogRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Generate a unique request ID.
     */
    public function generateRequestId(): string
    {
        return wp_generate_uuid4();
    }

    /**
     * Log an incoming request.
     */
    public function logRequest(WP_REST_Request $request, string $endpoint): string
    {
        $requestId = $this->generateRequestId();

        $data = [];
        if ($this->isDebugMode()) {
            $data = [
                'headers' => $this->sanitizeHeaders($request->get_headers()),
                'body' => $this->sanitizeData($request->get_body_params()),
                'query' => $this->sanitizeData($request->get_query_params()),
            ];
        }

        $entry = LogEntry::forRequest(
            requestId: $requestId,
            endpoint: $endpoint,
            method: $request->get_method(),
            agent: $this->extractAgent($request),
            ipAddress: $this->getClientIp(),
            data: $data,
            sessionId: $this->extractSessionId($request),
        );

        try {
            $this->repository->insert($entry);
        } catch (\Throwable $e) {
            error_log('[UCP Logger] Failed to log request: ' . $e->getMessage());
        }

        return $requestId;
    }

    /**
     * Log an outgoing response.
     */
    public function logResponse(
        string $requestId,
        WP_REST_Response $response,
        float $durationMs,
        string $endpoint,
        string $method,
        ?string $sessionId = null,
    ): void {
        $data = [];
        if ($this->isDebugMode()) {
            $responseData = $response->get_data();
            $data = [
                'body' => $this->sanitizeData(is_array($responseData) ? $responseData : ['raw' => $responseData]),
                'headers' => $response->get_headers(),
            ];
        }

        // Extract session ID from response if not provided
        if ($sessionId === null) {
            $responseData = $response->get_data();
            if (is_array($responseData) && isset($responseData['id'])) {
                $sessionId = $responseData['id'];
            }
        }

        $entry = LogEntry::forResponse(
            requestId: $requestId,
            endpoint: $endpoint,
            method: $method,
            statusCode: $response->get_status(),
            durationMs: (int) $durationMs,
            data: $data,
            sessionId: $sessionId,
        );

        try {
            $this->repository->insert($entry);
        } catch (\Throwable $e) {
            error_log('[UCP Logger] Failed to log response: ' . $e->getMessage());
        }
    }

    /**
     * Log a session event (status transition, field update, etc.).
     */
    public function logSessionEvent(
        string $sessionId,
        string $event,
        array $context = [],
        ?string $requestId = null,
    ): void {
        $entry = LogEntry::forSession(
            requestId: $requestId ?? $this->generateRequestId(),
            sessionId: $sessionId,
            event: $event,
            context: $this->sanitizeData($context),
        );

        try {
            $this->repository->insert($entry);
        } catch (\Throwable $e) {
            error_log('[UCP Logger] Failed to log session event: ' . $e->getMessage());
        }
    }

    /**
     * Log a payment event.
     */
    public function logPaymentEvent(
        string $sessionId,
        string $handler,
        bool $success,
        ?string $error = null,
        array $context = [],
        ?string $requestId = null,
    ): void {
        $entry = LogEntry::forPayment(
            requestId: $requestId ?? $this->generateRequestId(),
            sessionId: $sessionId,
            handler: $handler,
            success: $success,
            error: $error,
            context: $this->sanitizeData($context),
        );

        try {
            $this->repository->insert($entry);
        } catch (\Throwable $e) {
            error_log('[UCP Logger] Failed to log payment event: ' . $e->getMessage());
        }
    }

    /**
     * Log an error.
     */
    public function logError(
        \Throwable $exception,
        ?string $endpoint = null,
        ?string $sessionId = null,
        ?string $requestId = null,
    ): void {
        $entry = LogEntry::forError(
            requestId: $requestId ?? $this->generateRequestId(),
            exception: $exception,
            endpoint: $endpoint,
            sessionId: $sessionId,
            includeTrace: $this->isDebugMode(),
        );

        try {
            $this->repository->insert($entry);
        } catch (\Throwable $e) {
            error_log('[UCP Logger] Failed to log error: ' . $e->getMessage());
        }
    }

    /**
     * Check if debug mode is enabled.
     */
    public function isDebugMode(): bool
    {
        return (bool) get_option(self::OPTION_DEBUG_MODE, false);
    }

    /**
     * Enable or disable debug mode.
     */
    public function setDebugMode(bool $enabled): void
    {
        update_option(self::OPTION_DEBUG_MODE, $enabled);
    }

    /**
     * Get the log retention period in days.
     */
    public function getRetentionDays(): int
    {
        return (int) get_option(self::OPTION_RETENTION_DAYS, LogRepository::DEFAULT_RETENTION_DAYS);
    }

    /**
     * Set the log retention period.
     */
    public function setRetentionDays(int $days): void
    {
        update_option(self::OPTION_RETENTION_DAYS, max(1, min(90, $days)));
    }

    /**
     * Run log cleanup (purge old entries).
     */
    public function cleanup(): int
    {
        return $this->repository->purgeOld($this->getRetentionDays());
    }

    /**
     * Get the underlying repository.
     */
    public function getRepository(): LogRepository
    {
        return $this->repository;
    }

    /**
     * Extract the UCP-Agent header value.
     */
    private function extractAgent(WP_REST_Request $request): ?string
    {
        $headers = $request->get_headers();

        return $headers['ucp_agent'][0]
            ?? $headers['ucp-agent'][0]
            ?? $headers['user_agent'][0]
            ?? null;
    }

    /**
     * Extract session ID from request.
     */
    private function extractSessionId(WP_REST_Request $request): ?string
    {
        // Check URL parameters
        $params = $request->get_url_params();
        if (!empty($params['id'])) {
            return $params['id'];
        }

        // Check body
        $body = $request->get_body_params();
        if (!empty($body['session_id'])) {
            return $body['session_id'];
        }

        return null;
    }

    /**
     * Get the client IP address.
     */
    private function getClientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Handle comma-separated list (X-Forwarded-For)
                if (str_contains($ip, ',')) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return 'unknown';
    }

    /**
     * Sanitize headers for logging.
     */
    private function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = ['authorization', 'cookie', 'x-api-key'];
        $sanitized = [];

        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, $sensitiveHeaders, true)) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = is_array($value) ? implode(', ', $value) : $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize data by redacting sensitive fields.
     */
    private function sanitizeData(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);

            // Check if this is a sensitive field
            $isSensitive = false;
            foreach (self::SENSITIVE_FIELDS as $sensitiveField) {
                if (str_contains($lowerKey, $sensitiveField)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeData($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
