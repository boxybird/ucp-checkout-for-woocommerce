<?php

namespace UcpCheckout\Endpoints;

use WP_REST_Request;
use WP_REST_Response;

class SearchEndpoint extends AbstractEndpoint
{
    public function getRoute(): string
    {
        return '/search';
    }

    public function getMethods(): string
    {
        return 'GET';
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $query = $request->get_param('q');

        if (empty($query)) {
            return $this->validationError([
                'q' => 'Search query is required',
            ]);
        }

        $limit = (int) ($request->get_param('limit') ?? 5);
        $limit = min(max($limit, 1), 20); // Clamp between 1 and 20

        $products = wc_get_products([
            's' => $query,
            'limit' => $limit,
            'status' => 'publish',
        ]);

        $results = [];
        foreach ($products as $product) {
            $results[] = $this->formatProduct($product);
        }

        return $this->success([
            'query' => $query,
            'count' => count($results),
            'results' => $results,
        ]);
    }

    /**
     * Format a WooCommerce product for API response.
     */
    private function formatProduct(\WC_Product $product): array
    {
        return [
            'name' => $product->get_name(),
            'sku' => $product->get_sku(),
            'price' => $product->get_price(),
            'currency' => get_woocommerce_currency(),
            'in_stock' => $product->is_in_stock(),
            'image' => wp_get_attachment_image_url((int) $product->get_image_id(), 'full'),
            'url' => $product->get_permalink(),
        ];
    }
}
