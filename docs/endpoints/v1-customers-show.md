---
title: "Retrieve a customer"
operation_id: "v1.customers.show"
method: GET
path: "/api/v1/customers/{customer}"
group: "Customers"
api_version: "v1"
authenticated: true
deprecated: true
superseded_by: "v2.customers.show"
canonical: "https://fpeposhi.github.io/lusen/endpoints/v1-customers-show.html"
---

# Retrieve a customer

Part of the [Acme Commerce API](/lusen/index.html) documentation.

## GET /api/v1/customers/{customer}

**Deprecated.**

**A newer version of this operation exists**: [`GET /api/v2/customers/{customer}`](/lusen/endpoints/v2-customers-show.md).

Full URL: `https://api.acme.example/api/v1/customers/{customer}`

API version: `v1`.

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
curl -X GET 'https://api.acme.example/api/v1/customers/1' \
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
