# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**UCP Checkout for WooCommerce** is a WordPress plugin implementing the [Universal Commerce Protocol (UCP)](https://ucp.dev), enabling AI agents (ChatGPT, Gemini, Claude) to discover and purchase products from WooCommerce stores.

**UCP Version:** `2026-01-11`

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
        ├── AbstractEndpoint.php               # Base class for all endpoints
        ├── CheckoutSessionCreateEndpoint.php  # POST /checkout-sessions
        ├── CheckoutSessionGetEndpoint.php     # GET /checkout-sessions/{id}
        ├── CheckoutSessionUpdateEndpoint.php  # PUT /checkout-sessions/{id}
        ├── CheckoutSessionCompleteEndpoint.php # POST /checkout-sessions/{id}/complete
        └── CheckoutSessionCancelEndpoint.php  # POST /checkout-sessions/{id}/cancel
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
| `/checkout-sessions` | POST | Create checkout session |
| `/checkout-sessions/{id}` | GET | Get session status |
| `/checkout-sessions/{id}` | PUT | Update checkout session |
| `/checkout-sessions/{id}/complete` | POST | Complete checkout |
| `/checkout-sessions/{id}/cancel` | POST | Cancel checkout session |

## UCP Protocol Compliance

Implements [UCP Specification](https://ucp.dev/specification/overview/) version `2026-01-11`.

**Capability:** `dev.ucp.shopping.checkout`

**Manifest** (/.well-known/ucp):
```json
{
  "ucp": {
    "version": "2026-01-11",
    "services": { "dev.ucp.shopping": {...} },
    "capabilities": [{ "name": "dev.ucp.shopping.checkout", ... }]
  },
  "payment": { "handlers": [...] },
  "signing_keys": [...]
}
```

**Responses** - Data at root level alongside UCP envelope (per spec):
```json
{
  "ucp": { "version": "2026-01-11", "capabilities": [...] },
  "id": "...",
  "status": "incomplete",
  "line_items": [...],
  "totals": [...],
  "currency": "USD"
}
```

**Errors** - Severity uses UCP spec values (`recoverable`, `requires_buyer_input`, `requires_buyer_review`):
```json
{ "status": "validation_error", "messages": [{ "type": "error", "code": "...", "message": "...", "severity": "recoverable" }] }
```

**Session Status Values** (per UCP spec):
- `incomplete` - Missing required information
- `requires_escalation` - Needs buyer handoff
- `ready_for_complete` - All information collected
- `complete_in_progress` - Processing completion
- `completed` - Order placed successfully
- `canceled` - Session terminated

## Development

### Setup
```bash
composer install
```

### Testing
```bash
# Manifest
curl http://yoursite.local/.well-known/ucp | jq .

# Create checkout session (UCP spec format)
curl -X POST "http://yoursite.local/wp-json/ucp/v1/checkout-sessions" \
  -H "Content-Type: application/json" \
  -d '{"line_items": [{"item": {"id": "123"}, "quantity": 1}], "currency": "USD"}' | jq .

# Get checkout session
curl "http://yoursite.local/wp-json/ucp/v1/checkout-sessions/{id}" | jq .

# Update checkout session
curl -X PUT "http://yoursite.local/wp-json/ucp/v1/checkout-sessions/{id}" \
  -H "Content-Type: application/json" \
  -d '{"line_items": [{"item": {"id": "123"}, "quantity": 2}]}' | jq .

# Complete checkout (UCP spec format)
curl -X POST "http://yoursite.local/wp-json/ucp/v1/checkout-sessions/{id}/complete" \
  -H "Content-Type: application/json" \
  -d '{"payment_data": {"handler_id": "ucp_agent", "credential": {"token": "tok_xxx"}}, "buyer": {"shipping_address": {...}}}' | jq .

# Cancel checkout
curl -X POST "http://yoursite.local/wp-json/ucp/v1/checkout-sessions/{id}/cancel" | jq .
```

### Run Tests
```bash
composer test
```

### Dependencies
- WordPress 6.0+
- WooCommerce 8.0+
- PHP 8.0+

## Resources

- [UCP Official Site](https://ucp.dev/)
- [UCP Specification](https://ucp.dev/specification/overview/)
- [UCP Checkout Capability](https://ucp.dev/specification/checkout/)
- [UCP GitHub](https://github.com/Universal-Commerce-Protocol/ucp)
