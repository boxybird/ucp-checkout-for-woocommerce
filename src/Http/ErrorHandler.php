<?php

namespace UcpCheckout\Http;

use WP_REST_Response;
use Exception;

class ErrorHandler
{
    // UCP spec severity values
    public const SEVERITY_RECOVERABLE = 'recoverable';
    public const SEVERITY_REQUIRES_BUYER_INPUT = 'requires_buyer_input';
    public const SEVERITY_REQUIRES_BUYER_REVIEW = 'requires_buyer_review';

    // Legacy alias for backwards compatibility during refactor
    public const SEVERITY_ERROR = 'recoverable';

    public const STATUS_ERROR = 'error';
    public const STATUS_VALIDATION_ERROR = 'validation_error';
    public const STATUS_NOT_FOUND = 'not_found';
    public const STATUS_UNAUTHORIZED = 'unauthorized';
    public const STATUS_REQUIRES_ESCALATION = 'requires_escalation';
    public const STATUS_VERSION_UNSUPPORTED = 'version_unsupported';

    /**
     * Create a UCP-compliant error response.
     *
     * @param string $status Error status type
     * @param string $code Error code
     * @param string $message Human-readable message
     * @param string $severity Error severity (recoverable, requires_buyer_input, requires_buyer_review)
     * @param int $httpStatus HTTP status code
     */
    public static function createError(
        string $status,
        string $code,
        string $message,
        string $severity = self::SEVERITY_RECOVERABLE,
        int $httpStatus = 400
    ): WP_REST_Response {
        return new WP_REST_Response([
            'status' => $status,
            'messages' => [
                [
                    'type' => 'error',
                    'code' => $code,
                    'message' => $message,
                    'severity' => $severity,
                ],
            ],
        ], $httpStatus);
    }

    /**
     * Create error response from an exception.
     * In production, sensitive details are hidden from the response but logged for debugging.
     */
    public static function fromException(Exception $e, string $status = self::STATUS_ERROR): WP_REST_Response
    {
        $httpStatus = 500;

        if ($e->getCode() >= 400 && $e->getCode() < 600) {
            $httpStatus = $e->getCode();
        }

        // In production, hide detailed exception messages that could leak sensitive info
        $message = (defined('WP_DEBUG') && WP_DEBUG)
            ? $e->getMessage()
            : 'An error occurred while processing your request.';

        // Always log the full error for debugging
        error_log(sprintf(
            'UCP Error: [%s] %s in %s:%d',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        return self::createError(
            $status,
            self::exceptionToCode($e),
            $message,
            self::SEVERITY_ERROR,
            $httpStatus
        );
    }

    /**
     * Create a not found error response.
     */
    public static function notFound(string $resource, string $identifier = ''): WP_REST_Response
    {
        $message = $identifier
            ? sprintf('%s not found: %s', ucfirst($resource), $identifier)
            : sprintf('%s not found', ucfirst($resource));

        return self::createError(
            self::STATUS_NOT_FOUND,
            $resource . '_not_found',
            $message,
            self::SEVERITY_ERROR,
            404
        );
    }

    /**
     * Create a validation error response with multiple messages.
     * Per UCP spec, validation errors are recoverable.
     *
     * @param array $errors Array of ['field' => 'error message']
     */
    public static function validationError(array $errors): WP_REST_Response
    {
        $messages = [];

        foreach ($errors as $field => $message) {
            $messages[] = [
                'type' => 'error',
                'code' => 'invalid_' . $field,
                'message' => $message,
                'severity' => self::SEVERITY_RECOVERABLE,
            ];
        }

        return new WP_REST_Response([
            'status' => self::STATUS_VALIDATION_ERROR,
            'messages' => $messages,
        ], 400);
    }

    /**
     * Create an unauthorized error response.
     */
    public static function unauthorized(string $reason = 'Authentication required'): WP_REST_Response
    {
        return self::createError(
            self::STATUS_UNAUTHORIZED,
            'unauthorized',
            $reason,
            self::SEVERITY_ERROR,
            401
        );
    }

    /**
     * Create a requires escalation error (needs human intervention).
     * Per UCP spec, this requires buyer input.
     */
    public static function requiresEscalation(string $reason): WP_REST_Response
    {
        return self::createError(
            self::STATUS_REQUIRES_ESCALATION,
            'escalation_required',
            $reason,
            self::SEVERITY_REQUIRES_BUYER_INPUT,
            400
        );
    }

    /**
     * Convert exception class name to error code.
     */
    private static function exceptionToCode(Exception $e): string
    {
        $className = new \ReflectionClass($e)->getShortName();
        $code = preg_replace('/Exception$/', '', $className);
        $code = strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', (string) $code));

        return $code ?: 'unknown_error';
    }
}
