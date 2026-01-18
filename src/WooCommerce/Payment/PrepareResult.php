<?php

namespace UcpCheckout\WooCommerce\Payment;

/**
 * Value object representing the result of payment preparation.
 */
final readonly class PrepareResult
{
    /**
     * @param bool $success Whether preparation succeeded
     * @param string $message Human-readable message
     * @param array $context Additional context data for debugging
     */
    private function __construct(
        public bool $success,
        public string $message,
        public array $context = []
    ) {
    }

    /**
     * Create a successful preparation result.
     *
     * @param string $message Optional success message
     * @param array $context Additional context data
     * @return self
     */
    public static function success(string $message = 'Payment prepared successfully', array $context = []): self
    {
        return new self(true, $message, $context);
    }

    /**
     * Create a failed preparation result.
     *
     * @param string $message Error message
     * @param array $context Additional context data for debugging
     * @return self
     */
    public static function failure(string $message, array $context = []): self
    {
        return new self(false, $message, $context);
    }

    /**
     * Check if preparation succeeded.
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Check if preparation failed.
     *
     * @return bool
     */
    public function isFailure(): bool
    {
        return !$this->success;
    }
}
