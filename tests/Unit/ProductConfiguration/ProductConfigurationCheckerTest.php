<?php

/**
 * ProductConfigurationChecker Unit Tests
 *
 * Tests for the product configuration checker aggregator service.
 */

use UcpCheckout\ProductConfiguration\ProductConfigurationChecker;

describe('ProductConfigurationChecker', function (): void {
    describe('Aggregator Behavior', function (): void {
        it('returns null when no detectors are registered', function (): void {
            $checker = new ProductConfigurationChecker([]);
            $product = wc_get_product(UCP_TEST_PRODUCT_ID);

            $result = $checker->check($product);

            expect($result)->toBeNull();
        });

        it('returns null when no detector finds required configuration', function (): void {
            $detector = createMockDetector(
                isApplicable: true,
                requiresConfiguration: false
            );

            $checker = new ProductConfigurationChecker([$detector]);
            $product = wc_get_product(UCP_TEST_PRODUCT_ID);

            $result = $checker->check($product);

            expect($result)->toBeNull();
        });

        it('returns configuration info when detector finds required configuration', function (): void {
            $detector = createMockDetector(
                isApplicable: true,
                requiresConfiguration: true,
                pluginName: 'Test Plugin',
                reason: 'Test reason message'
            );

            $checker = new ProductConfigurationChecker([$detector]);
            $product = wc_get_product(UCP_TEST_PRODUCT_ID);

            $result = $checker->check($product);

            expect($result)->toBeArray();
            expect($result['plugin'])->toBe('Test Plugin');
            expect($result['reason'])->toBe('Test reason message');
        });

        it('skips detectors where plugin is not applicable', function (): void {
            $inapplicableDetector = createMockDetector(
                isApplicable: false,
                requiresConfiguration: true,
                pluginName: 'Should Not Appear'
            );

            $checker = new ProductConfigurationChecker([$inapplicableDetector]);
            $product = wc_get_product(UCP_TEST_PRODUCT_ID);

            $result = $checker->check($product);

            expect($result)->toBeNull();
        });

        it('returns first matching detector result', function (): void {
            $firstDetector = createMockDetector(
                isApplicable: true,
                requiresConfiguration: true,
                pluginName: 'First Plugin',
                reason: 'First reason'
            );

            $secondDetector = createMockDetector(
                isApplicable: true,
                requiresConfiguration: true,
                pluginName: 'Second Plugin',
                reason: 'Second reason'
            );

            $checker = new ProductConfigurationChecker([$firstDetector, $secondDetector]);
            $product = wc_get_product(UCP_TEST_PRODUCT_ID);

            $result = $checker->check($product);

            expect($result['plugin'])->toBe('First Plugin');
            expect($result['reason'])->toBe('First reason');
        });

        it('continues to next detector when first does not match', function (): void {
            $nonMatchingDetector = createMockDetector(
                isApplicable: true,
                requiresConfiguration: false
            );

            $matchingDetector = createMockDetector(
                isApplicable: true,
                requiresConfiguration: true,
                pluginName: 'Matching Plugin',
                reason: 'Matching reason'
            );

            $checker = new ProductConfigurationChecker([$nonMatchingDetector, $matchingDetector]);
            $product = wc_get_product(UCP_TEST_PRODUCT_ID);

            $result = $checker->check($product);

            expect($result['plugin'])->toBe('Matching Plugin');
        });
    });

    describe('Detector Management', function (): void {
        it('can add detectors after construction', function (): void {
            $checker = new ProductConfigurationChecker([]);
            expect($checker->getDetectors())->toHaveCount(0);

            $detector = createMockDetector(isApplicable: true, requiresConfiguration: false);
            $checker->addDetector($detector);

            expect($checker->getDetectors())->toHaveCount(1);
        });

        it('returns all registered detectors', function (): void {
            $detector1 = createMockDetector(isApplicable: true, requiresConfiguration: false);
            $detector2 = createMockDetector(isApplicable: true, requiresConfiguration: false);

            $checker = new ProductConfigurationChecker([$detector1, $detector2]);

            expect($checker->getDetectors())->toHaveCount(2);
        });
    });
});
