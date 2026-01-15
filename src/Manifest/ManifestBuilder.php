<?php

namespace UcpPlugin\Manifest;

use UcpPlugin\Config\PluginConfig;

class ManifestBuilder
{
    private PluginConfig $config;

    public function __construct(?PluginConfig $config = null)
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
     * Build payment handlers array.
     * Returns configured handlers or a placeholder structure.
     */
    private function buildPaymentHandlers(): array
    {
        $handlers = $this->config->getPaymentHandlers();

        if (!empty($handlers)) {
            return $handlers;
        }

        // Return placeholder indicating payment handler configuration needed
        return [
            [
                'id' => 'ucp_agent',
                'name' => 'UCP Agent Payment',
                'version' => $this->config->getUcpVersion(),
                'spec' => 'https://ucp.dev/payment-handlers/agent',
                'config' => [
                    'supported_methods' => ['card', 'wallet'],
                ],
            ],
        ];
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
        return (bool) preg_match('#^/\.well-known/ucp/?(\?.*)?$#', $requestUri);
    }
}
