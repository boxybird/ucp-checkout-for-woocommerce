<?php

declare(strict_types=1);

namespace UcpCheckout\Logging;

/**
 * Value object representing a log entry.
 */
class LogEntry
{
    public const TYPE_REQUEST = 'request';
    public const TYPE_RESPONSE = 'response';
    public const TYPE_SESSION = 'session';
    public const TYPE_PAYMENT = 'payment';
    public const TYPE_ERROR = 'error';

    public function __construct(
        public readonly string $requestId,
        public readonly string $type,
        public readonly ?string $endpoint = null,
        public readonly ?string $method = null,
        public readonly ?string $sessionId = null,
        public readonly ?string $agent = null,
        public readonly ?string $ipAddress = null,
        public readonly ?int $statusCode = null,
        public readonly ?int $durationMs = null,
        public readonly array $data = [],
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?int $id = null,
    ) {
    }

    /**
     * Create a new request log entry.
     */
    public static function forRequest(
        string $requestId,
        string $endpoint,
        string $method,
        ?string $agent,
        ?string $ipAddress,
        array $data = [],
        ?string $sessionId = null,
    ): self {
        return new self(
            requestId: $requestId,
            type: self::TYPE_REQUEST,
            endpoint: $endpoint,
            method: $method,
            sessionId: $sessionId,
            agent: $agent,
            ipAddress: $ipAddress,
            data: $data,
            createdAt: new \DateTimeImmutable(),
        );
    }

    /**
     * Create a new response log entry.
     */
    public static function forResponse(
        string $requestId,
        string $endpoint,
        string $method,
        int $statusCode,
        int $durationMs,
        array $data = [],
        ?string $sessionId = null,
    ): self {
        return new self(
            requestId: $requestId,
            type: self::TYPE_RESPONSE,
            endpoint: $endpoint,
            method: $method,
            sessionId: $sessionId,
            statusCode: $statusCode,
            durationMs: $durationMs,
            data: $data,
            createdAt: new \DateTimeImmutable(),
        );
    }

    /**
     * Create a new session event log entry.
     */
    public static function forSession(
        string $requestId,
        string $sessionId,
        string $event,
        array $context = [],
    ): self {
        return new self(
            requestId: $requestId,
            type: self::TYPE_SESSION,
            sessionId: $sessionId,
            data: ['event' => $event, 'context' => $context],
            createdAt: new \DateTimeImmutable(),
        );
    }

    /**
     * Create a new payment event log entry.
     */
    public static function forPayment(
        string $requestId,
        string $sessionId,
        string $handler,
        bool $success,
        ?string $error = null,
        array $context = [],
    ): self {
        return new self(
            requestId: $requestId,
            type: self::TYPE_PAYMENT,
            sessionId: $sessionId,
            data: [
                'handler' => $handler,
                'success' => $success,
                'error' => $error,
                'context' => $context,
            ],
            createdAt: new \DateTimeImmutable(),
        );
    }

    /**
     * Create a new error log entry.
     */
    public static function forError(
        string $requestId,
        \Throwable $exception,
        ?string $endpoint = null,
        ?string $sessionId = null,
        bool $includeTrace = false,
    ): self {
        $data = [
            'exception_type' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];

        if ($includeTrace) {
            $data['trace'] = $exception->getTraceAsString();
        }

        return new self(
            requestId: $requestId,
            type: self::TYPE_ERROR,
            endpoint: $endpoint,
            sessionId: $sessionId,
            data: $data,
            createdAt: new \DateTimeImmutable(),
        );
    }

    /**
     * Convert to array for storage.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'request_id' => $this->requestId,
            'type' => $this->type,
            'endpoint' => $this->endpoint,
            'method' => $this->method,
            'session_id' => $this->sessionId,
            'agent' => $this->agent,
            'ip_address' => $this->ipAddress,
            'status_code' => $this->statusCode,
            'duration_ms' => $this->durationMs,
            'data' => $this->data,
            'created_at' => $this->createdAt?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Create from database row.
     */
    public static function fromRow(array $row): self
    {
        return new self(
            requestId: $row['request_id'],
            type: $row['type'],
            endpoint: $row['endpoint'] ?? null,
            method: $row['method'] ?? null,
            sessionId: $row['session_id'] ?? null,
            agent: $row['agent'] ?? null,
            ipAddress: $row['ip_address'] ?? null,
            statusCode: isset($row['status_code']) ? (int) $row['status_code'] : null,
            durationMs: isset($row['duration_ms']) ? (int) $row['duration_ms'] : null,
            data: isset($row['data']) ? json_decode($row['data'], true) ?? [] : [],
            createdAt: isset($row['created_at']) ? new \DateTimeImmutable($row['created_at']) : null,
            id: isset($row['id']) ? (int) $row['id'] : null,
        );
    }
}
