<?php

/**
 * UCP Checkout Flow Feature Tests
 *
 * End-to-end tests for the complete checkout flow against a live WooCommerce site.
 * These tests validate the full checkout journey from session creation to order completion.
 *
 * Requirements:
 * - A running WooCommerce site at UCP_TEST_BASE_URL
 * - At least one product with ID UCP_TEST_PRODUCT_ID
 * - Shipping zones configured
 * - A simple payment gateway enabled (e.g., 'cheque' or 'bacs')
 */

// =============================================================================
// MANIFEST DISCOVERY
// =============================================================================

describe('Manifest Discovery', function (): void {
    it('returns valid UCP manifest at /.well-known/ucp', function (): void {
        $response = get_ucp_manifest();

        expect($response)->toHaveKey('ucp');
        expect($response['ucp']['version'])->toBe('2026-01-11');
        expect($response['ucp'])->toHaveKey('services');
        expect($response['ucp'])->toHaveKey('capabilities');
    });

    it('includes dev.ucp.shopping.checkout capability', function (): void {
        $response = get_ucp_manifest();

        $capabilityNames = array_column($response['ucp']['capabilities'], 'name');
        expect($capabilityNames)->toContain('dev.ucp.shopping.checkout');
    });

    it('includes payment handlers with required fields', function (): void {
        $response = get_ucp_manifest();

        expect($response)->toHaveKey('payment');
        expect($response['payment'])->toHaveKey('handlers');
        expect($response['payment']['handlers'])->not->toBeEmpty();

        $handler = $response['payment']['handlers'][0];
        expect($handler)->toHaveKey('id');
        expect($handler)->toHaveKey('name');
        expect($handler)->toHaveKey('version');
        expect($handler)->toHaveKey('config');
    });

    it('includes correct REST endpoint URL', function (): void {
        $response = get_ucp_manifest();

        expect($response['ucp']['services']['dev.ucp.shopping']['rest']['endpoint'])
            ->toBe(UCP_TEST_BASE_URL . '/wp-json/ucp/v1');
    });
});

// =============================================================================
// SESSION CREATION
// =============================================================================

describe('Checkout Session Creation', function (): void {
    it('creates session with incomplete status', function (): void {
        $response = create_checkout_session();

        expect($response)->toHaveKey('id');
        expect($response['id'])->toStartWith('ucp_sess_');
        expect($response['status'])->toBe('incomplete');
    });

    it('includes line items with product details', function (): void {
        $response = create_checkout_session(quantity: 2);

        expect($response['line_items'])->toHaveCount(1);
        expect($response['line_items'][0]['item'])->toHaveKey('id');
        expect($response['line_items'][0]['item'])->toHaveKey('title');
        expect($response['line_items'][0]['item'])->toHaveKey('unit_price');
        expect($response['line_items'][0]['item'])->toHaveKey('image');
        expect($response['line_items'][0]['quantity'])->toBe(2);
    });

    it('returns prices in minor units (cents)', function (): void {
        $response = create_checkout_session();

        expect($response['line_items'][0]['item']['unit_price'])->toBeInt();
        expect($response['line_items'][0]['item']['unit_price'])->toBeGreaterThan(0);
    });

    it('includes totals with subtotal', function (): void {
        $response = create_checkout_session();

        expect($response)->toHaveKey('totals');
        $totalTypes = array_column($response['totals'], 'type');
        expect($totalTypes)->toContain('subtotal');
        expect($totalTypes)->toContain('total');
    });

    it('includes payment handlers', function (): void {
        $response = create_checkout_session();

        expect($response)->toHaveKey('payment');
        expect($response['payment'])->toHaveKey('handlers');
        expect($response['payment']['handlers'])->not->toBeEmpty();
    });

    it('includes required links', function (): void {
        $response = create_checkout_session();

        expect($response)->toHaveKey('links');
        expect($response['links'])->toHaveKey('privacy_policy');
        expect($response['links'])->toHaveKey('terms_of_service');
    });

    it('includes expiration timestamp', function (): void {
        $response = create_checkout_session();

        expect($response)->toHaveKey('expires_at');
        expect(strtotime((string) $response['expires_at']))->toBeGreaterThan(time());
    });
});

// =============================================================================
// SESSION RETRIEVAL
// =============================================================================

