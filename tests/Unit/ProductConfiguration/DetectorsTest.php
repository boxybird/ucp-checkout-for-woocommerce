<?php

/**
 * Product Configuration Detectors Unit Tests
 *
 * Tests for individual product configuration detector implementations.
 */

use UcpCheckout\ProductConfiguration\ProductConfigurationDetectorInterface;
use UcpCheckout\ProductConfiguration\Detectors\WooCommerceProductAddOnsDetector;
use UcpCheckout\ProductConfiguration\Detectors\YithProductAddOnsDetector;
use UcpCheckout\ProductConfiguration\Detectors\CompositeProductsDetector;
use UcpCheckout\ProductConfiguration\Detectors\ProductBundlesDetector;

describe('Product Configuration Detectors', function (): void {
    describe('WooCommerceProductAddOnsDetector', function (): void {
        it('implements ProductConfigurationDetectorInterface', function (): void {
            $detector = new WooCommerceProductAddOnsDetector();

            expect($detector)->toBeInstanceOf(ProductConfigurationDetectorInterface::class);
        });

        it('returns false for isApplicable when plugin is not active', function (): void {
            $detector = new WooCommerceProductAddOnsDetector();

            // Plugin is not installed in test environment
            expect($detector->isApplicable())->toBeFalse();
        });

        it('returns correct plugin name', function (): void {
            $detector = new WooCommerceProductAddOnsDetector();

            expect($detector->getPluginName())->toBe('WooCommerce Product Add-Ons');
        });

        it('returns a human-readable reason message', function (): void {
            $detector = new WooCommerceProductAddOnsDetector();
            $product = wc_get_product(UCP_TEST_PRODUCT_ID);

            $reason = $detector->getRequirementReason($product);

            expect($reason)->toBeString();
            expect($reason)->not->toBeEmpty();
        });

        it('returns false for simple product without add-ons', function (): void {
            $detector = new WooCommerceProductAddOnsDetector();
            $product = wc_get_product(UCP_TEST_PRODUCT_ID);

            // With plugin not active, should return false
            expect($detector->requiresConfiguration($product))->toBeFalse();
        });
    });

    describe('YithProductAddOnsDetector', function (): void {
        it('implements ProductConfigurationDetectorInterface', function (): void {
            $detector = new YithProductAddOnsDetector();

            expect($detector)->toBeInstanceOf(ProductConfigurationDetectorInterface::class);
        });

        it('returns false for isApplicable when plugin is not active', function (): void {
            $detector = new YithProductAddOnsDetector();

            // Plugin is not installed in test environment
            expect($detector->isApplicable())->toBeFalse();
        });

        it('returns correct plugin name', function (): void {
            $detector = new YithProductAddOnsDetector();

            expect($detector->getPluginName())->toBe('YITH WooCommerce Product Add-Ons');
        });

        it('returns a human-readable reason message', function (): void {
            $detector = new YithProductAddOnsDetector();
            $product = wc_get_product(UCP_TEST_PRODUCT_ID);

            $reason = $detector->getRequirementReason($product);

            expect($reason)->toBeString();
            expect($reason)->not->toBeEmpty();
        });
    });

    describe('CompositeProductsDetector', function (): void {
        it('implements ProductConfigurationDetectorInterface', function (): void {
            $detector = new CompositeProductsDetector();

            expect($detector)->toBeInstanceOf(ProductConfigurationDetectorInterface::class);
        });

        it('returns false for isApplicable when plugin is not active', function (): void {
            $detector = new CompositeProductsDetector();

            // Plugin is not installed in test environment
            expect($detector->isApplicable())->toBeFalse();
        });

        it('returns correct plugin name', function (): void {
            $detector = new CompositeProductsDetector();

            expect($detector->getPluginName())->toBe('WooCommerce Composite Products');
        });

        it('returns a human-readable reason message', function (): void {
            $detector = new CompositeProductsDetector();
            $product = wc_get_product(UCP_TEST_PRODUCT_ID);

            $reason = $detector->getRequirementReason($product);

            expect($reason)->toBeString();
            expect($reason)->not->toBeEmpty();
            expect($reason)->toContain('composite');
        });

        it('returns false for simple product', function (): void {
            $detector = new CompositeProductsDetector();
            $product = wc_get_product(UCP_TEST_PRODUCT_ID);

            // Simple products are not composite
            expect($detector->requiresConfiguration($product))->toBeFalse();
        });
    });

    describe('ProductBundlesDetector', function (): void {
        it('implements ProductConfigurationDetectorInterface', function (): void {
            $detector = new ProductBundlesDetector();

            expect($detector)->toBeInstanceOf(ProductConfigurationDetectorInterface::class);
        });

        it('returns false for isApplicable when plugin is not active', function (): void {
            $detector = new ProductBundlesDetector();

            // Plugin is not installed in test environment
            expect($detector->isApplicable())->toBeFalse();
        });

        it('returns correct plugin name', function (): void {
            $detector = new ProductBundlesDetector();

            expect($detector->getPluginName())->toBe('WooCommerce Product Bundles');
        });

        it('returns a human-readable reason message', function (): void {
            $detector = new ProductBundlesDetector();
            $product = wc_get_product(UCP_TEST_PRODUCT_ID);

            $reason = $detector->getRequirementReason($product);

            expect($reason)->toBeString();
            expect($reason)->not->toBeEmpty();
            expect($reason)->toContain('bundle');
        });

        it('returns false for simple product', function (): void {
            $detector = new ProductBundlesDetector();
            $product = wc_get_product(UCP_TEST_PRODUCT_ID);

            // Simple products are not bundles
            expect($detector->requiresConfiguration($product))->toBeFalse();
        });
    });

    describe('All Detectors', function (): void {
        it('all detectors return boolean from isApplicable', function (): void {
            $detectors = [
                new WooCommerceProductAddOnsDetector(),
                new YithProductAddOnsDetector(),
                new CompositeProductsDetector(),
                new ProductBundlesDetector(),
            ];

            foreach ($detectors as $detector) {
                expect($detector->isApplicable())->toBeBool();
            }
        });

        it('all detectors return boolean from requiresConfiguration', function (): void {
            $detectors = [
                new WooCommerceProductAddOnsDetector(),
                new YithProductAddOnsDetector(),
                new CompositeProductsDetector(),
                new ProductBundlesDetector(),
            ];

            $product = wc_get_product(UCP_TEST_PRODUCT_ID);

            foreach ($detectors as $detector) {
                expect($detector->requiresConfiguration($product))->toBeBool();
            }
        });

        it('all detectors return non-empty plugin names', function (): void {
            $detectors = [
                new WooCommerceProductAddOnsDetector(),
                new YithProductAddOnsDetector(),
                new CompositeProductsDetector(),
                new ProductBundlesDetector(),
            ];

            foreach ($detectors as $detector) {
                expect($detector->getPluginName())->toBeString();
                expect($detector->getPluginName())->not->toBeEmpty();
            }
        });
    });
});
