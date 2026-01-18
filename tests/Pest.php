<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(Tests\TestCase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
|
| Test environment configuration. These values should match your local
| WooCommerce test site setup.
|
*/

const UCP_TEST_BASE_URL = 'https://ucp-plugin.test';
const UCP_TEST_PRODUCT_ID = '36';
const UCP_TEST_PAYMENT_HANDLER = 'cheque';

// Stripe test configuration
const UCP_TEST_STRIPE_ENABLED = true;
const UCP_TEST_STRIPE_HANDLER = 'stripe';

/*
|--------------------------------------------------------------------------
| HTTP Client
|--------------------------------------------------------------------------
|
| Helper function for making JSON HTTP requests to the UCP API.
|
*/

/**
 * Make a JSON HTTP request to the UCP API.
 *
 * @param string $url Full URL to request
 * @param string $method HTTP method (GET, POST, PUT, DELETE)
 * @param array $data Request body data (for POST/PUT)
 * @return array Decoded JSON response
 */
function ucp_request(string $url, string $method = 'GET', array $data = []): array
{
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => "Content-Type: application/json\r\nUCP-Agent: pest-test/1.0\r\n",
            'content' => $method !== 'GET' ? json_encode($data) : null,
            'ignore_errors' => true,
            'timeout' => 30,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $response = file_get_contents($url, false, $context);

    return json_decode($response, true) ?? [];
}

/**
 * Make a request to a UCP API endpoint (prefixes with base URL).
 *
 * @param string $endpoint API endpoint path (e.g., '/checkout-sessions')
 * @param string $method HTTP method
 * @param array $data Request body data
 * @return array Decoded JSON response
 */
function ucp_api(string $endpoint, string $method = 'GET', array $data = []): array
{
    $url = UCP_TEST_BASE_URL . '/wp-json/ucp/v1' . $endpoint;

    return ucp_request($url, $method, $data);
}

/*
|--------------------------------------------------------------------------
| Checkout Session Helpers
|--------------------------------------------------------------------------
|
| Factory functions for creating test data and checkout sessions.
|
*/

/**
 * Create a new checkout session with default test product.
 *
 * @param int $quantity Product quantity
 * @param string|null $productId Product ID (defaults to UCP_TEST_PRODUCT_ID)
 * @return array API response with session data
 */
function create_checkout_session(int $quantity = 1, ?string $productId = null): array
{
    return ucp_api('/checkout-sessions', 'POST', [
        'line_items' => [
            ['item' => ['id' => $productId ?? UCP_TEST_PRODUCT_ID], 'quantity' => $quantity],
        ],
        'currency' => 'USD',
    ]);
}

/**
 * Get a valid test shipping address (US, California).
 *
 * @param array $overrides Fields to override
 * @return array UCP-format shipping address
 */
function test_shipping_address(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Test',
        'last_name' => 'User',
        'street_address' => '123 Test St',
        'address_locality' => 'Los Angeles',
        'address_region' => 'CA',
        'postal_code' => '90210',
        'address_country' => 'US',
        'email' => 'test@example.com',
        'phone' => '555-1234',
    ], $overrides);
}

/**
 * Get test payment data for completing checkout.
 *
 * @param string|null $handlerId Payment handler ID (defaults to UCP_TEST_PAYMENT_HANDLER)
 * @param array $credential Payment credential data
 * @return array UCP-format payment data
 */
function test_payment_data(?string $handlerId = null, array $credential = ['token' => 'test']): array
{
    return [
        'handler_id' => $handlerId ?? UCP_TEST_PAYMENT_HANDLER,
        'credential' => $credential,
    ];
}

/**
 * Get Stripe test payment data with a test PaymentMethod.
 *
 * Uses Stripe's special test PaymentMethod tokens that work in test mode.
 *
 * @param string $testCard Test card type: 'visa', 'mastercard', 'amex', 'declined', '3ds'
 * @return array UCP-format payment data for Stripe
 */
function stripe_payment_data(string $testCard = 'visa'): array
{
    // Stripe test PaymentMethod tokens
    // See: https://docs.stripe.com/testing#cards
    $testPaymentMethods = [
        'visa' => 'pm_card_visa',
        'mastercard' => 'pm_card_mastercard',
        'amex' => 'pm_card_amex',
        'declined' => 'pm_card_visa_chargeDeclined',
        'insufficient_funds' => 'pm_card_visa_chargeDeclinedInsufficientFunds',
        '3ds' => 'pm_card_threeDSecure2Required',
    ];

    return [
        'handler_id' => UCP_TEST_STRIPE_HANDLER,
        'credential' => [
            'token' => $testPaymentMethods[$testCard] ?? $testPaymentMethods['visa'],
        ],
    ];
}

/**
 * Update a checkout session with shipping address.
 *
 * @param string $sessionId Session ID
 * @param array|null $address Shipping address (defaults to test_shipping_address())
 * @return array API response
 */
function add_shipping_to_session(string $sessionId, ?array $address = null): array
{
    return ucp_api("/checkout-sessions/{$sessionId}", 'PUT', [
        'buyer' => ['shipping_address' => $address ?? test_shipping_address()],
    ]);
}

/**
 * Complete a checkout session.
 *
 * @param string $sessionId Session ID
 * @param array|null $paymentData Payment data (defaults to test_payment_data())
 * @param array|null $address Shipping address (defaults to test_shipping_address())
 * @return array API response
 */
function complete_checkout_session(string $sessionId, ?array $paymentData = null, ?array $address = null): array
{
    return ucp_api("/checkout-sessions/{$sessionId}/complete", 'POST', [
        'payment_data' => $paymentData ?? test_payment_data(),
        'buyer' => ['shipping_address' => $address ?? test_shipping_address()],
    ]);
}

/**
 * Cancel a checkout session.
 *
 * @param string $sessionId Session ID
 * @return array API response
 */
function cancel_checkout_session(string $sessionId): array
{
    return ucp_api("/checkout-sessions/{$sessionId}/cancel", 'POST');
}

/**
 * Get a checkout session by ID.
 *
 * @param string $sessionId Session ID
 * @return array API response
 */
function get_checkout_session(string $sessionId): array
{
    return ucp_api("/checkout-sessions/{$sessionId}");
}

/**
 * Fetch the UCP manifest.
 *
 * @return array Manifest data
 */
function get_ucp_manifest(): array
{
    return ucp_request(UCP_TEST_BASE_URL . '/.well-known/ucp');
}

/*
|--------------------------------------------------------------------------
| Custom Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeUcpSession', function (): Pest\Expectation {
    return $this
        ->toHaveKey('id')
        ->toHaveKey('status')
        ->toHaveKey('line_items')
        ->toHaveKey('currency')
        ->toHaveKey('totals');
});

expect()->extend('toBeValidationError', function (): Pest\Expectation {
    return $this
        ->toHaveKey('status')
        ->and($this->value['status'])->toBe('validation_error')
        ->and($this->value)->toHaveKey('messages');
});