describe('Checkout Session Retrieval', function (): void {
    it('retrieves existing session by ID', function (): void {
        $created = create_checkout_session();
        $sessionId = $created['id'];

        $response = get_checkout_session($sessionId);

        expect($response['id'])->toBe($sessionId);
        expect($response['status'])->toBe('incomplete');
    });

    it('returns not_found for non-existent session', function (): void {
        $response = get_checkout_session('invalid_session_id');

        expect($response['status'])->toBe('not_found');
        expect($response['messages'][0]['code'])->toBe('checkout_session_not_found');
    });
});

// =============================================================================
// SESSION UPDATE WITH SHIPPING ADDRESS
// =============================================================================

describe('Checkout Session Update with Shipping Address', function (): void {
    it('accepts shipping address and returns fulfillment options', function (): void {
        $created = create_checkout_session();
        $sessionId = $created['id'];

        $response = add_shipping_to_session($sessionId);

        expect($response['id'])->toBe($sessionId);
        expect($response)->toHaveKey('fulfillment');
        expect($response['fulfillment'])->toHaveKey('options');
        expect($response['fulfillment']['options'])->not->toBeEmpty();
    });

    it('returns shipping options with id, name, and amount', function (): void {
        $created = create_checkout_session();
        $response = add_shipping_to_session($created['id']);

        $option = $response['fulfillment']['options'][0];
        expect($option)->toHaveKey('id');
        expect($option)->toHaveKey('name');
        expect($option)->toHaveKey('amount');
        expect($option['amount'])->toBeInt();
    });

    it('auto-selects first shipping method', function (): void {
        $created = create_checkout_session();
        $response = add_shipping_to_session($created['id']);

        expect($response['fulfillment'])->toHaveKey('selected');
        expect($response['fulfillment']['selected'])->toBe($response['fulfillment']['options'][0]['id']);
    });
});

// =============================================================================
// SHIPPING METHOD SELECTION
// =============================================================================

describe('Shipping Method Selection', function (): void {
    it('allows selecting a different shipping method', function (): void {
        $created = create_checkout_session();
        $sessionId = $created['id'];

        $withAddress = add_shipping_to_session($sessionId);

        if (count($withAddress['fulfillment']['options']) < 2) {
            $this->markTestSkipped('Need at least 2 shipping options');
        }

        $secondOption = $withAddress['fulfillment']['options'][1];

        $response = ucp_api("/checkout-sessions/{$sessionId}", 'PUT', [
            'fulfillment' => ['shipping_method' => $secondOption['id']],
        ]);

        expect($response['fulfillment']['selected'])->toBe($secondOption['id']);
    });

    it('updates totals when shipping method changes', function (): void {
        $created = create_checkout_session();
        $sessionId = $created['id'];

        $withAddress = add_shipping_to_session($sessionId);

        // Find a paid shipping option
        $paidOption = null;
        foreach ($withAddress['fulfillment']['options'] as $option) {
            if ($option['amount'] > 0) {
                $paidOption = $option;
                break;
            }
        }

        if (!$paidOption) {
            $this->markTestSkipped('Need a paid shipping option');
        }

        $response = ucp_api("/checkout-sessions/{$sessionId}", 'PUT', [
            'fulfillment' => ['shipping_method' => $paidOption['id']],
        ]);

        $totalTypes = array_column($response['totals'], 'type');
        expect($totalTypes)->toContain('shipping');

        $shippingTotal = array_filter($response['totals'], fn($t) => $t['type'] === 'shipping');
        $shippingAmount = array_values($shippingTotal)[0]['amount'];
        expect($shippingAmount)->toBe($paidOption['amount']);
    });
});

// =============================================================================
// CHECKOUT COMPLETION
// =============================================================================

describe('Checkout Completion', function (): void {
    it('completes checkout and creates order', function (): void {
        $created = create_checkout_session();
        $sessionId = $created['id'];

        add_shipping_to_session($sessionId);
        $response = complete_checkout_session($sessionId);

        expect($response['status'])->toBe('completed');
        expect($response)->toHaveKey('order');
        expect($response['order'])->toHaveKey('id');
        expect($response['order']['status'])->toBe('confirmed');
    });

    it('returns order ID as string', function (): void {
        $created = create_checkout_session();
        $sessionId = $created['id'];

        add_shipping_to_session($sessionId);
        $response = complete_checkout_session($sessionId);

        expect($response['order']['id'])->toBeString();
    });

    it('cannot complete already completed session', function (): void {
        $created = create_checkout_session();
        $sessionId = $created['id'];

        add_shipping_to_session($sessionId);
        complete_checkout_session($sessionId);

        // Try again
        $response = complete_checkout_session($sessionId);

        expect($response['status'])->toBe('invalid_session_status');
    });
});

