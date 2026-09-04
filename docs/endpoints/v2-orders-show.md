---
title: "Retrieve an order"
operation_id: "v2.orders.show"
method: GET
path: "/api/v2/orders/{order}"
group: "Orders"
api_version: "v2"
authenticated: true
canonical: "https://fpeposhi.github.io/lusen/endpoints/v2-orders-show.html"
---

# Retrieve an order

Part of the [Acme Commerce API](/lusen/index.html) documentation.

## GET /api/v2/orders/{order}

Full URL: `https://api.acme.example/api/v2/orders/{order}`

API version: `v2`.

Authentication: required (bearer token).

### Path parameters

| Name | Type | Required | Description |
| --- | --- | --- | --- |
| `order` | integer | yes | The order id. |

### Example request

```bash
curl -X GET 'https://api.acme.example/api/v2/orders/1' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Accept: application/json'
```

### Responses

**200** — The order.

| Field | Type | Description |
| --- | --- | --- |
| `data` | object |  |
| `data.id` | integer |  |
| `data.customer_id` | integer |  |
| `data.status` | string, one of pending, paid, shipped, refunded |  |
| `data.total` | integer |  |
| `data.currency` | string |  |
| `data.items` | array |  |
| `data.items[].product_id` | integer |  |
| `data.items[].quantity` | integer |  |
| `data.items[].unit_price` | integer |  |
| `data.placed_at` | string<date-time> |  |
| `data.refunded_at` | string<date-time>, nullable |  |

**404** — No order with that id.
