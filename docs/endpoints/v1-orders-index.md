---
title: "List orders"
operation_id: "v1.orders.index"
method: GET
path: "/api/v1/orders"
group: "Orders"
api_version: "v1"
authenticated: true
deprecated: true
superseded_by: "v2.orders.index"
canonical: "https://fpeposhi.github.io/lusen/endpoints/v1-orders-index.html"
---

# List orders

Part of the [Acme Commerce API](/lusen/index.html) documentation.

## GET /api/v1/orders

**Deprecated.**

**A newer version of this operation exists**: [`GET /api/v2/orders`](/lusen/endpoints/v2-orders-index.md).

Full URL: `https://api.acme.example/api/v1/orders`

API version: `v1`.

Authentication: required (bearer token).

### Query parameters

| Name | Type | Required | Description |
| --- | --- | --- | --- |
| `per_page` | integer | no | Results per page, 1–100. |
| `page` | integer | no | Page number, 1-indexed. |
| `status` | string, one of pending, paid, shipped, refunded | no | Only orders in this state. |
| `customer_id` | integer | no | Only orders belonging to this customer. |

### Example request

```bash
curl -X GET 'https://api.acme.example/api/v1/orders' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Accept: application/json'
```

### Responses

**200** — A page of orders.

```json
{
    "data": [
        {
            "id": 8801,
            "customer_id": 1,
            "status": "paid",
            "total": 4200,
            "currency": "USD",
            "placed_at": "2026-02-03T14:20:00Z"
        }
    ],
    "meta": {
        "page": 1,
        "per_page": 25,
        "total": 1
    }
}
```
