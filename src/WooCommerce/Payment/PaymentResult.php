<?php

namespace UcpCheckout\WooCommerce\Payment;

/**
 * Value object representing the result of payment processing.
 */
final readonly class PaymentResult
{
    /**
     * @param bool $success Whether payment succeeded
     * @param string $message Human-readable message
     * @param string|null $redirect Redirect URL if applicable
     * @param string|null $transactionId Transaction ID from the gateway
     * @param array $context Additional context data
     */
    private function __construct(
        public bool $success,
        public string $message,
        public ?string $redirect = null,
        public ?string $transactionId = null,
        public array $context = []
    ) {
    }

    /**
     * Create a successful payment result.
     *
     * @param string $message Success message
     * @param string|null $redirect Redirect URL
     * @param string|null $transactionId Transaction ID
     * @param array $context Additional context
     * @return self
     */
    public static function success(
        string $message = 'Payment processed successfully',
        ?string $redirect = null,
        ?string $transactionId = null,
        array $context = []
    ): self {
        return new self(true, $message, $redirect, $transactionId, $context);
    }

    /**
     * Create a failed payment result.
     *
     * @param string $message Error message
     * @param array $context Additional context for debugging
     * @return self
     */
    public static function failure(string $message, array $context = []): self
    {
        return new self(false, $message, null, null, $context);
    }

    /**
     * Check if payment succeeded.
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Check if payment failed.
     *
     * @return bool
     */
    public function isFailure(): bool
    {
        return !$this->success;
    }

    /**
     * Convert to legacy array format for backwards compatibility.
     *
     * @return array{success: bool, message: string, redirect?: string}
     */
    public function toArray(): array
    {
        $result = [
            'success' => $this->success,
            'message' => $this->message,
        ];

        if ($this->redirect !== null) {
            $result['redirect'] = $this->redirect;
        }

        if ($this->transactionId !== null) {
            $result['transaction_id'] = $this->transactionId;
        }

        return $result;
    }
}