// =============================================================================
// SESSION CANCELLATION
// =============================================================================

describe('Checkout Session Cancellation', function (): void {
    it('cancels an incomplete session', function (): void {
        $created = create_checkout_session();
        $response = cancel_checkout_session($created['id']);

        expect($response['status'])->toBe('canceled');
    });

    it('cannot complete a canceled session', function (): void {
        $created = create_checkout_session();
        $sessionId = $created['id'];

        cancel_checkout_session($sessionId);
        $response = complete_checkout_session($sessionId);

        expect($response['status'])->toBe('invalid_session_status');
        expect($response['messages'][0]['message'])->toContain('canceled');
    });

    it('cannot update a canceled session', function (): void {
        $created = create_checkout_session();
        $sessionId = $created['id'];

        cancel_checkout_session($sessionId);
        $response = add_shipping_to_session($sessionId);

        expect($response['status'])->toBe('invalid_session_status');
    });
});

// =============================================================================
// ERROR SCENARIOS
// =============================================================================

describe('Error Scenarios', function (): void {
    it('returns validation error for non-existent product', function (): void {
        $response = create_checkout_session(productId: '99999');

        expect($response['status'])->toBe('validation_error');
        expect($response['messages'][0]['message'])->toContain('not found');
    });

    it('returns validation error for zero quantity', function (): void {
        $response = create_checkout_session(quantity: 0);

        expect($response['status'])->toBe('validation_error');
        expect($response['messages'][0]['message'])->toContain('Quantity');
    });

    it('returns validation error for missing line_items', function (): void {
        $response = ucp_api('/checkout-sessions', 'POST', ['currency' => 'USD']);

        expect($response['status'])->toBe('validation_error');
        expect($response['messages'][0]['code'])->toContain('line_items');
    });

    it('returns validation error for missing payment data on complete', function (): void {
        $created = create_checkout_session();
        $sessionId = $created['id'];

        add_shipping_to_session($sessionId);

        $response = ucp_api("/checkout-sessions/{$sessionId}/complete", 'POST', [
            'buyer' => ['shipping_address' => test_shipping_address()],
        ]);

        expect($response['status'])->toBe('validation_error');
        expect($response['messages'][0]['code'])->toContain('payment_data');
    });

    it('returns validation error for missing shipping address on complete', function (): void {
        $created = create_checkout_session();
        $sessionId = $created['id'];

        $response = ucp_api("/checkout-sessions/{$sessionId}/complete", 'POST', [
            'payment_data' => test_payment_data(),
        ]);

        expect($response['status'])->toBe('validation_error');
        expect($response['messages'][0]['code'])->toContain('shipping_address');
    });

    it('returns error for invalid payment handler', function (): void {
        $created = create_checkout_session();
        $sessionId = $created['id'];

        add_shipping_to_session($sessionId);
        $response = complete_checkout_session($sessionId, test_payment_data('invalid_gateway'));

        expect($response['status'])->toBe('error');
        expect($response['messages'][0]['message'])->toContain('payment gateway');
    });

    it('returns recoverable severity for all validation errors', function (): void {
        $response = ucp_api('/checkout-sessions', 'POST', []);

        expect($response['messages'][0]['severity'])->toBe('recoverable');
    });
});

// =============================================================================
// FULL CHECKOUT FLOW
// =============================================================================

describe('Full Checkout Flow', function (): void {
    it('completes entire checkout journey', function (): void {
        // Step 1: Create session
        $session = create_checkout_session(quantity: 2);
        expect($session['status'])->toBe('incomplete');
        $sessionId = $session['id'];

        // Step 2: Add shipping address
        $withAddress = add_shipping_to_session($sessionId);
        expect($withAddress['fulfillment']['options'])->not->toBeEmpty();

        // Step 3: Select shipping method (if multiple options)
        if (count($withAddress['fulfillment']['options']) > 1) {
            $selectedMethod = $withAddress['fulfillment']['options'][1]['id'];
            $withShipping = ucp_api("/checkout-sessions/{$sessionId}", 'PUT', [
                'fulfillment' => ['shipping_method' => $selectedMethod],
            ]);
            expect($withShipping['fulfillment']['selected'])->toBe($selectedMethod);
        }

        // Step 4: Complete checkout
        $completed = complete_checkout_session($sessionId);

        expect($completed['status'])->toBe('completed');
        expect($completed['order']['id'])->toBeString();
        expect($completed['order']['status'])->toBe('confirmed');

        // Step 5: Verify session is no longer modifiable
        $retryComplete = complete_checkout_session($sessionId);
        expect($retryComplete['status'])->toBe('invalid_session_status');
    });
});

