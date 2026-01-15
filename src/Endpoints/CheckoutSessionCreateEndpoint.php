<?php

namespace UcpCheckout\Endpoints;

use UcpCheckout\Checkout\CheckoutSession;
use UcpCheckout\Checkout\CheckoutSessionRepository;
use UcpCheckout\Config\PluginConfig;
use WP_REST_Request;
use WP_REST_Response;

class CheckoutSessionCreateEndpoint extends AbstractEndpoint
{
    public function __construct(
        ?PluginConfig $config = null,
        private readonly ?CheckoutSessionRepository $repository = new CheckoutSessionRepository()
    ) {
        parent::__construct($config);
    }

    public function getRoute(): string
    {
        return '/checkout-sessions';
    }

    public function getMethods(): string
    {
        return 'POST';
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params();

        // UCP spec requires line_items array
        if (empty($params['line_items'])) {
            return $this->validationError([
                'line_items' => 'line_items array is required',
            ]);
        }

        // Validate currency
        $currency = $params['currency'] ?? get_woocommerce_currency();

        // Validate all products exist and are available
        $validationErrors = $this->validateLineItems($params['line_items']);
        if (!empty($validationErrors)) {
            return $this->validationError($validationErrors);
        }

        // Build UCP spec-compliant line items
        $lineItems = $this->buildLineItems($params['line_items']);

        // Create the session
        $session = CheckoutSession::create($lineItems, $currency);

        // Optionally set shipping address if provided (buyer info)
        if (!empty($params['buyer']['shipping_address'])) {
            $session->setShippingAddress($params['buyer']['shipping_address']);
        }

        // Save session
        $this->repository->save($session);

        return $this->success($session->toApiResponse(), 201);
    }

    /**
     * Validate all line items have valid products.
     */
    private function validateLineItems(array $lineItems): array
    {
        $errors = [];

        foreach ($lineItems as $index => $lineItem) {
            $itemId = $lineItem['item']['id'] ?? $lineItem['item_id'] ?? null;

            if (empty($itemId)) {
                $errors["line_items.{$index}.item.id"] = 'Item ID is required';
                continue;
            }

            // Item ID can be product ID or SKU
            $product = $this->findProduct($itemId);

            if (!$product) {
                $errors["line_items.{$index}.item.id"] = "Product not found: {$itemId}";
                continue;
            }

            if (!$product->is_in_stock()) {
                $errors["line_items.{$index}.item.id"] = "Product out of stock: {$itemId}";
            }

            $quantity = $lineItem['quantity'] ?? 1;
            if ($quantity < 1) {
                $errors["line_items.{$index}.quantity"] = 'Quantity must be at least 1';
            }
        }

        return $errors;
    }

    /**
     * Build UCP spec-compliant line items from input.
     * Prices are in minor units (cents).
     */
    private function buildLineItems(array $inputItems): array
    {
        $lineItems = [];

        foreach ($inputItems as $inputItem) {
            $itemId = $inputItem['item']['id'] ?? $inputItem['item_id'];
            $product = $this->findProduct($itemId);
            $quantity = (int) ($inputItem['quantity'] ?? 1);

            // Convert price to minor units (cents)
            $unitPriceCents = (int) round((float) $product->get_price() * 100);
            $subtotalCents = $unitPriceCents * $quantity;

            $imageId = $product->get_image_id();
            $imageUrl = $imageId ? wp_get_attachment_url((int) $imageId) : null;

            $lineItems[] = [
                'item' => [
                    'id' => (string) $product->get_id(),
                    'title' => $product->get_name(),
                    'unit_price' => $unitPriceCents,
                    'image' => $imageUrl ?: null,
                ],
                'quantity' => $quantity,
                'totals' => [
                    ['type' => 'subtotal', 'amount' => $subtotalCents],
                ],
            ];
        }

        return $lineItems;
    }

    /**
     * Find product by ID or SKU.
     */
    private function findProduct(string $itemId): ?\WC_Product
    {
        // Try as product ID first
        $product = wc_get_product((int) $itemId);
        if ($product) {
            return $product;
        }

        // Try as SKU
        $productId = wc_get_product_id_by_sku($itemId);
        if ($productId) {
            return wc_get_product($productId);
        }

        return null;
    }
}
