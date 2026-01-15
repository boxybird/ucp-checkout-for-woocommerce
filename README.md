# UCP Checkout for WooCommerce

Enable AI agents like ChatGPT, Gemini, and Claude to discover and purchase products from your WooCommerce store using the [Universal Commerce Protocol (UCP)](https://ucp.dev).

## What is UCP?

The Universal Commerce Protocol (UCP) is an open standard that allows AI agents to interact with e-commerce stores programmatically. It provides a standardized way for AI assistants to:

- Search for products
- Check availability and pricing
- Get shipping estimates
- Complete purchases on behalf of users

Learn more at [ucp.dev](https://ucp.dev).

## Requirements

- WordPress 6.0 or higher
- WooCommerce 8.0 or higher
- PHP 8.4 or higher
- HTTPS enabled (recommended for production)

## Installation

1. Upload the `ucp-checkout-for-woocommerce` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin
3. Ensure WooCommerce is installed and activated

## How It Works

Once activated, your store becomes discoverable by UCP-compatible AI agents through the manifest endpoint at:

```
https://your-store.com/.well-known/ucp
```

This manifest advertises your store's capabilities and API endpoints to AI agents.

## API Endpoints

All endpoints are available under the `/wp-json/ucp/v1/` namespace.

### Product Search

Search your product catalog.

```
GET /wp-json/ucp/v1/search?q={query}&limit={limit}
```

**Parameters:**
- `q` (required): Search query
- `limit` (optional): Maximum results (1-20, default: 5)

**Response:**
```json
{
  "status": "success",
  "data": {
    "query": "shirt",
    "count": 2,
    "results": [
      {
        "name": "Blue T-Shirt",
        "sku": "SHIRT-001",
        "price": "29.99",
        "currency": "USD",
        "in_stock": true,
        "image": "https://...",
        "url": "https://..."
      }
    ]
  }
}
```

### Product Availability

Check real-time availability and pricing for a specific product.

```
GET /wp-json/ucp/v1/availability?sku={sku}
```

**Parameters:**
- `sku` (required): Product SKU

**Response:**
```json
{
  "status": "success",
  "data": {
    "sku": "SHIRT-001",
    "name": "Blue T-Shirt",
    "in_stock": true,
    "stock_status": "instock",
    "stock_quantity": 50,
    "price": "29.99",
    "regular_price": "34.99",
    "sale_price": "29.99",
    "currency": "USD",
    "backorders_allowed": false
  }
}
```

### Shipping Estimate

Get shipping options and cost estimates for a product.

```
POST /wp-json/ucp/v1/estimate
Content-Type: application/json

{
  "sku": "SHIRT-001",
  "quantity": 2,
  "zip": "90210",
  "country": "US",
  "state": "CA"
}
```

**Response:**
```json
{
  "status": "success",
  "data": {
    "sku": "SHIRT-001",
    "quantity": 2,
    "unit_price": 29.99,
    "subtotal": 59.98,
    "tax": 5.25,
    "currency": "USD",
    "shipping_options": [
      {
        "id": "flat_rate:1",
        "method_id": "flat_rate",
        "label": "Standard Shipping",
        "cost": 5.99,
        "currency": "USD"
      }
    ],
    "destination": {
      "country": "US",
      "state": "CA",
      "postcode": "90210"
    }
  }
}
```

### Create Checkout Session

Initialize a checkout session with items to purchase.

```
POST /wp-json/ucp/v1/checkout-sessions
Content-Type: application/json

{
  "items": [
    { "sku": "SHIRT-001", "quantity": 2 }
  ],
  "shipping": {
    "first_name": "John",
    "last_name": "Doe",
    "address": "123 Main St",
    "city": "Beverly Hills",
    "state": "CA",
    "zip": "90210",
    "country": "US"
  }
}
```

**Shorthand for single item:**
```json
{
  "sku": "SHIRT-001",
  "quantity": 1
}
```

**Response:**
```json
{
  "status": "success",
  "data": {
    "session_id": "ucp_sess_abc123...",
    "status": "pending",
    "items": [...],
    "created_at": "2026-01-14T12:00:00Z",
    "expires_at": "2026-01-14T12:30:00Z"
  }
}
```

### Get Checkout Session

Retrieve the current state of a checkout session.

```
GET /wp-json/ucp/v1/checkout-sessions/{session_id}
```

### Complete Checkout

Finalize the purchase and create a WooCommerce order.

```
POST /wp-json/ucp/v1/checkout-sessions/{session_id}/complete
Content-Type: application/json

{
  "payment_token": "tok_...",
  "payment_method": "ucp_agent",
  "shipping": {
    "first_name": "John",
    "last_name": "Doe",
    "address": "123 Main St",
    "city": "Beverly Hills",
    "state": "CA",
    "zip": "90210",
    "country": "US",
    "email": "john@example.com",
    "phone": "555-1234"
  },
  "shipping_method": "flat_rate:1"
}
```

**Response:**
```json
{
  "status": "success",
  "data": {
    "session_id": "ucp_sess_abc123...",
    "status": "completed",
    "order_id": 1234,
    "order_status": "processing",
    "total": "71.22",
    "currency": "USD"
  }
}
```

## UCP Manifest

The plugin automatically serves a UCP manifest at `/.well-known/ucp` that advertises:

- **UCP Version**: Protocol version supported
- **Services**: Available commerce services and their REST endpoints
- **Capabilities**: Supported features (product search, availability, shipping estimates, checkout)
- **Payment Handlers**: Configured payment processing options

Example manifest:
```json
{
  "ucp": {
    "version": "2026-01-01",
    "services": {
      "dev.ucp.commerce": {
        "version": "2026-01-01",
        "spec": "https://ucp.dev/services/commerce",
        "rest": {
          "schema": "https://your-store.com/.well-known/ucp/openapi.json",
          "endpoint": "https://your-store.com/wp-json/ucp/v1"
        }
      }
    },
    "capabilities": [
      {
        "name": "dev.ucp.product-search",
        "version": "2026-01-01",
        "spec": "https://ucp.dev/capabilities/product-search"
      },
      {
        "name": "dev.ucp.availability",
        "version": "2026-01-01",
        "spec": "https://ucp.dev/capabilities/availability"
      },
      {
        "name": "dev.ucp.shipping-estimate",
        "version": "2026-01-01",
        "spec": "https://ucp.dev/capabilities/shipping-estimate"
      },
      {
        "name": "dev.ucp.checkout",
        "version": "2026-01-01",
        "spec": "https://ucp.dev/capabilities/checkout"
      }
    ]
  },
  "payment": {
    "handlers": [...]
  },
  "signing_keys": [...]
}
```

## Error Handling

All error responses follow the UCP error format:

```json
{
  "status": "validation_error",
  "messages": [
    {
      "type": "error",
      "code": "invalid_sku",
      "message": "SKU is required",
      "severity": "error"
    }
  ]
}
```

**Error Status Types:**
- `error` - General error
- `validation_error` - Invalid request parameters
- `not_found` - Resource not found
- `unauthorized` - Authentication required
- `requires_escalation` - Human intervention needed

## Checkout Session States

Sessions progress through these states:

| Status | Description |
|--------|-------------|
| `pending` | Session created, awaiting completion |
| `processing` | Payment being processed |
| `completed` | Order created successfully |
| `expired` | Session timed out (30 min default) |
| `cancelled` | Session was cancelled |

## Development

### Running Tests

```bash
composer test
```

### Code Quality

```bash
composer polish
```

This runs:
1. Pest tests
2. Rector (code modernization)
3. PHPStan (static analysis)

## Resources

- [UCP Specification](https://ucp.dev)
- [UCP Developer Documentation](https://ucp.dev/docs)
- [WooCommerce REST API](https://woocommerce.github.io/woocommerce-rest-api-docs/)

## License

GPL-2.0-or-later
