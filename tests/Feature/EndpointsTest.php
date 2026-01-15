<?php

/**
 * UCP Checkout Endpoints Test Suite
 *
 * Tests the UCP spec-compliant checkout session endpoints.
 * All tests validate against the official UCP specification (2026-01-11).
 */

const BASE_URL = 'https://ucp-plugin.test';
const TEST_PRODUCT_ID = '15'; // WooCommerce product ID for testing

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

describe('UCP Manifest', function (): void {
    it('returns valid UCP manifest with spec-compliant structure', function (): void {
        $response = fetchJson(BASE_URL . '/.well-known/ucp');

        // Validate UCP spec structure
        expect($response)->toHaveKey('ucp');
        expect($response['ucp'])->toHaveKey('version');
        expect($response['ucp']['version'])->toBe('2026-01-11');
        expect($response['ucp'])->toHaveKey('services');
        expect($response['ucp'])->toHaveKey('capabilities');

        // Validate capabilities use correct naming
        $capabilityNames = array_column($response['ucp']['capabilities'], 'name');
        expect($capabilityNames)->toContain('dev.ucp.shopping.checkout');

        // Validate payment handlers
        expect($response)->toHaveKey('payment');
        expect($response['payment'])->toHaveKey('handlers');
        expect($response['payment']['handlers'][0])->toHaveKey('config_schema');
        expect($response['payment']['handlers'][0])->toHaveKey('instrument_schemas');
    });
});

describe('Checkout Session Create', function (): void {
    it('creates a checkout session with UCP spec-compliant structure', function (): void {
        $response = fetchJson(BASE_URL . '/wp-json/ucp/v1/checkout-sessions', 'POST', [
            'line_items' => [
                ['item' => ['id' => TEST_PRODUCT_ID], 'quantity' => 1],
            ],
            'currency' => 'USD',
        ]);

        // Validate UCP envelope (data at root level, not wrapped)
        expect($response)->toHaveKey('ucp');
        expect($response)->toHaveKey('id'); // Not session_id
        expect($response)->toHaveKey('status');
        expect($response['status'])->toBe('incomplete'); // Not pending

        // Validate required UCP spec fields
        expect($response)->toHaveKey('line_items');
        expect($response)->toHaveKey('currency');
        expect($response)->toHaveKey('totals');
        expect($response)->toHaveKey('payment');
        expect($response)->toHaveKey('links');
        expect($response)->toHaveKey('expires_at');

        // Validate line item structure
        expect($response['line_items'][0])->toHaveKey('item');
        expect($response['line_items'][0]['item'])->toHaveKey('id');
        expect($response['line_items'][0]['item'])->toHaveKey('title');
        expect($response['line_items'][0]['item'])->toHaveKey('unit_price');
        expect($response['line_items'][0])->toHaveKey('quantity');
        expect($response['line_items'][0])->toHaveKey('totals');

        // Validate prices are in minor units (cents)
        expect($response['line_items'][0]['item']['unit_price'])->toBeInt();
    });

    it('returns validation error when creating session without line_items', function (): void {
        $response = fetchJson(BASE_URL . '/wp-json/ucp/v1/checkout-sessions', 'POST', []);

        expect($response)->toHaveKey('status');
        expect($response['status'])->toBe('validation_error');
        expect($response)->toHaveKey('messages');
        expect($response['messages'][0]['severity'])->toBe('recoverable');
    });
});

describe('Checkout Session Get', function (): void {
    it('returns 404 for non-existent session', function (): void {
        $response = fetchJson(BASE_URL . '/wp-json/ucp/v1/checkout-sessions/non-existent-session-id');

        expect($response)->toHaveKey('status');
        expect($response['status'])->toBe('not_found');
        expect($response)->toHaveKey('messages');
    });
});

describe('Checkout Session Update', function (): void {
    it('returns 404 for non-existent session', function (): void {
        $response = fetchJson(BASE_URL . '/wp-json/ucp/v1/checkout-sessions/non-existent-session-id', 'PUT', [
            'line_items' => [
                ['item' => ['id' => TEST_PRODUCT_ID], 'quantity' => 2],
            ],
        ]);

        expect($response)->toHaveKey('status');
        expect($response['status'])->toBe('not_found');
    });
});

describe('Checkout Session Cancel', function (): void {
    it('returns 404 for non-existent session', function (): void {
        $response = fetchJson(BASE_URL . '/wp-json/ucp/v1/checkout-sessions/non-existent-session-id/cancel', 'POST', []);

        expect($response)->toHaveKey('status');
        expect($response['status'])->toBe('not_found');
    });
});

describe('Checkout Session Complete', function (): void {
    it('returns error for non-existent session', function (): void {
        $response = fetchJson(BASE_URL . '/wp-json/ucp/v1/checkout-sessions/fake-session/complete', 'POST', [
            'payment_data' => [
                'handler_id' => 'ucp_agent',
                'credential' => ['token' => 'test_token_123'],
            ],
            'buyer' => [
                'shipping_address' => [
                    'first_name' => 'Test',
                    'last_name' => 'User',
                    'street_address' => '123 Test St',
                    'address_locality' => 'Test City',
                    'postal_code' => '12345',
                    'address_country' => 'US',
                ],
            ],
        ]);

        expect($response)->toHaveKey('status');
        expect($response['status'])->toBe('not_found');
    });
});
