<?php

namespace UcpCheckout\Endpoints;

use UcpCheckout\Checkout\CheckoutSession;
use UcpCheckout\Checkout\CheckoutSessionRepository;
use UcpCheckout\Config\PluginConfig;
use UcpCheckout\Http\ErrorHandler;
use UcpCheckout\ProductConfiguration\ProductConfigurationChecker;
use UcpCheckout\WooCommerce\WooCommerceService;
use WP_REST_Request;
use WP_REST_Response;

class CheckoutSessionUpdateEndpoint extends AbstractEndpoint
{
    public function __construct(
        ?PluginConfig $config = null,
        private readonly ?CheckoutSessionRepository $repository = new CheckoutSessionRepository(),
        private readonly ?WooCommerceService $wcService = new WooCommerceService(),
        private readonly ?ProductConfigurationChecker $configurationChecker = new ProductConfigurationChecker()
    ) {
        parent::__construct($config);
    }

    public function getRoute(): string
    {
        return '/checkout-sessions/(?P<id>[a-zA-Z0-9_-]+)';
    }

    public function getMethods(): string
    {
        return 'PUT';
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $sessionId = $request->get_param('id');
        $params = $request->get_json_params();

        if (empty($sessionId)) {
            return $this->validationError([
                'id' => 'Session ID is required',
            ]);
        }

        $session = $this->repository->find($sessionId);

        if (!$session) {
            return $this->notFound('checkout_session', $sessionId);
        }

        // Check if session can be updated
        if (!$session->canUpdate()) {
            if ($session->isExpired()) {
                return ErrorHandler::createError(
                    'session_expired',
                    'session_expired',
                    'This checkout session has expired',
                    ErrorHandler::SEVERITY_ERROR,
                    400
                );
            }

            return ErrorHandler::createError(
                'invalid_session_status',
                'invalid_status',
                "Session cannot be updated. Current status: {$session->getStatus()}",
                ErrorHandler::SEVERITY_ERROR,
                400
            );
        }

        // Update line items if provided
        if (!empty($params['line_items'])) {
            $validationErrors = $this->validateLineItems($params['line_items']);
            if (!empty($validationErrors)) {
                return $this->validationError($validationErrors);
            }

            $lineItems = $this->buildLineItems($params['line_items']);
            $session->setLineItems($lineItems);
        }

        // Update shipping address if provided
        if (!empty($params['buyer']['shipping_address'])) {
            $session->setShippingAddress($params['buyer']['shipping_address']);
        }

        // Update shipping method if provided
        if (!empty($params['fulfillment']['shipping_method'])) {
            $session->setSelectedShippingMethod($params['fulfillment']['shipping_method']);
        }

        // Calculate shipping and tax when address is available
        $shippingAddress = $session->getShippingAddress();
        if ($shippingAddress) {
            $this->calculateShippingAndTax($session, $shippingAddress);
        }

        // Save session
        $this->repository->save($session);

        return $this->success($session->toApiResponse());
    }

    /**
     * Calculate shipping options and tax based on address.
     */
    private function calculateShippingAndTax(CheckoutSession $session, array $shippingAddress): void
    {
        $lineItems = $session->getLineItems();

        // Get available shipping methods
        $shippingMethods = $this->wcService->getAvailableShippingMethods($shippingAddress, $lineItems);
        $session->setAvailableShippingMethods($shippingMethods);

        // Calculate shipping cost for selected method (or first available)
        $selectedMethod = $session->getSelectedShippingMethod();
        $shippingResult = $this->wcService->calculateShipping($shippingAddress, $lineItems);

        if (!empty($shippingResult['options'])) {
            // Use selected method if valid, otherwise first available
            $shippingAmount = 0;
            if ($selectedMethod) {
                foreach ($shippingResult['options'] as $option) {
                    if ($option['id'] === $selectedMethod) {
                        $shippingAmount = $option['amount'];
                        break;
                    }
                }
            }

            if ($shippingAmount === 0 && count($shippingResult['options']) > 0) {
                // Default to first shipping option
                $shippingAmount = $shippingResult['options'][0]['amount'];
                $session->setSelectedShippingMethod($shippingResult['options'][0]['id']);
            }

            $session->setCalculatedShipping($shippingAmount);
        }

        // Calculate tax
        $tax = $this->wcService->calculateTax($shippingAddress, $lineItems);
        $session->setCalculatedTax($tax);
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

            $product = $this->findProduct($itemId);

            if (!$product) {
                $errors["line_items.{$index}.item.id"] = "Product not found: {$itemId}";
                continue;
            }

            if (!$product->is_in_stock()) {
                $errors["line_items.{$index}.item.id"] = "Product out of stock: {$itemId}";
            }

            // Check if product requires configuration (add-ons, composites, bundles)
            $configResult = $this->configurationChecker->check($product);
            if ($configResult !== null) {
                $errors["line_items.{$index}.item.id"] = sprintf(
                    "Product '%s' requires configuration (%s) and cannot be purchased via UCP. %s",
                    $product->get_name(),
                    $configResult['plugin'],
                    $configResult['reason']
                );
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
     */
    private function buildLineItems(array $inputItems): array
    {
        $lineItems = [];

        foreach ($inputItems as $inputItem) {
            $itemId = $inputItem['item']['id'] ?? $inputItem['item_id'];
            $product = $this->findProduct($itemId);
            $quantity = (int) ($inputItem['quantity'] ?? 1);

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
        $product = wc_get_product((int) $itemId);
        if ($product) {
            return $product;
        }

        $productId = wc_get_product_id_by_sku($itemId);
        if ($productId) {
            return wc_get_product($productId);
        }

        return null;
    }
}
