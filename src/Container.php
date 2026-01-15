<?php

namespace UcpCheckout;

use UcpCheckout\Checkout\CheckoutSessionRepository;
use UcpCheckout\Config\PluginConfig;
use UcpCheckout\Endpoints\AvailabilityEndpoint;
use UcpCheckout\Endpoints\CheckoutSessionCompleteEndpoint;
use UcpCheckout\Endpoints\CheckoutSessionCreateEndpoint;
use UcpCheckout\Endpoints\CheckoutSessionGetEndpoint;
use UcpCheckout\Endpoints\EstimateEndpoint;
use UcpCheckout\Endpoints\SearchEndpoint;
use UcpCheckout\Manifest\ManifestBuilder;

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
        $container->register(PluginConfig::class, function () {
            return PluginConfig::getInstance();
        });

        $container->register(ManifestBuilder::class, function (Container $c) {
            return new ManifestBuilder($c->get(PluginConfig::class));
        });

        $container->register(CheckoutSessionRepository::class, function () {
            return new CheckoutSessionRepository();
        });

        // Endpoints
        $container->register(SearchEndpoint::class, function (Container $c) {
            return new SearchEndpoint($c->get(PluginConfig::class));
        });

        $container->register(AvailabilityEndpoint::class, function (Container $c) {
            return new AvailabilityEndpoint($c->get(PluginConfig::class));
        });

        $container->register(EstimateEndpoint::class, function (Container $c) {
            return new EstimateEndpoint($c->get(PluginConfig::class));
        });

        $container->register(CheckoutSessionCreateEndpoint::class, function (Container $c) {
            return new CheckoutSessionCreateEndpoint(
                $c->get(PluginConfig::class),
                $c->get(CheckoutSessionRepository::class)
            );
        });

        $container->register(CheckoutSessionGetEndpoint::class, function (Container $c) {
            return new CheckoutSessionGetEndpoint(
                $c->get(PluginConfig::class),
                $c->get(CheckoutSessionRepository::class)
            );
        });

        $container->register(CheckoutSessionCompleteEndpoint::class, function (Container $c) {
            return new CheckoutSessionCompleteEndpoint(
                $c->get(PluginConfig::class),
                $c->get(CheckoutSessionRepository::class)
            );
        });

        return $container;
    }

    /**
     * Get all registered endpoint class names.
     */
    public function getEndpointClasses(): array
    {
        return [
            SearchEndpoint::class,
            AvailabilityEndpoint::class,
            EstimateEndpoint::class,
            CheckoutSessionCreateEndpoint::class,
            CheckoutSessionGetEndpoint::class,
            CheckoutSessionCompleteEndpoint::class,
        ];
    }
}
