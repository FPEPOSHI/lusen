---
title: "Delete a customer"
operation_id: "v2.customers.destroy"
method: DELETE
path: "/api/v2/customers/{customer}"
group: "Customers"
api_version: "v2"
authenticated: true
canonical: "https://fpeposhi.github.io/lusen/endpoints/v2-customers-destroy.html"
---

# Delete a customer

Part of the [Acme Commerce API](/lusen/index.html) documentation.

## DELETE /api/v2/customers/{customer}

Permanently removes the customer and anonymises their orders. Prefer archiving via the update endpoint.

Full URL: `https://api.acme.example/api/v2/customers/{customer}`

API version: `v2`.

Authentication: required (bearer token).

### Path parameters

| Name | Type | Required | Description |
| --- | --- | --- | --- |
| `customer` | integer | yes | The customer id. |

### Example request

```bash
curl -X DELETE 'https://api.acme.example/api/v2/customers/1' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Accept: application/json'
```

### Responses

**204** — Deleted.

**409** — The customer has an open order and cannot be deleted.
