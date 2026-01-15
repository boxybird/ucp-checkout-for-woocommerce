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

        // Validate items
        if (empty($params['items']) && empty($params['sku'])) {
            return $this->validationError([
                'items' => 'At least one item is required (provide items array or sku)',
            ]);
        }

        // Support both single SKU and items array
        $items = $this->normalizeItems($params);

        if (empty($items)) {
            return $this->validationError([
                'items' => 'No valid items provided',
            ]);
        }

        // Validate all products exist and are available
        $validationErrors = $this->validateItems($items);
        if (!empty($validationErrors)) {
            return $this->validationError($validationErrors);
        }

        // Enrich items with product data
        $enrichedItems = $this->enrichItems($items);

        // Create the session
        $session = CheckoutSession::create($enrichedItems);

        // Optionally set shipping address if provided
        if (!empty($params['shipping'])) {
            $session->setShippingAddress($params['shipping']);
        }

        // Save session
        $this->repository->save($session);

        return $this->success($session->toApiResponse(), 201);
    }

    /**
     * Normalize input to items array.
     */
    private function normalizeItems(array $params): array
    {
        if (!empty($params['items'])) {
            return $params['items'];
        }

        // Single SKU shorthand
        if (!empty($params['sku'])) {
            return [
                [
                    'sku' => $params['sku'],
                    'quantity' => (int) ($params['quantity'] ?? 1),
                ],
            ];
        }

        return [];
    }

    /**
     * Validate all items have valid products.
     */
    private function validateItems(array $items): array
    {
        $errors = [];

        foreach ($items as $index => $item) {
            if (empty($item['sku'])) {
                $errors["items.{$index}.sku"] = 'SKU is required';
                continue;
            }

            $productId = wc_get_product_id_by_sku($item['sku']);
            if (!$productId) {
                $errors["items.{$index}.sku"] = "Product not found: {$item['sku']}";
                continue;
            }

            $product = wc_get_product($productId);
            if (!$product) {
                $errors["items.{$index}.sku"] = "Product not found: {$item['sku']}";
                continue;
            }

            if (!$product->is_in_stock()) {
                $errors["items.{$index}.sku"] = "Product out of stock: {$item['sku']}";
            }
        }

        return $errors;
    }

    /**
     * Enrich items with product data.
     */
    private function enrichItems(array $items): array
    {
        $enriched = [];

        foreach ($items as $item) {
            $productId = wc_get_product_id_by_sku($item['sku']);
            $product = wc_get_product($productId);
            $quantity = (int) ($item['quantity'] ?? 1);

            $enriched[] = [
                'sku' => $item['sku'],
                'product_id' => $productId,
                'name' => $product->get_name(),
                'quantity' => $quantity,
                'unit_price' => (float) $product->get_price(),
                'line_total' => (float) $product->get_price() * $quantity,
                'currency' => get_woocommerce_currency(),
            ];
        }

        return $enriched;
    }
}
