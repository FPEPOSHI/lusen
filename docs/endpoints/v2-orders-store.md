---
title: "Create an order"
operation_id: "v2.orders.store"
method: POST
path: "/api/v2/orders"
group: "Orders"
api_version: "v2"
authenticated: true
canonical: "https://fpeposhi.github.io/lusen/endpoints/v2-orders-store.html"
---

# Create an order

Part of the [Acme Commerce API](/lusen/index.html) documentation.

## POST /api/v2/orders

Creates an order in `pending` state. Send the `Idempotency-Key` header so a retried request cannot double-charge.

Full URL: `https://api.acme.example/api/v2/orders`

API version: `v2`.

Authentication: required (bearer token).

### Header parameters

| Name | Type | Required | Description |
| --- | --- | --- | --- |
| `Idempotency-Key` | string<uuid> | yes | A unique key per logical order. Replays return the original order. |

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
curl -X POST 'https://api.acme.example/api/v2/orders' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: 9f8c7b6a-5d4e-3f2a-1b0c-9d8e7f6a5b4c' \
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
