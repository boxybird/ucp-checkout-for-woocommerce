<?php

namespace UcpPlugin;

use UcpPlugin\Config\PluginConfig;
use UcpPlugin\Manifest\ManifestBuilder;

class Plugin
{
    private Container $container;
    private PluginConfig $config;
    private ManifestBuilder $manifestBuilder;

    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? Container::bootstrap();
        $this->config = $this->container->get(PluginConfig::class);
        $this->manifestBuilder = $this->container->get(ManifestBuilder::class);
    }

    /**
     * Initialize the plugin.
     */
    public function init(): void
    {
        // Handle manifest discovery
        add_action('init', [$this, 'handleManifest'], 1);

        // Register REST API endpoints
        add_action('rest_api_init', [$this, 'registerEndpoints']);

        // Register activation/deactivation hooks
        register_activation_hook($this->getPluginFile(), [$this, 'activate']);
        register_deactivation_hook($this->getPluginFile(), [$this, 'deactivate']);
    }

    /**
     * Handle manifest discovery request.
     */
    public function handleManifest(): void
    {
        if (!ManifestBuilder::isManifestRequest()) {
            return;
        }

        $this->manifestBuilder->output();
        exit;
    }

    /**
     * Register all REST API endpoints.
     */
    public function registerEndpoints(): void
    {
        foreach ($this->container->getEndpointClasses() as $endpointClass) {
            $endpoint = $this->container->get($endpointClass);
            $endpoint->register();
        }
    }

    /**
     * Plugin activation hook.
     */
    public function activate(): void
    {
        // Ensure WooCommerce is active
        if (!class_exists('WooCommerce')) {
            deactivate_plugins(plugin_basename($this->getPluginFile()));
            wp_die(
                'UCP Checkout for WooCommerce requires WooCommerce to be installed and active.',
                'Plugin Activation Error',
                ['back_link' => true]
            );
        }

        // Initialize default settings if not present
        if (!get_option(PluginConfig::OPTION_KEY)) {
            update_option(PluginConfig::OPTION_KEY, [
                'https_required' => true,
                'payment_handlers' => [],
                'signing_keys' => [],
            ]);
        }

        // Flush rewrite rules for /.well-known/ucp
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation hook.
     */
    public function deactivate(): void
    {
        // Clean up expired checkout sessions
        $repository = $this->container->get(\UcpPlugin\Checkout\CheckoutSessionRepository::class);
        $repository->cleanupExpired();

        flush_rewrite_rules();
    }

    /**
     * Get the main plugin file path.
     */
    private function getPluginFile(): string
    {
        return dirname(__DIR__) . '/ucp-checkout.php';
    }

    /**
     * Get the container instance.
     */
    public function getContainer(): Container
    {
        return $this->container;
    }
}
