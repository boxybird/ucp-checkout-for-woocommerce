<?php

namespace UcpCheckout;

use UcpCheckout\Checkout\CheckoutSessionRepository;
use UcpCheckout\Config\PluginConfig;
use UcpCheckout\Endpoints\CheckoutSessionCancelEndpoint;
use UcpCheckout\Endpoints\CheckoutSessionCompleteEndpoint;
use UcpCheckout\Endpoints\CheckoutSessionCreateEndpoint;
use UcpCheckout\Endpoints\CheckoutSessionGetEndpoint;
use UcpCheckout\Endpoints\CheckoutSessionUpdateEndpoint;
use UcpCheckout\Manifest\ManifestBuilder;
use UcpCheckout\WooCommerce\PaymentGatewayAdapter;
use UcpCheckout\WooCommerce\ShippingCalculator;
use UcpCheckout\WooCommerce\TaxCalculator;
use UcpCheckout\WooCommerce\WooCommerceService;

class Container
{
    private array $instances = [];
    private array $factories = [];

    /**
     * Register a factory for a service.
     */
    public function register(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    /**
     * Get a service instance.
     */
    public function get(string $id): mixed
    {
        if (!isset($this->instances[$id])) {
            if (!isset($this->factories[$id])) {
                throw new \RuntimeException("Service not found: {$id}");
            }
            $this->instances[$id] = $this->factories[$id]($this);
        }

        return $this->instances[$id];
    }

    /**
     * Check if a service is registered.
     */
    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || isset($this->instances[$id]);
    }

    /**
     * Create a container with all services registered.
     */
    public static function bootstrap(): self
    {
        $container = new self();

        // Core services
        $container->register(PluginConfig::class, fn() => PluginConfig::getInstance());

        // WooCommerce integration services
        $container->register(TaxCalculator::class, fn() => new TaxCalculator());
        $container->register(ShippingCalculator::class, fn() => new ShippingCalculator());
        $container->register(PaymentGatewayAdapter::class, fn() => new PaymentGatewayAdapter());
        $container->register(WooCommerceService::class, fn(Container $c) => new WooCommerceService(
            $c->get(TaxCalculator::class),
            $c->get(ShippingCalculator::class),
            $c->get(PaymentGatewayAdapter::class)
        ));

        $container->register(ManifestBuilder::class, fn(Container $c) => new ManifestBuilder(
            $c->get(PluginConfig::class),
            $c->get(WooCommerceService::class)
        ));

        $container->register(CheckoutSessionRepository::class, fn() => new CheckoutSessionRepository());

        // Checkout Session Endpoints
        $container->register(CheckoutSessionCreateEndpoint::class, fn(Container $c) => new CheckoutSessionCreateEndpoint(
            $c->get(PluginConfig::class),
            $c->get(CheckoutSessionRepository::class)
        ));

        $container->register(CheckoutSessionGetEndpoint::class, fn(Container $c) => new CheckoutSessionGetEndpoint(
            $c->get(PluginConfig::class),
            $c->get(CheckoutSessionRepository::class)
        ));

        $container->register(CheckoutSessionUpdateEndpoint::class, fn(Container $c) => new CheckoutSessionUpdateEndpoint(
            $c->get(PluginConfig::class),
            $c->get(CheckoutSessionRepository::class),
            $c->get(WooCommerceService::class)
        ));

        $container->register(CheckoutSessionCompleteEndpoint::class, fn(Container $c) => new CheckoutSessionCompleteEndpoint(
            $c->get(PluginConfig::class),
            $c->get(CheckoutSessionRepository::class),
            $c->get(WooCommerceService::class)
        ));

        $container->register(CheckoutSessionCancelEndpoint::class, fn(Container $c) => new CheckoutSessionCancelEndpoint(
            $c->get(PluginConfig::class),
            $c->get(CheckoutSessionRepository::class)
        ));

        return $container;
    }

    /**
     * Get all registered endpoint class names.
     */
    public function getEndpointClasses(): array
    {
        return [
            CheckoutSessionCreateEndpoint::class,
            CheckoutSessionGetEndpoint::class,
            CheckoutSessionUpdateEndpoint::class,
            CheckoutSessionCompleteEndpoint::class,
            CheckoutSessionCancelEndpoint::class,
        ];
    }
}
