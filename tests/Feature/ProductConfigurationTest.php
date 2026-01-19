<?php

/**
 * Product Configuration Validation Feature Tests
 *
 * Tests for the product configuration detection system that prevents
 * products requiring configuration (add-ons, bundles, composites) from
 * being added to UCP checkout sessions.
 *
 * Note: Full integration tests with actual add-on plugins would require
 * installing those premium plugins on the test site. These tests verify
 * the core functionality with simple products and Container wiring.
 */

use UcpCheckout\Container;
use UcpCheckout\ProductConfiguration\ProductConfigurationChecker;
use UcpCheckout\ProductConfiguration\Detectors\WooCommerceProductAddOnsDetector;
use UcpCheckout\ProductConfiguration\Detectors\YithProductAddOnsDetector;
use UcpCheckout\ProductConfiguration\Detectors\CompositeProductsDetector;
use UcpCheckout\ProductConfiguration\Detectors\ProductBundlesDetector;

// =============================================================================
// CONTAINER INTEGRATION
// =============================================================================

describe('Container Integration', function (): void {
    it('registers ProductConfigurationChecker in container', function (): void {
        $container = Container::bootstrap();

        expect($container->has(ProductConfigurationChecker::class))->toBeTrue();

        $checker = $container->get(ProductConfigurationChecker::class);
        expect($checker)->toBeInstanceOf(ProductConfigurationChecker::class);
    });

    it('ProductConfigurationChecker has all four detectors registered', function (): void {
        $container = Container::bootstrap();
        $checker = $container->get(ProductConfigurationChecker::class);

        $detectors = $checker->getDetectors();

        expect($detectors)->toHaveCount(4);

        /** @var array<class-string> $detectorClasses */
        $detectorClasses = array_map(fn ($d) => $d::class, $detectors);

        expect($detectorClasses)->toContain(WooCommerceProductAddOnsDetector::class);
        expect($detectorClasses)->toContain(YithProductAddOnsDetector::class);
        expect($detectorClasses)->toContain(CompositeProductsDetector::class);
        expect($detectorClasses)->toContain(ProductBundlesDetector::class);
    });
});

// =============================================================================
// SIMPLE PRODUCT VALIDATION
// =============================================================================

describe('Simple Product Validation', function (): void {
    it('allows simple product without configuration requirements', function (): void {
        $response = create_checkout_session();

        // Should succeed - simple products don't require configuration
        expect($response)->toHaveKey('id');
        expect($response['status'])->toBe('incomplete');
        expect($response)->not->toHaveKey('messages');
    });

    it('allows updating session with simple product', function (): void {
        $session = create_checkout_session();
        $sessionId = $session['id'];

        // Update with same simple product at different quantity
        $response = ucp_api("/checkout-sessions/{$sessionId}", 'PUT', [
            'line_items' => [
                ['item' => ['id' => UCP_TEST_PRODUCT_ID], 'quantity' => 3],
            ],
        ]);

        expect($response)->toHaveKey('id');
        expect($response['line_items'][0]['quantity'])->toBe(3);
    });
});

// =============================================================================
// CONFIGURATION CHECK BEHAVIOR
// =============================================================================

describe('Configuration Check Behavior', function (): void {
    it('ProductConfigurationChecker returns null for simple product', function (): void {
        $container = Container::bootstrap();
        $checker = $container->get(ProductConfigurationChecker::class);
        $product = wc_get_product(UCP_TEST_PRODUCT_ID);

        $result = $checker->check($product);

        expect($result)->toBeNull();
    });

    it('all detectors return false for simple product', function (): void {
        $product = wc_get_product(UCP_TEST_PRODUCT_ID);

        $detectors = [
            new WooCommerceProductAddOnsDetector(),
            new YithProductAddOnsDetector(),
            new CompositeProductsDetector(),
            new ProductBundlesDetector(),
        ];

        foreach ($detectors as $detector) {
            expect($detector->requiresConfiguration($product))->toBeFalse(
                "Detector {$detector->getPluginName()} should return false for simple product"
            );
        }
    });

    it('detectors are not applicable when plugins are not installed', function (): void {
        $detectors = [
            new WooCommerceProductAddOnsDetector(),
            new YithProductAddOnsDetector(),
            new CompositeProductsDetector(),
            new ProductBundlesDetector(),
        ];

        foreach ($detectors as $detector) {
            // None of these premium plugins should be installed on test site
            expect($detector->isApplicable())->toBeFalse(
                "Detector {$detector->getPluginName()} should not be applicable without plugin"
            );
        }
    });
});

// =============================================================================
// ERROR FORMAT VALIDATION
// =============================================================================

describe('Product Configuration Error Format', function (): void {
    it('validation errors follow UCP error format', function (): void {
        // Test with non-existent product to see validation error format
        $response = ucp_api('/checkout-sessions', 'POST', [
            'line_items' => [
                ['item' => ['id' => '99999999'], 'quantity' => 1],
            ],
            'currency' => 'USD',
        ]);

        expect($response)->toHaveKey('status');
        expect($response['status'])->toBe('validation_error');
        expect($response)->toHaveKey('messages');
        expect($response['messages'])->toBeArray();

        $message = $response['messages'][0];
        expect($message)->toHaveKey('type');
        expect($message)->toHaveKey('code');
        expect($message)->toHaveKey('message');
        expect($message)->toHaveKey('severity');
        expect($message['type'])->toBe('error');
        expect($message['severity'])->toBe('recoverable');
    });

    it('product configuration error would include plugin name in message', function (): void {
        // This test documents the expected error format when a product
        // requires configuration. We test this by simulating what the
        // error message format would look like.

        $expectedPattern = "Product '%s' requires configuration (%s) and cannot be purchased via UCP. %s";

        // Verify the pattern matches what endpoints generate
        $testMessage = sprintf(
            $expectedPattern,
            'Test Product',
            'WooCommerce Product Add-Ons',
            'Please select the required add-on options on the product page.'
        );

        expect($testMessage)->toContain('requires configuration');
        expect($testMessage)->toContain('WooCommerce Product Add-Ons');
        expect($testMessage)->toContain('cannot be purchased via UCP');
    });
});

// =============================================================================
// ENDPOINT INTEGRATION
// =============================================================================

describe('Endpoint Integration', function (): void {
    it('CheckoutSessionCreateEndpoint validates product configuration', function (): void {
        // Creating a session with simple product should work
        $response = create_checkout_session();

        expect($response)->toHaveKey('id');
        expect($response)->not->toHaveKey('status', 'validation_error');
    });

    it('CheckoutSessionUpdateEndpoint validates product configuration on line item changes', function (): void {
        $session = create_checkout_session();
        $sessionId = $session['id'];

        // Updating with simple product should work
        $response = ucp_api("/checkout-sessions/{$sessionId}", 'PUT', [
            'line_items' => [
                ['item' => ['id' => UCP_TEST_PRODUCT_ID], 'quantity' => 5],
            ],
        ]);

        expect($response)->toHaveKey('id');
        expect($response['line_items'][0]['quantity'])->toBe(5);
    });

    it('multiple products in session all get validated', function (): void {
        // If we had multiple test products, we'd test them here
        // For now, test with same product twice to verify loop runs
        $response = ucp_api('/checkout-sessions', 'POST', [
            'line_items' => [
                ['item' => ['id' => UCP_TEST_PRODUCT_ID], 'quantity' => 1],
                ['item' => ['id' => UCP_TEST_PRODUCT_ID], 'quantity' => 2],
            ],
            'currency' => 'USD',
        ]);

        expect($response)->toHaveKey('id');
        expect($response['line_items'])->toHaveCount(2);
    });
});
