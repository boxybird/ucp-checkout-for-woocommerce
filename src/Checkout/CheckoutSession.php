<?php

namespace UcpCheckout\Checkout;

use DateTimeImmutable;

class CheckoutSession
{
    // UCP spec status values
    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_REQUIRES_ESCALATION = 'requires_escalation';
    public const STATUS_READY_FOR_COMPLETE = 'ready_for_complete';
    public const STATUS_COMPLETE_IN_PROGRESS = 'complete_in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELED = 'canceled';

    private string $id;
    private string $status;
    private array $lineItems;
    private string $currency;
    private ?array $shippingAddress = null;
    private ?string $selectedShippingMethod = null;
    private ?array $paymentData = null;
    private ?int $orderId = null;
    private ?string $continueUrl = null;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $expiresAt;

    // WooCommerce integration fields
    private int $calculatedShipping = 0;
    private int $calculatedTax = 0;
    private array $availableShippingMethods = [];
    private array $availablePaymentHandlers = [];

    private function __construct()
    {
        // Use named constructors
    }

    /**
     * Create a new checkout session.
     *
     * @param array $lineItems Array of line items with UCP spec structure
     * @param string $currency ISO 4217 currency code
     * @param int $expirationMinutes Session expiration time (default 6 hours per spec)
     */
    public static function create(array $lineItems, string $currency = 'USD', int $expirationMinutes = 360): self
    {
        $session = new self();
        $session->id = self::generateId();
        $session->status = self::STATUS_INCOMPLETE;
        $session->lineItems = $lineItems;
        $session->currency = $currency;
        $session->shippingAddress = null;
        $session->selectedShippingMethod = null;
        $session->paymentData = null;
        $session->orderId = null;
        $session->continueUrl = null;
        $session->createdAt = new DateTimeImmutable();
        $session->expiresAt = $session->createdAt->modify("+{$expirationMinutes} minutes");

        return $session;
    }

    /**
     * Reconstruct from stored data.
     */
    public static function fromArray(array $data): self
    {
        $session = new self();
        $session->id = $data['id'];
        $session->status = $data['status'];
        $session->lineItems = $data['line_items'];
        $session->currency = $data['currency'] ?? 'USD';
        $session->shippingAddress = $data['shipping_address'] ?? null;
        $session->selectedShippingMethod = $data['selected_shipping_method'] ?? null;
        $session->paymentData = $data['payment_data'] ?? null;
        $session->orderId = $data['order_id'] ?? null;
        $session->continueUrl = $data['continue_url'] ?? null;
        $session->createdAt = new DateTimeImmutable($data['created_at']);
        $session->expiresAt = new DateTimeImmutable($data['expires_at']);
        $session->calculatedShipping = $data['calculated_shipping'] ?? 0;
        $session->calculatedTax = $data['calculated_tax'] ?? 0;
        $session->availableShippingMethods = $data['available_shipping_methods'] ?? [];
        $session->availablePaymentHandlers = $data['available_payment_handlers'] ?? [];

        return $session;
    }

    /**
     * Convert to array for storage.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'line_items' => $this->lineItems,
            'currency' => $this->currency,
            'shipping_address' => $this->shippingAddress,
            'selected_shipping_method' => $this->selectedShippingMethod,
            'payment_data' => $this->paymentData,
            'order_id' => $this->orderId,
            'continue_url' => $this->continueUrl,
            'created_at' => $this->createdAt->format('c'),
            'expires_at' => $this->expiresAt->format('c'),
            'calculated_shipping' => $this->calculatedShipping,
            'calculated_tax' => $this->calculatedTax,
            'available_shipping_methods' => $this->availableShippingMethods,
            'available_payment_handlers' => $this->availablePaymentHandlers,
        ];
    }

    /**
     * Convert to UCP spec-compliant API response format.
     */
    public function toApiResponse(): array
    {
        $response = [
            'id' => $this->id,
            'status' => $this->status,
            'line_items' => $this->lineItems,
            'currency' => $this->currency,
            'totals' => $this->calculateTotals(),
            'payment' => $this->buildPaymentConfig(),
            'links' => $this->buildLinks(),
            'expires_at' => $this->expiresAt->format('c'),
        ];

        // Include fulfillment options if shipping address is set
        if ($this->shippingAddress || !empty($this->availableShippingMethods)) {
            $response['fulfillment'] = $this->buildFulfillmentConfig();
        }

        // continue_url is required when status is requires_escalation
        if ($this->continueUrl || $this->status === self::STATUS_REQUIRES_ESCALATION) {
            $response['continue_url'] = $this->continueUrl ?? $this->generateContinueUrl();
        }

        // Include order info if completed
        if ($this->orderId && $this->status === self::STATUS_COMPLETED) {
            $response['order'] = [
                'id' => (string) $this->orderId,
                'status' => 'confirmed',
            ];
        }

        return $response;
    }

