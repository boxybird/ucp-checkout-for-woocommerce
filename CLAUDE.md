# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**UCP Checkout for WooCommerce** is a WordPress plugin implementing the Universal Commerce Protocol (UCP), enabling AI agents (ChatGPT, Gemini, Claude) to discover, search, and purchase products from WooCommerce stores.

## Architecture

### Directory Structure
```
├── composer.json              # PSR-4 autoloading
├── ucp-checkout.php           # WordPress plugin bootstrap
└── src/
    ├── Plugin.php             # Main plugin class (entry point)
    ├── Container.php          # Dependency injection container
    ├── Config/
    │   └── PluginConfig.php   # Configuration singleton
    ├── Http/
    │   ├── UcpResponse.php    # Response wrapper with UCP envelope
    │   └── ErrorHandler.php   # UCP-compliant error responses
    ├── Manifest/
    │   └── ManifestBuilder.php # Builds /.well-known/ucp manifest
    ├── Checkout/
    │   ├── CheckoutSession.php           # Session model
    │   └── CheckoutSessionRepository.php # Session storage (transients)
    └── Endpoints/
        ├── AbstractEndpoint.php              # Base class for all endpoints
        ├── SearchEndpoint.php                # GET /search
        ├── AvailabilityEndpoint.php          # GET /availability
        ├── EstimateEndpoint.php              # POST /estimate
        ├── CheckoutSessionCreateEndpoint.php # POST /checkout-sessions
        ├── CheckoutSessionGetEndpoint.php    # GET /checkout-sessions/{id}
        └── CheckoutSessionCompleteEndpoint.php # POST /checkout-sessions/{id}/complete
```

### Namespace
- Root: `UcpCheckout`
- PSR-4 autoloading maps `UcpCheckout\` to `src/`

### Key Components

**`Plugin`** - Main entry point, registers hooks and endpoints
**`Container`** - Simple DI container, bootstraps all services
**`PluginConfig`** - Singleton with UCP_VERSION, capabilities, services config
**`AbstractEndpoint`** - Base class providing validation, auth, response helpers

### Adding New Endpoints

1. Create class extending `AbstractEndpoint` in `src/Endpoints/`
2. Implement `getRoute()`, `getMethods()`, `handle()`
3. Register in `Container::bootstrap()` and `Container::getEndpointClasses()`

```php
class MyEndpoint extends AbstractEndpoint
{
    public function getRoute(): string { return '/my-route'; }
    public function getMethods(): string { return 'GET'; }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $errors = $this->validateRequired($request->get_params(), ['field']);
        if (!empty($errors)) {
            return $this->validationError($errors);
        }
        return $this->success(['key' => 'value']);
    }
}
```

## REST API Endpoints

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/search` | GET | Search products (`?q=query&limit=5`) |
| `/availability` | GET | Stock/price by SKU (`?sku=XXX`) |
| `/estimate` | POST | Shipping/tax estimate |
| `/checkout-sessions` | POST | Create checkout session |
| `/checkout-sessions/{id}` | GET | Get session status |
| `/checkout-sessions/{id}/complete` | POST | Complete checkout |

## UCP Protocol Compliance

**Manifest** (/.well-known/ucp):
```json
{
  "ucp": { "version": "2026-01-01", "services": {...}, "capabilities": [...] },
  "payment": { "handlers": [...] },
  "signing_keys": [...]
}
```

**Responses** - All wrapped with UCP envelope:
```json
{ "ucp": { "version": "...", "capabilities": [...] }, "data": {...} }
```

**Errors**:
```json
{ "status": "validation_error", "messages": [{ "type": "error", "code": "...", "message": "..." }] }
```

## Development

### Setup
```bash
composer install
```

### Testing
```bash
# Manifest
curl http://yoursite.local/.well-known/ucp | jq .

# Search
curl "http://yoursite.local/wp-json/ucp/v1/search?q=shirt" | jq .

# Create checkout session
curl -X POST "http://yoursite.local/wp-json/ucp/v1/checkout-sessions" \
  -H "Content-Type: application/json" \
  -d '{"sku": "TEST123", "quantity": 1}' | jq .

# Complete checkout
curl -X POST "http://yoursite.local/wp-json/ucp/v1/checkout-sessions/{id}/complete" \
  -H "Content-Type: application/json" \
  -d '{"payment_token": "tok_xxx", "shipping": {...}}' | jq .
```

### Dependencies
- WordPress 6.0+
- WooCommerce 8.0+
- PHP 8.0+
