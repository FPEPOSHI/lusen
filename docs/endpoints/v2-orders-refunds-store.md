---
title: "Refund an order"
operation_id: "v2.orders.refunds.store"
method: POST
path: "/api/v2/orders/{order}/refunds"
group: "Orders"
api_version: "v2"
authenticated: true
canonical: "https://fpeposhi.github.io/lusen/endpoints/v2-orders-refunds-store.html"
---

# Refund an order

Part of the [Acme Commerce API](/lusen/index.html) documentation.

## POST /api/v2/orders/{order}/refunds

Refunds all or part of a paid order. Partial refunds may be issued repeatedly up to the order total.

Full URL: `https://api.acme.example/api/v2/orders/{order}/refunds`

API version: `v2`.

Authentication: required (bearer token).

### Path parameters

| Name | Type | Required | Description |
| --- | --- | --- | --- |
| `order` | integer | yes | The order id. |

### Body parameters

| Name | Type | Required | Description |
| --- | --- | --- | --- |
| `amount` | integer | no | Minor units. Omit to refund the full remaining balance. |
| `reason` | string, one of requested_by_customer, duplicate, fraudulent | no |  |

### Example request

```bash
curl -X POST 'https://api.acme.example/api/v2/orders/1/refunds' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{
    "amount": 4200,
    "reason": "requested_by_customer"
}'
```

### Responses

**201** — The refund.

```json
{
    "id": "rfnd_91a",
    "order_id": 8801,
    "amount": 4200,
    "status": "succeeded"
}
```

**409** — The order is not in a refundable state.