    /**
     * Calculate totals from line items.
     * Returns array of total objects per UCP spec.
     */
    private function calculateTotals(): array
    {
        $subtotal = 0;
        foreach ($this->lineItems as $lineItem) {
            foreach ($lineItem['totals'] ?? [] as $total) {
                if ($total['type'] === 'subtotal') {
                    $subtotal += $total['amount'];
                }
            }
        }

        $totals = [
            ['type' => 'subtotal', 'amount' => $subtotal],
        ];

        // Include shipping if calculated
        if ($this->calculatedShipping > 0) {
            $totals[] = ['type' => 'shipping', 'amount' => $this->calculatedShipping];
        }

        // Include tax if calculated
        if ($this->calculatedTax > 0) {
            $totals[] = ['type' => 'tax', 'amount' => $this->calculatedTax];
        }

        // Calculate total
        $total = $subtotal + $this->calculatedShipping + $this->calculatedTax;
        $totals[] = ['type' => 'total', 'amount' => $total];

        return $totals;
    }

    /**
     * Build payment configuration for response.
     */
    private function buildPaymentConfig(): array
    {
        // Use available handlers from WooCommerce if set
        if (!empty($this->availablePaymentHandlers)) {
            return [
                'handlers' => $this->availablePaymentHandlers,
            ];
        }

        // Default handler
        return [
            'handlers' => [
                [
                    'id' => 'ucp_agent',
                    'name' => 'dev.ucp.payment.agent',
                    'instrument_types' => ['card'],
                ],
            ],
        ];
    }

    /**
     * Build fulfillment configuration for response.
     */
    private function buildFulfillmentConfig(): array
    {
        $fulfillment = [];

        if (!empty($this->availableShippingMethods)) {
            $fulfillment['options'] = $this->availableShippingMethods;
        }

        if ($this->selectedShippingMethod) {
            $fulfillment['selected'] = $this->selectedShippingMethod;
        }

        return $fulfillment;
    }

    /**
     * Build required links for response.
     */
    private function buildLinks(): array
    {
        return [
            'privacy_policy' => home_url('/privacy-policy/'),
            'terms_of_service' => home_url('/terms-of-service/'),
        ];
    }

    /**
     * Generate continue URL for escalation scenarios.
     */
    private function generateContinueUrl(): string
    {
        return home_url('/checkout/?ucp_session=' . $this->id);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getLineItems(): array
    {
        return $this->lineItems;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getShippingAddress(): ?array
    {
        return $this->shippingAddress;
    }

    public function getSelectedShippingMethod(): ?string
    {
        return $this->selectedShippingMethod;
    }

    public function getOrderId(): ?int
    {
        return $this->orderId;
    }

    public function isExpired(): bool
    {
        return new DateTimeImmutable() > $this->expiresAt;
    }

    /**
     * Check if session can be completed.
     * Per UCP spec, only incomplete or ready_for_complete sessions can be completed.
     */
    public function canComplete(): bool
    {
        $completableStatuses = [
            self::STATUS_INCOMPLETE,
            self::STATUS_READY_FOR_COMPLETE,
        ];

        return in_array($this->status, $completableStatuses, true) && !$this->isExpired();
    }

    /**
     * Check if session can be updated.
     */
    public function canUpdate(): bool
    {
        $updatableStatuses = [
            self::STATUS_INCOMPLETE,
            self::STATUS_REQUIRES_ESCALATION,
        ];

        return in_array($this->status, $updatableStatuses, true) && !$this->isExpired();
    }

    /**
     * Check if session can be canceled.
     */
    public function canCancel(): bool
    {
        $cancelableStatuses = [
            self::STATUS_INCOMPLETE,
            self::STATUS_REQUIRES_ESCALATION,
            self::STATUS_READY_FOR_COMPLETE,
        ];

        return in_array($this->status, $cancelableStatuses, true);
    }

    public function setShippingAddress(array $address): void
    {
        $this->shippingAddress = $address;
    }

    public function setSelectedShippingMethod(string $methodId): void
    {
        $this->selectedShippingMethod = $methodId;
    }

    public function setPaymentData(array $data): void
    {
        $this->paymentData = $data;
    }

    public function setContinueUrl(string $url): void
    {
        $this->continueUrl = $url;
    }

    public function setLineItems(array $lineItems): void
    {
        $this->lineItems = $lineItems;
    }

    public function setCalculatedShipping(int $amount): void
    {
        $this->calculatedShipping = $amount;
    }

    public function setCalculatedTax(int $amount): void
    {
        $this->calculatedTax = $amount;
    }

    public function setAvailableShippingMethods(array $methods): void
    {
        $this->availableShippingMethods = $methods;
    }

    public function setAvailablePaymentHandlers(array $handlers): void
    {
        $this->availablePaymentHandlers = $handlers;
    }

    public function getCalculatedShipping(): int
    {
        return $this->calculatedShipping;
    }

    public function getCalculatedTax(): int
    {
        return $this->calculatedTax;
    }

    public function getPaymentData(): ?array
    {
        return $this->paymentData;
    }

    public function markReadyForComplete(): void
    {
        $this->status = self::STATUS_READY_FOR_COMPLETE;
    }

    public function markRequiresEscalation(): void
    {
        $this->status = self::STATUS_REQUIRES_ESCALATION;
    }

    public function markCompleteInProgress(): void
    {
        $this->status = self::STATUS_COMPLETE_IN_PROGRESS;
    }

    public function markCompleted(int $orderId): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->orderId = $orderId;
    }

    public function markCanceled(): void
    {
        $this->status = self::STATUS_CANCELED;
    }

    /**
     * Generate a unique session ID.
     */
    private static function generateId(): string
    {
        return 'ucp_sess_' . bin2hex(random_bytes(16));
    }
}
