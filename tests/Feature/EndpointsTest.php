<?php

/**
 * UCP Checkout Endpoints Test Suite
 *
 * Basic endpoint tests for UCP spec compliance.
 * For comprehensive checkout flow tests, see CheckoutFlowTest.php.
 */

describe('UCP Manifest', function (): void {
    it('returns valid UCP manifest with spec-compliant structure', function (): void {
        $response = get_ucp_manifest();

        expect($response)->toHaveKey('ucp');
        expect($response['ucp'])->toHaveKey('version');
        expect($response['ucp']['version'])->toBe('2026-01-11');
        expect($response['ucp'])->toHaveKey('services');
        expect($response['ucp'])->toHaveKey('capabilities');

        $capabilityNames = array_column($response['ucp']['capabilities'], 'name');
        expect($capabilityNames)->toContain('dev.ucp.shopping.checkout');

        expect($response)->toHaveKey('payment');
        expect($response['payment'])->toHaveKey('handlers');
        expect($response['payment']['handlers'][0])->toHaveKey('config_schema');
        expect($response['payment']['handlers'][0])->toHaveKey('instrument_schemas');
    });
});

describe('Checkout Session Create', function (): void {
    it('creates a checkout session with UCP spec-compliant structure', function (): void {
        $response = create_checkout_session();

        expect($response)->toHaveKey('ucp');
        expect($response)->toHaveKey('id');
        expect($response)->toHaveKey('status');
        expect($response['status'])->toBe('incomplete');

        expect($response)->toHaveKey('line_items');
        expect($response)->toHaveKey('currency');
        expect($response)->toHaveKey('totals');
        expect($response)->toHaveKey('payment');
        expect($response)->toHaveKey('links');
        expect($response)->toHaveKey('expires_at');

        expect($response['line_items'][0])->toHaveKey('item');
        expect($response['line_items'][0]['item'])->toHaveKey('id');
        expect($response['line_items'][0]['item'])->toHaveKey('title');
        expect($response['line_items'][0]['item'])->toHaveKey('unit_price');
        expect($response['line_items'][0])->toHaveKey('quantity');
        expect($response['line_items'][0])->toHaveKey('totals');

        expect($response['line_items'][0]['item']['unit_price'])->toBeInt();
    });

    it('returns validation error when creating session without line_items', function (): void {
        $response = ucp_api('/checkout-sessions', 'POST', []);

        expect($response)->toBeValidationError();
        expect($response['messages'][0]['severity'])->toBe('recoverable');
    });
});

describe('Checkout Session Get', function (): void {
    it('returns 404 for non-existent session', function (): void {
        $response = get_checkout_session('non-existent-session-id');

        expect($response)->toHaveKey('status');
        expect($response['status'])->toBe('not_found');
        expect($response)->toHaveKey('messages');
    });
});

describe('Checkout Session Update', function (): void {
    it('returns 404 for non-existent session', function (): void {
        $response = ucp_api('/checkout-sessions/non-existent-session-id', 'PUT', [
            'line_items' => [
                ['item' => ['id' => UCP_TEST_PRODUCT_ID], 'quantity' => 2],
            ],
        ]);

        expect($response)->toHaveKey('status');
        expect($response['status'])->toBe('not_found');
    });
});

describe('Checkout Session Cancel', function (): void {
    it('returns 404 for non-existent session', function (): void {
        $response = cancel_checkout_session('non-existent-session-id');

        expect($response)->toHaveKey('status');
        expect($response['status'])->toBe('not_found');
    });
});

describe('Checkout Session Complete', function (): void {
    it('returns error for non-existent session', function (): void {
        $response = complete_checkout_session('fake-session');

        expect($response)->toHaveKey('status');
        expect($response['status'])->toBe('not_found');
    });
});
