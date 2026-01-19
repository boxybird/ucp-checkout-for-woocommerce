<?php

namespace UcpCheckout\ProductConfiguration;

/**
 * Aggregator service that runs all registered product configuration detectors.
 *
 * This service collects multiple detector implementations and checks products
 * against each one to determine if they require configuration that UCP cannot handle.
 */
class ProductConfigurationChecker
{
    /**
     * @param ProductConfigurationDetectorInterface[] $detectors Array of detector implementations
     */
    public function __construct(private array $detectors = [])
    {
    }

    /**
     * Check if a product requires configuration.
     *
     * Runs through all applicable detectors and returns the first match.
     * Returns null if the product can be purchased via UCP without additional configuration.
     *
     * @param \WC_Product $product The product to check
     * @return array|null Null if OK, or array with ['plugin' => '...', 'reason' => '...'] if configuration required
     */
    public function check(\WC_Product $product): ?array
    {
        foreach ($this->detectors as $detector) {
            // Skip detectors whose plugins aren't active
            if (!$detector->isApplicable()) {
                continue;
            }

            // Check if this detector finds required configuration
            if ($detector->requiresConfiguration($product)) {
                return [
                    'plugin' => $detector->getPluginName(),
                    'reason' => $detector->getRequirementReason($product),
                ];
            }
        }

        return null;
    }

    /**
     * Add a detector to the checker.
     *
     * @param ProductConfigurationDetectorInterface $detector The detector to add
     */
    public function addDetector(ProductConfigurationDetectorInterface $detector): void
    {
        $this->detectors[] = $detector;
    }

    /**
     * Get all registered detectors.
     *
     * @return ProductConfigurationDetectorInterface[]
     */
    public function getDetectors(): array
    {
        return $this->detectors;
    }
}
