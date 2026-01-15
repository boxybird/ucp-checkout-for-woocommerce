<?php

namespace UcpCheckout\Manifest;

use UcpCheckout\Config\PluginConfig;
use UcpCheckout\WooCommerce\WooCommerceService;

class ManifestBuilder
{
    private readonly PluginConfig $config;

    public function __construct(?PluginConfig $config = null, private readonly ?WooCommerceService $wcService = new WooCommerceService())
    {
        $this->config = $config ?? PluginConfig::getInstance();
    }

    /**
     * Build the complete UCP-compliant manifest.
     */
    public function build(): array
    {
        return [
            'ucp' => [
                'version' => $this->config->getUcpVersion(),
                'services' => $this->config->getServices(),
                'capabilities' => $this->config->getCapabilities(),
            ],
            'payment' => [
                'handlers' => $this->buildPaymentHandlers(),
            ],
            'signing_keys' => $this->config->getSigningKeys(),
        ];
    }

    /**
     * Build payment handlers array per UCP spec.
     * Dynamically builds from available WooCommerce payment gateways.
     */
    private function buildPaymentHandlers(): array
    {
        // First check for explicitly configured handlers
        $handlers = $this->config->getPaymentHandlers();
        if (!empty($handlers)) {
            return $handlers;
        }

        // Build handlers dynamically from WooCommerce gateways
        return $this->wcService->buildPaymentHandlersForManifest();
    }

    /**
     * Output the manifest as JSON response.
     */
    public function output(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=3600');

        echo json_encode($this->build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Check if the current request is for the UCP manifest.
     */
    public static function isManifestRequest(): bool
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';

        // Exact match for /.well-known/ucp (with optional trailing slash or query string)
        return (bool) preg_match('#^/\.well-known/ucp/?(\?.*)?$#', (string) $requestUri);
    }
}
