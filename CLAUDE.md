# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

WooCommerce UCP Bridge is a WordPress plugin that implements the Universal Commerce Protocol (UCP), enabling AI agents (Gemini, ChatGPT, Claude) to discover, search, and purchase WooCommerce products.

## Architecture

The plugin consists of a single class `Woo_UCP_Bridge` in `upc-plugin.php` with two main systems:

### 1. Discovery Layer (Manifest)
- Intercepts `/.well-known/ucp` requests via WordPress `init` hook
- Returns JSON manifest advertising capabilities and endpoint URLs
- Capabilities: product_search, real_time_availability, shipping_estimation, native_checkout

### 2. REST API Layer
All endpoints are under the `ucp/v1` namespace:

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/search` | GET | Search products by query param `q` |
| `/availability` | GET | Get stock/price by `sku` param |
| `/estimate` | POST | Calculate shipping/tax (requires `sku`, `zip`, `country`) |
| `/checkout` | POST | Process order (requires `sku`, `shipping`, `payment_token`) |

### Dependencies
- Requires WordPress with WooCommerce active
- Uses WooCommerce functions: `wc_get_products()`, `wc_get_product_id_by_sku()`, `wc_create_order()`, shipping calculator

## Development

### Testing Locally
The plugin requires a WordPress environment with WooCommerce. Test endpoints via:
```bash
# Manifest discovery
curl http://yoursite.local/.well-known/ucp

# Search products
curl "http://yoursite.local/wp-json/ucp/v1/search?q=shirt"

# Check availability
curl "http://yoursite.local/wp-json/ucp/v1/availability?sku=SKU123"
```

### Key Implementation Notes
- `verify_agent_request()` currently returns true - production should verify agent headers
- Checkout sets payment method to `ucp_agent` - requires corresponding payment gateway configuration
- Shipping calculation uses WooCommerce's native `WC()->shipping->calculate_shipping()`
