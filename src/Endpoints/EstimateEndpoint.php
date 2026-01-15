<?php

namespace UcpCheckout\Endpoints;

use WP_REST_Request;
use WP_REST_Response;

class EstimateEndpoint extends AbstractEndpoint
{
    public function getRoute(): string
    {
        return '/estimate';
    }

    public function getMethods(): string
    {
        return 'POST';
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params();

        $errors = $this->validateRequired($params, ['sku']);
        if (!empty($errors)) {
            return $this->validationError($errors);
        }

        $sku = $params['sku'];
        $quantity = (int) ($params['quantity'] ?? 1);
        $postcode = $params['zip'] ?? '';
        $country = $params['country'] ?? 'US';
        $state = $params['state'] ?? '';

        $productId = wc_get_product_id_by_sku($sku);

        if (!$productId) {
            return $this->notFound('product', $sku);
        }

        $product = wc_get_product($productId);

        if (!$product) {
            return $this->notFound('product', $sku);
        }

        // Set customer location for tax/shipping calculation
        WC()->customer->set_billing_location($country, $state, $postcode);
        WC()->customer->set_shipping_location($country, $state, $postcode);

        // Calculate subtotal
        $unitPrice = (float) $product->get_price();
        $subtotal = $unitPrice * $quantity;

        // Calculate tax
        $taxIncludingPrice = (float) wc_get_price_including_tax($product, ['qty' => $quantity]);
        $tax = $taxIncludingPrice - $subtotal;

        // Calculate shipping options
        $shippingOptions = $this->calculateShipping($product, $productId, $quantity, $country, $state, $postcode);

        return $this->success([
            'sku' => $sku,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $subtotal,
            'tax' => round($tax, 2),
            'currency' => get_woocommerce_currency(),
            'shipping_options' => $shippingOptions,
            'destination' => [
                'country' => $country,
                'state' => $state,
                'postcode' => $postcode,
            ],
        ]);
    }

    /**
     * Calculate available shipping options.
     */
    private function calculateShipping(
        \WC_Product $product,
        int $productId,
        int $quantity,
        string $country,
        string $state,
        string $postcode
    ): array {
        $package = [
            [
                'contents' => [
                    $productId => [
                        'data' => $product,
                        'quantity' => $quantity,
                        'line_total' => $product->get_price() * $quantity,
                        'line_tax' => 0,
                    ],
                ],
                'contents_cost' => $product->get_price() * $quantity,
                'destination' => [
                    'country' => $country,
                    'state' => $state,
                    'postcode' => $postcode,
                    'city' => '',
                    'address' => '',
                    'address_2' => '',
                ],
            ],
        ];

        $shippingMethods = WC()->shipping->calculate_shipping($package);

        $options = [];
        if (!empty($shippingMethods[0]['rates'])) {
            foreach ($shippingMethods[0]['rates'] as $rate) {
                $options[] = [
                    'id' => $rate->id,
                    'method_id' => $rate->method_id,
                    'label' => $rate->label,
                    'cost' => (float) $rate->cost,
                    'currency' => get_woocommerce_currency(),
                ];
            }
        }

        return $options;
    }
}
