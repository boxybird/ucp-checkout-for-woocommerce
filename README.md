<p align="center">
  !!! Experimental - not ready for production !!!
</p>


<p align="center">
  <img src="https://ucp.dev/assets/updated-icon.svg" alt="UCP Logo" width="120" height="120">
</p>

<h1 align="center">UCP Checkout for WooCommerce</h1>

<p align="center">
  Enable AI agents like ChatGPT, Gemini, and Claude to purchase products from your WooCommerce store using the <a href="https://ucp.dev">Universal Commerce Protocol (UCP)</a>.
</p>

<p align="center">
  <img src="assets/identity.png" alt="Account linking" width="250">
  &nbsp;&nbsp;
  <img src="assets/checkout.png" alt="Order review" width="250">
  &nbsp;&nbsp;
  <img src="assets/order.png" alt="Order complete" width="250">
</p>

<p align="center">
  <em>Seamless AI-powered checkout: Account linking → Order review → Confirmation</em>
</p>

## What is UCP?

The Universal Commerce Protocol (UCP) is an open standard developed by Google and industry partners that allows AI agents to interact with e-commerce stores programmatically. It provides a standardized way for AI assistants to:

- Create and manage checkout sessions
- Complete purchases on behalf of users
- Handle payment processing securely

This plugin implements the **Checkout capability** (`dev.ucp.shopping.checkout`) from UCP specification version `2026-01-11`.

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

All endpoints are available under the `/wp-json/ucp/v1/` namespace and implement the [UCP Checkout capability](https://ucp.dev/specification/checkout/).

### Create Checkout Session

Initialize a checkout session with line items. Prices are returned in minor units (cents).

```
POST /wp-json/ucp/v1/checkout-sessions
Content-Type: application/json

{
  "line_items": [
    {
      "item": { "id": "123" },
      "quantity": 2
    }
  ],
  "currency": "USD"
}
```

**Response:**
```json
{
  "ucp": {
    "version": "2026-01-11",
    "capabilities": [{ "name": "dev.ucp.shopping.checkout", "version": "2026-01-11" }]
  },
  "id": "ucp_sess_abc123...",
  "status": "incomplete",
  "line_items": [
    {
      "item": {
        "id": "123",
        "title": "Blue T-Shirt",
        "unit_price": 2999,
        "image": "https://..."
      },
      "quantity": 2,
      "totals": [{ "type": "subtotal", "amount": 5998 }]
    }
  ],
  "currency": "USD",
  "totals": [
    { "type": "subtotal", "amount": 5998 },
    { "type": "total", "amount": 5998 }
  ],
  "payment": { "handlers": [...] },
  "links": { "privacy_policy": "...", "terms_of_service": "..." },
  "expires_at": "2026-01-14T18:00:00Z"
}
```

### Get Checkout Session

Retrieve the current state of a checkout session.

```
GET /wp-json/ucp/v1/checkout-sessions/{id}
```

### Update Checkout Session

Update line items or buyer information for an existing session.

```
PUT /wp-json/ucp/v1/checkout-sessions/{id}
Content-Type: application/json

{
  "line_items": [
    { "item": { "id": "123" }, "quantity": 3 }
  ],
  "buyer": {
    "shipping_address": {
      "first_name": "John",
      "last_name": "Doe",
      "street_address": "123 Main St",
      "address_locality": "Beverly Hills",
      "address_region": "CA",
      "postal_code": "90210",
      "address_country": "US"
    }
  }
}
```

### Complete Checkout

Finalize the purchase and create a WooCommerce order.

```
POST /wp-json/ucp/v1/checkout-sessions/{id}/complete
Content-Type: application/json

{
  "payment_data": {
    "handler_id": "ucp_agent",
    "credential": {
      "type": "token",
      "token": "tok_..."
    }
  },
  "buyer": {
    "shipping_address": {
      "first_name": "John",
      "last_name": "Doe",
      "street_address": "123 Main St",
      "address_locality": "Beverly Hills",
      "address_region": "CA",
      "postal_code": "90210",
      "address_country": "US",
      "email": "john@example.com",
      "phone": "555-1234"
    }
  }
}
```

**Response:**
```json
{
  "ucp": { "version": "2026-01-11", "capabilities": [...] },
  "id": "ucp_sess_abc123...",
  "status": "completed",
  "order": {
    "id": "1234",
    "status": "confirmed"
  },
  ...
}
```

### Cancel Checkout Session

Cancel an incomplete checkout session.

```
POST /wp-json/ucp/v1/checkout-sessions/{id}/cancel
```

**Response:**
```json
{
  "ucp": { "version": "2026-01-11", "capabilities": [...] },
  "id": "ucp_sess_abc123...",
  "status": "canceled",
  ...
}
```

## UCP Manifest

The plugin automatically serves a UCP manifest at `/.well-known/ucp` that advertises:

- **UCP Version**: Protocol version supported (`2026-01-11`)
- **Services**: Available commerce services and their REST endpoints
- **Capabilities**: Supported features (Checkout capability)
- **Payment Handlers**: Configured payment processing options with schemas

Example manifest:
```json
{
  "ucp": {
    "version": "2026-01-11",
    "services": {
      "dev.ucp.shopping": {
        "version": "2026-01-11",
        "spec": "https://ucp.dev/specification/overview",
        "rest": {
          "schema": "https://your-store.com/.well-known/ucp/openapi.json",
          "endpoint": "https://your-store.com/wp-json/ucp/v1"
        }
      }
    },
    "capabilities": [
      {
        "name": "dev.ucp.shopping.checkout",
        "version": "2026-01-11",
        "spec": "https://ucp.dev/specification/checkout",
        "schema": "https://ucp.dev/schemas/shopping/checkout.json"
      }
    ]
  },
  "payment": {
    "handlers": [
      {
        "id": "ucp_agent",
        "name": "dev.ucp.payment.agent",
        "version": "2026-01-11",
        "config_schema": "https://ucp.dev/schemas/payment/agent-config.json",
        "instrument_schemas": ["https://ucp.dev/schemas/payment/card-instrument.json"]
      }
    ]
  },
  "signing_keys": [...]
}
```

## Error Handling

All error responses follow the UCP error format with spec-compliant severity values:

```json
{
  "status": "validation_error",
  "messages": [
    {
      "type": "error",
      "code": "invalid_line_items",
      "message": "line_items array is required",
      "severity": "recoverable"
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

**Severity Values (per UCP spec):**
- `recoverable` - Platform can fix via API
- `requires_buyer_input` - Missing non-API-collectible data
- `requires_buyer_review` - Policy/regulatory authorization needed

## Checkout Session States

Sessions progress through these states (per [UCP Checkout specification](https://ucp.dev/specification/checkout/)):

| Status | Description |
|--------|-------------|
| `incomplete` | Missing required information; platform should update |
| `requires_escalation` | Needs buyer handoff via `continue_url` |
| `ready_for_complete` | All information collected; platform can finalize |
| `complete_in_progress` | Business processing the completion request |
| `completed` | Order successfully placed |
| `canceled` | Session terminated or expired |

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

- [UCP Specification Overview](https://ucp.dev/specification/overview/)
- [UCP Checkout Capability](https://ucp.dev/specification/checkout/)
- [WooCommerce REST API](https://woocommerce.github.io/woocommerce-rest-api-docs/)

## License

GPL-2.0-or-later
