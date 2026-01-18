<?php

namespace UcpCheckout;

use UcpCheckout\Admin\AdminMenu;
use UcpCheckout\Admin\DebugDashboard;
use UcpCheckout\Config\PluginConfig;
use UcpCheckout\Http\RequestLoggingMiddleware;
use UcpCheckout\Logging\LogRepository;
use UcpCheckout\Manifest\ManifestBuilder;

class Plugin
{
    private readonly Container $container;
    private readonly ManifestBuilder $manifestBuilder;

    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? Container::bootstrap();
        $this->manifestBuilder = $this->container->get(ManifestBuilder::class);
    }

    /**
     * Initialize the plugin.
     */
    public function init(): void
    {
        // Handle manifest discovery
        add_action('init', $this->handleManifest(...), 1);

        // Register REST API endpoints
        add_action('rest_api_init', $this->registerEndpoints(...));

        // Register logging middleware
        add_action('rest_api_init', $this->registerLoggingMiddleware(...));

        // Register admin menu and dashboard
        if (is_admin()) {
            $this->registerAdmin();
        }
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
     * Register the request logging middleware.
     */
    public function registerLoggingMiddleware(): void
    {
        $middleware = $this->container->get(RequestLoggingMiddleware::class);
        $middleware->register();
    }

    /**
     * Register admin menu and dashboard.
     */
    private function registerAdmin(): void
    {
        $adminMenu = $this->container->get(AdminMenu::class);
        $adminMenu->register();

        $dashboard = $this->container->get(DebugDashboard::class);
        $dashboard->registerAjaxHandlers();
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

        // Create the logs database table
        $logRepository = $this->container->get(LogRepository::class);
        $logRepository->createTable();

        // Flush rewrite rules for /.well-known/ucp
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation hook.
     */
    public function deactivate(): void
    {
        // Clean up expired checkout sessions
        $repository = $this->container->get(\UcpCheckout\Checkout\CheckoutSessionRepository::class);
        $repository->cleanupExpired();

        // Unregister the logging middleware (clears cron job)
        $middleware = $this->container->get(RequestLoggingMiddleware::class);
        $middleware->unregister();

        flush_rewrite_rules();
    }

    /**
     * Get the container instance.
     */
    public function getContainer(): Container
    {
        return $this->container;
    }
}
