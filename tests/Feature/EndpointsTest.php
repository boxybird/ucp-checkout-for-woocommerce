<?php

const BASE_URL = 'https://ucp-plugin.test';
const TEST_SKU = 'woo-tshirt';

function fetchJson(string $url, string $method = 'GET', array $data = []): array
{
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => "Content-Type: application/json\r\n",
            'content' => $method !== 'GET' ? json_encode($data) : null,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $response = file_get_contents($url, false, $context);
    return json_decode($response, true) ?? [];
}

describe('Manifest', function () {
    it('returns valid UCP manifest', function () {
        $response = fetchJson(BASE_URL . '/.well-known/ucp');
        expect($response)->toMatchSnapshot();
    });
});

describe('Search Endpoint', function () {
    it('searches for products', function () {
        $response = fetchJson(BASE_URL . '/wp-json/ucp/v1/search?q=shirt');
        expect($response)->toMatchSnapshot();
    });

    it('returns validation error for missing query parameter', function () {
        $response = fetchJson(BASE_URL . '/wp-json/ucp/v1/search');
        expect($response)->toMatchSnapshot();
    });
});

describe('Availability Endpoint', function () {
    it('returns product availability for valid SKU', function () {
        $response = fetchJson(BASE_URL . '/wp-json/ucp/v1/availability?sku=' . TEST_SKU);
        expect($response)->toMatchSnapshot();
    });

    it('returns validation error for missing SKU parameter', function () {
        $response = fetchJson(BASE_URL . '/wp-json/ucp/v1/availability');
        expect($response)->toMatchSnapshot();
    });

    it('returns 404 for non-existent SKU', function () {
        $response = fetchJson(BASE_URL . '/wp-json/ucp/v1/availability?sku=INVALID-SKU-12345');
        expect($response)->toMatchSnapshot();
    });
});

describe('Estimate Endpoint', function () {
    it('returns shipping estimate for valid SKU', function () {
        $response = fetchJson(BASE_URL . '/wp-json/ucp/v1/estimate', 'POST', [
            'sku' => TEST_SKU,
            'quantity' => 1,
            'country' => 'US',
            'state' => 'CA',
            'zip' => '90210',
        ]);
        expect($response)->toMatchSnapshot();
    });

    it('returns validation error for missing SKU', function () {
        $response = fetchJson(BASE_URL . '/wp-json/ucp/v1/estimate', 'POST', [
            'quantity' => 1,
        ]);
        expect($response)->toMatchSnapshot();
    });
});

describe('Checkout Session Create', function () {
    it('creates a checkout session', function () {
        $response = fetchJson(BASE_URL . '/wp-json/ucp/v1/checkout-sessions', 'POST', [
            'sku' => TEST_SKU,
            'quantity' => 1,
        ]);

        // Session ID and timestamps will vary - check structure instead
        expect($response)->toHaveKey('ucp');
        expect($response)->toHaveKey('data');
        expect($response['data'])->toHaveKey('session_id');
        expect($response['data']['status'])->toBe('pending');
    });

    it('returns validation error when creating session without items', function () {
        $response = fetchJson(BASE_URL . '/wp-json/ucp/v1/checkout-sessions', 'POST', []);
        expect($response)->toMatchSnapshot();
    });
});

describe('Checkout Session Get', function () {
    it('returns 404 for non-existent session', function () {
        $response = fetchJson(BASE_URL . '/wp-json/ucp/v1/checkout-sessions/non-existent-session-id');
        expect($response)->toMatchSnapshot();
    });
});

describe('Checkout Session Complete', function () {
    it('returns error for non-existent session', function () {
        $response = fetchJson(BASE_URL . '/wp-json/ucp/v1/checkout-sessions/fake-session/complete', 'POST', [
            'payment_token' => 'test_token_123',
            'shipping' => [
                'first_name' => 'Test',
                'last_name' => 'User',
                'address' => '123 Test St',
                'city' => 'Test City',
                'zip' => '12345',
                'country' => 'US',
            ],
        ]);
        expect($response)->toMatchSnapshot();
    });
});
