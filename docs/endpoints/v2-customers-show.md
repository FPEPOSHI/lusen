---
title: "Retrieve a customer"
operation_id: "v2.customers.show"
method: GET
path: "/api/v2/customers/{customer}"
group: "Customers"
api_version: "v2"
authenticated: true
canonical: "https://fpeposhi.github.io/lusen/endpoints/v2-customers-show.html"
---

# Retrieve a customer

Part of the [Acme Commerce API](/lusen/index.html) documentation.

## GET /api/v2/customers/{customer}

Full URL: `https://api.acme.example/api/v2/customers/{customer}`

API version: `v2`.

Authentication: required (bearer token).

### Path parameters

| Name | Type | Required | Description |
| --- | --- | --- | --- |
| `customer` | integer | yes | The customer id. |

### Query parameters

| Name | Type | Required | Description |
| --- | --- | --- | --- |
| `include` | string, one of orders, addresses | no | Embed a related collection in the response. |

### Example request

```bash
curl -X GET 'https://api.acme.example/api/v2/customers/1' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Accept: application/json'
```

### Responses

**200** — The customer.

| Field | Type | Description |
| --- | --- | --- |
| `id` | integer |  |
| `email` | string<email> |  |
| `name` | string |  |
| `status` | string, one of active, invited, archived |  |
| `created_at` | string<date-time> |  |

**404** — No customer with that id.
