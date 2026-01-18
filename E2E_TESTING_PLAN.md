# End-to-End Testing Plan

## Overview

This document outlines a plan to create a live WooCommerce test site for real end-to-end testing of the UCP Checkout plugin. Mocking WooCommerce's complex ecosystem (shipping zones, payment gateways, tax calculations, inventory) is fragile and may not reflect real-world behavior.

## Test Site Requirements

### Hosting
- Any hosted WooCommerce site (shared hosting, VPS, managed WP hosting)
- HTTPS enabled (required for payment gateways)
- Publicly accessible URL

### WooCommerce Configuration

**Products (5-10 test products):**
- Simple products with varying prices
- At least one product with stock management enabled (limited quantity)
- At least one product with different tax class
- Products with images

**Shipping:**
- Multiple shipping zones configured
- At least 2 shipping methods (e.g., Flat Rate, Free Shipping threshold)
- International shipping zone for address-based testing

**Tax:**
- Tax calculations enabled
- Tax rates configured for at least one region (e.g., US states)

**Payment Gateways (in test/sandbox mode):**
- Stripe (test mode) - uses test API keys
- PayPal (sandbox) - optional second gateway
- Test card numbers work without real charges

### UCP Plugin
- This plugin installed and activated
- Manifest accessible at `/.well-known/ucp`

---

## Test Scenarios

### 1. Manifest Discovery
```bash
curl https://ucp-plugin.test/.well-known/ucp | jq .
```
**Verify:**
- UCP version is `2026-01-11`
- Capability `dev.ucp.shopping.checkout` is listed
- Payment handlers reflect configured WC gateways
- REST endpoint URL is correct

### 2. Create Checkout Session
```bash
curl -X POST "https://ucp-plugin.test/wp-json/ucp/v1/checkout-sessions" \
  -H "Content-Type: application/json" \
  -d '{
    "line_items": [
      {"item": {"id": "PRODUCT_ID"}, "quantity": 2}
    ],
    "currency": "USD"
  }' | jq .
```
**Verify:**
- Session created with status `incomplete`
- Line items include product title, unit_price (in cents), image
- Totals include subtotal
- Payment handlers listed
- Session ID returned

### 3. Update Session with Shipping Address
```bash
curl -X PUT "https://ucp-plugin.test/wp-json/ucp/v1/checkout-sessions/{SESSION_ID}" \
  -H "Content-Type: application/json" \
  -d '{
    "buyer": {
      "shipping_address": {
        "first_name": "Test",
        "last_name": "User",
        "street_address": "123 Test St",
        "address_locality": "Los Angeles",
        "address_region": "CA",
        "postal_code": "90210",
        "address_country": "US"
      }
    }
  }' | jq .
```
**Verify:**
- Shipping options returned in `fulfillment.options`
- Shipping cost calculated and added to totals
- Tax calculated based on address
- Total reflects subtotal + shipping + tax

### 4. Select Shipping Method
```bash
curl -X PUT "https://ucp-plugin.test/wp-json/ucp/v1/checkout-sessions/{SESSION_ID}" \
  -H "Content-Type: application/json" \
  -d '{
    "fulfillment": {
      "shipping_method": "flat_rate:1"
    }
  }' | jq .
```
**Verify:**
- Selected method reflected in response
- Totals updated with correct shipping cost

### 5. Complete Checkout (Stripe Test Mode)
```bash
curl -X POST "https://ucp-plugin.test/wp-json/ucp/v1/checkout-sessions/{SESSION_ID}/complete" \
  -H "Content-Type: application/json" \
  -d '{
    "payment_data": {
      "handler_id": "stripe",
      "credential": {
        "token": "tok_visa"
      }
    },
    "buyer": {
      "shipping_address": {
        "first_name": "Test",
        "last_name": "User",
        "street_address": "123 Test St",
        "address_locality": "Los Angeles",
        "address_region": "CA",
        "postal_code": "90210",
        "address_country": "US",
        "email": "test@example.com",
        "phone": "555-1234"
      }
    }
  }' | jq .
```
**Verify:**
- Status changes to `completed`
- Order ID returned
- Order visible in WooCommerce admin
- Payment processed in Stripe dashboard (test mode)
- Stock reduced for purchased products
- Order confirmation email sent

### 6. Cancel Session
```bash
curl -X POST "https://ucp-plugin.test/wp-json/ucp/v1/checkout-sessions/{SESSION_ID}/cancel" | jq .
```
**Verify:**
- Status changes to `canceled`
- Session cannot be completed after cancellation

### 7. Error Scenarios
- Create session with non-existent product ID
- Create session with out-of-stock product
- Complete session with invalid payment token
- Update expired session

---

## Stripe Test Cards

| Card Number | Scenario |
|-------------|----------|
| 4242424242424242 | Successful payment |
| 4000000000000002 | Card declined |
| 4000000000009995 | Insufficient funds |
| 4000000000000069 | Expired card |

Use any future expiry date and any 3-digit CVC.

---

## Verification Checklist

After completing test scenarios, verify in WooCommerce admin:

- [ ] Orders appear in WooCommerce > Orders
- [ ] Order has correct line items and totals
- [ ] Order has correct shipping address
- [ ] Order has `_ucp_session_id` and `_ucp_agent_checkout` meta
- [ ] Payment shows in Stripe dashboard (test mode)
- [ ] Product stock reduced correctly
- [ ] Order confirmation email received

---

## Future Automation

Once the test site is set up, these tests could be automated using:
- A shell script that runs all curl commands in sequence
- Pest/PHPUnit integration tests that make HTTP requests
- A dedicated test runner that reports pass/fail for each scenario

---

## Notes

- Always use payment gateways in **test/sandbox mode** to avoid real charges
- Consider using a subdomain like `ucp-test.yoursite.com`
- Keep test products simple but representative of real use cases
- Document the product IDs and shipping method IDs for test scripts
