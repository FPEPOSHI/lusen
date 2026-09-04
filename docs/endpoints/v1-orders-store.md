---
title: "Create an order"
operation_id: "v1.orders.store"
method: POST
path: "/api/v1/orders"
group: "Orders"
api_version: "v1"
authenticated: true
deprecated: true
superseded_by: "v2.orders.store"
canonical: "https://fpeposhi.github.io/lusen/endpoints/v1-orders-store.html"
---

# Create an order

Part of the [Acme Commerce API](/lusen/index.html) documentation.

## POST /api/v1/orders

Creates an order in `pending` state. A retried request creates a second order; v2 accepts an `Idempotency-Key` header that makes the retry safe.

**Deprecated.**

**A newer version of this operation exists**: [`POST /api/v2/orders`](/lusen/endpoints/v2-orders-store.md).

Full URL: `https://api.acme.example/api/v1/orders`

API version: `v1`.

Authentication: required (bearer token).

### Body parameters

| Name | Type | Required | Description |
| --- | --- | --- | --- |
| `customer_id` | integer | yes | Who the order is for. |
| `currency` | string, one of USD, EUR, GBP | yes |  |
| `items` | array | yes | At least one line item. |
| `items[].product_id` | integer | no |  |
| `items[].quantity` | integer | no |  |

### Example request

```bash
curl -X POST 'https://api.acme.example/api/v1/orders' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{
    "customer_id": 1,
    "currency": "USD",
    "items": [
        {
            "product_id": 1,
            "quantity": 2
        }
    ]
}'
```

### Responses

**201** — The created order.

```json
{
    "id": 8802,
    "status": "pending",
    "total": 3998,
    "currency": "USD",
    "items": [
        {
            "product_id": 12,
            "quantity": 2,
            "unit_price": 1999
        }
    ]
}
```

**422** — Validation failed.

**429** — Too many orders created. Back off and retry after the interval in the Retry-After header.