// =============================================================================
// LINE ITEM UPDATES
// =============================================================================

describe('Line Item Updates', function (): void {
    it('allows updating line item quantity', function (): void {
        $created = create_checkout_session(quantity: 1);
        $sessionId = $created['id'];
        $originalSubtotal = $created['totals'][0]['amount'];

        $response = ucp_api("/checkout-sessions/{$sessionId}", 'PUT', [
            'line_items' => [
                ['item' => ['id' => UCP_TEST_PRODUCT_ID], 'quantity' => 3],
            ],
        ]);

        expect($response['line_items'][0]['quantity'])->toBe(3);

        $newSubtotal = $response['totals'][0]['amount'];
        expect($newSubtotal)->toBe($originalSubtotal * 3);
    });
});

// =============================================================================
// STRIPE PAYMENT GATEWAY
// =============================================================================

describe('Stripe Payment Gateway', function (): void {
    beforeEach(function (): void {
        if (!UCP_TEST_STRIPE_ENABLED) {
            $this->markTestSkipped('Stripe testing is disabled');
        }
    });

    it('shows stripe in manifest payment handlers', function (): void {
        $manifest = get_ucp_manifest();

        $handlerIds = array_column($manifest['payment']['handlers'], 'id');
        expect($handlerIds)->toContain('stripe');
    });

    it('completes checkout with Stripe test visa card', function (): void {
        // Create session
        $session = create_checkout_session();
        $sessionId = $session['id'];

        // Add shipping
        add_shipping_to_session($sessionId);

        // Complete with Stripe payment
        $response = complete_checkout_session($sessionId, stripe_payment_data('visa'));

        expect($response['status'])->toBe('completed');
        expect($response['order'])->toHaveKey('id');
        expect($response['order']['status'])->toBe('confirmed');
    });

    it('completes checkout with Stripe test mastercard', function (): void {
        $session = create_checkout_session();
        $sessionId = $session['id'];

        add_shipping_to_session($sessionId);
        $response = complete_checkout_session($sessionId, stripe_payment_data('mastercard'));

        expect($response['status'])->toBe('completed');
    });

    it('completes checkout with Stripe test amex', function (): void {
        $session = create_checkout_session();
        $sessionId = $session['id'];

        add_shipping_to_session($sessionId);
        $response = complete_checkout_session($sessionId, stripe_payment_data('amex'));

        expect($response['status'])->toBe('completed');
    });

    it('handles declined card gracefully', function (): void {
        $session = create_checkout_session();
        $sessionId = $session['id'];

        add_shipping_to_session($sessionId);
        $response = complete_checkout_session($sessionId, stripe_payment_data('declined'));

        // Should fail with an error, not crash
        expect($response['status'])->toBeIn(['error', 'payment_failed']);
        expect($response)->toHaveKey('messages');
    });

    it('handles insufficient funds gracefully', function (): void {
        $session = create_checkout_session();
        $sessionId = $session['id'];

        add_shipping_to_session($sessionId);
        $response = complete_checkout_session($sessionId, stripe_payment_data('insufficient_funds'));

        expect($response['status'])->toBeIn(['error', 'payment_failed']);
        expect($response)->toHaveKey('messages');
    });

    it('completes full checkout flow with Stripe', function (): void {
        // Step 1: Create session
        $session = create_checkout_session(quantity: 2);
        expect($session['status'])->toBe('incomplete');
        $sessionId = $session['id'];

        // Step 2: Add shipping address
        $withAddress = add_shipping_to_session($sessionId);
        expect($withAddress['fulfillment']['options'])->not->toBeEmpty();

        // Step 3: Select shipping method if multiple available
        if (count($withAddress['fulfillment']['options']) > 1) {
            $selectedMethod = $withAddress['fulfillment']['options'][1]['id'];
            ucp_api("/checkout-sessions/{$sessionId}", 'PUT', [
                'fulfillment' => ['shipping_method' => $selectedMethod],
            ]);
        }

        // Step 4: Complete checkout with Stripe
        $completed = complete_checkout_session($sessionId, stripe_payment_data('visa'));

        expect($completed['status'])->toBe('completed');
        expect($completed['order']['id'])->toBeString();
        expect($completed['order']['status'])->toBe('confirmed');

        // Step 5: Verify session is no longer modifiable
        $retryComplete = complete_checkout_session($sessionId, stripe_payment_data('visa'));
        expect($retryComplete['status'])->toBe('invalid_session_status');
    });
});
