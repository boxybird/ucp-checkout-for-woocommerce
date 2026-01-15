<?php

namespace UcpCheckout\Endpoints;

use WP_REST_Request;
use WP_REST_Response;

class AvailabilityEndpoint extends AbstractEndpoint
{
    public function getRoute(): string
    {
        return '/availability';
    }

    public function getMethods(): string
    {
        return 'GET';
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $sku = $request->get_param('sku');

        if (empty($sku)) {
            return $this->validationError([
                'sku' => 'SKU is required',
            ]);
        }

        $productId = wc_get_product_id_by_sku($sku);

        if (!$productId) {
            return $this->notFound('product', $sku);
        }

        $product = wc_get_product($productId);

        if (!$product) {
            return $this->notFound('product', $sku);
        }

        return $this->success([
            'sku' => $sku,
            'name' => $product->get_name(),
            'in_stock' => $product->is_in_stock(),
            'stock_status' => $product->get_stock_status(),
            'stock_quantity' => $product->get_stock_quantity(),
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price() ?: null,
            'currency' => get_woocommerce_currency(),
            'backorders_allowed' => $product->backorders_allowed(),
        ]);
    }
}
