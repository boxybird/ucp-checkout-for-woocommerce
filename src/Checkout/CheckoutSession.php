<?php

namespace UcpPlugin\Checkout;

use DateTimeImmutable;

class CheckoutSession
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    private string $id;
    private string $status;
    private array $items;
    private ?array $shippingAddress;
    private ?string $selectedShippingMethod;
    private ?array $paymentInfo;
    private ?int $orderId;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $expiresAt;

    private function __construct()
    {
        // Use named constructors
    }

    /**
     * Create a new checkout session.
     */
    public static function create(array $items, int $expirationMinutes = 30): self
    {
        $session = new self();
        $session->id = self::generateId();
        $session->status = self::STATUS_PENDING;
        $session->items = $items;
        $session->shippingAddress = null;
        $session->selectedShippingMethod = null;
        $session->paymentInfo = null;
        $session->orderId = null;
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
        $session->items = $data['items'];
        $session->shippingAddress = $data['shipping_address'] ?? null;
        $session->selectedShippingMethod = $data['selected_shipping_method'] ?? null;
        $session->paymentInfo = $data['payment_info'] ?? null;
        $session->orderId = $data['order_id'] ?? null;
        $session->createdAt = new DateTimeImmutable($data['created_at']);
        $session->expiresAt = new DateTimeImmutable($data['expires_at']);

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
            'items' => $this->items,
            'shipping_address' => $this->shippingAddress,
            'selected_shipping_method' => $this->selectedShippingMethod,
            'payment_info' => $this->paymentInfo,
            'order_id' => $this->orderId,
            'created_at' => $this->createdAt->format('c'),
            'expires_at' => $this->expiresAt->format('c'),
        ];
    }

    /**
     * Convert to API response format.
     */
    public function toApiResponse(): array
    {
        $response = [
            'session_id' => $this->id,
            'status' => $this->status,
            'items' => $this->items,
            'created_at' => $this->createdAt->format('c'),
            'expires_at' => $this->expiresAt->format('c'),
        ];

        if ($this->shippingAddress) {
            $response['shipping_address'] = $this->shippingAddress;
        }

        if ($this->selectedShippingMethod) {
            $response['selected_shipping_method'] = $this->selectedShippingMethod;
        }

        if ($this->orderId) {
            $response['order_id'] = $this->orderId;
        }

        return $response;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getItems(): array
    {
        return $this->items;
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

    public function canComplete(): bool
    {
        return $this->status === self::STATUS_PENDING && !$this->isExpired();
    }

    public function setShippingAddress(array $address): void
    {
        $this->shippingAddress = $address;
    }

    public function setSelectedShippingMethod(string $methodId): void
    {
        $this->selectedShippingMethod = $methodId;
    }

    public function setPaymentInfo(array $info): void
    {
        $this->paymentInfo = $info;
    }

    public function markProcessing(): void
    {
        $this->status = self::STATUS_PROCESSING;
    }

    public function markCompleted(int $orderId): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->orderId = $orderId;
    }

    public function markExpired(): void
    {
        $this->status = self::STATUS_EXPIRED;
    }

    public function markCancelled(): void
    {
        $this->status = self::STATUS_CANCELLED;
    }

    /**
     * Generate a unique session ID.
     */
    private static function generateId(): string
    {
        return 'ucp_sess_' . bin2hex(random_bytes(16));
    }
}
