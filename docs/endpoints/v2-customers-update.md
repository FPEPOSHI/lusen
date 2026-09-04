---
title: "Update a customer"
operation_id: "v2.customers.update"
method: PATCH
path: "/api/v2/customers/{customer}"
group: "Customers"
api_version: "v2"
authenticated: true
canonical: "https://fpeposhi.github.io/lusen/endpoints/v2-customers-update.html"
---

# Update a customer

Part of the [Acme Commerce API](/lusen/index.html) documentation.

## PATCH /api/v2/customers/{customer}

Partial update — omitted fields are left untouched.

Full URL: `https://api.acme.example/api/v2/customers/{customer}`

API version: `v2`.

Authentication: required (bearer token).

### Path parameters

| Name | Type | Required | Description |
| --- | --- | --- | --- |
| `customer` | integer | yes | The customer id. |

### Body parameters

| Name | Type | Required | Description |
| --- | --- | --- | --- |
| `name` | string, maxLength 255 | no |  |
| `status` | string, one of active, archived | no | Archiving hides the customer from list endpoints. |

### Example request

```bash
curl -X PATCH 'https://api.acme.example/api/v2/customers/1' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{
    "name": "Jane Doe",
    "status": "active"
}'
```

### Responses

**200** — The updated customer.

| Field | Type | Description |
| --- | --- | --- |
| `id` | integer |  |
| `email` | string<email> |  |
| `name` | string |  |
| `status` | string, one of active, invited, archived |  |
| `created_at` | string<date-time> |  |

**422** — Validation failed.
