<?php

namespace UcpCheckout\Config;

class PluginConfig
{
    public const UCP_VERSION = '2026-01-11';
    public const API_NAMESPACE = 'ucp/v1';
    public const OPTION_KEY = 'ucp_plugin_settings';

    private static ?self $instance = null;
    private array $settings;

    private function __construct()
    {
        $this->settings = $this->loadSettings();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Load settings from WordPress options.
     */
    private function loadSettings(): array
    {
        $defaults = [
            'https_required' => true,
            'payment_handlers' => [],
            'signing_keys' => [],
        ];

        $saved = get_option(self::OPTION_KEY, []);

        return array_merge($defaults, is_array($saved) ? $saved : []);
    }

    public function getUcpVersion(): string
    {
        return self::UCP_VERSION;
    }

    public function getApiNamespace(): string
    {
        return self::API_NAMESPACE;
    }

    public function isHttpsRequired(): bool
    {
        return (bool) ($this->settings['https_required'] ?? true);
    }

    /**
     * Get configured payment handlers for the manifest.
     */
    public function getPaymentHandlers(): array
    {
        return $this->settings['payment_handlers'] ?? [];
    }

    /**
     * Get signing keys for request/response verification.
     */
    public function getSigningKeys(): array
    {
        return $this->settings['signing_keys'] ?? [];
    }

    /**
     * Get the list of supported capabilities.
     */
    public function getCapabilities(): array
    {
        return [
            [
                'name' => 'dev.ucp.shopping.checkout',
                'version' => self::UCP_VERSION,
                'spec' => 'https://ucp.dev/specification/checkout',
                'schema' => 'https://ucp.dev/schemas/shopping/checkout.json',
            ],
        ];
    }

    /**
     * Get the list of services with their transport bindings.
     */
    public function getServices(): array
    {
        $baseUrl = rest_url(self::API_NAMESPACE);

        return [
            'dev.ucp.shopping' => [
                'version' => self::UCP_VERSION,
                'spec' => 'https://ucp.dev/specification/overview',
                'rest' => [
                    'schema' => home_url('/.well-known/ucp/openapi.json'),
                    'endpoint' => rtrim($baseUrl, '/'),
                ],
            ],
        ];
    }

    /**
     * Update a setting value.
     */
    public function set(string $key, mixed $value): void
    {
        $this->settings[$key] = $value;
        update_option(self::OPTION_KEY, $this->settings);
    }

    /**
     * Get a setting value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}
