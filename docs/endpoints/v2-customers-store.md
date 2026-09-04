---
title: "Create a customer"
operation_id: "v2.customers.store"
method: POST
path: "/api/v2/customers"
group: "Customers"
api_version: "v2"
authenticated: true
canonical: "https://fpeposhi.github.io/lusen/endpoints/v2-customers-store.html"
---

# Create a customer

Part of the [Acme Commerce API](/lusen/index.html) documentation.

## POST /api/v2/customers

Creates a customer and, unless `send_invite` is false, emails them an invitation.

Full URL: `https://api.acme.example/api/v2/customers`

API version: `v2`.

Authentication: required (bearer token).

### Body parameters

| Name | Type | Required | Description |
| --- | --- | --- | --- |
| `email` | string<email> | yes | Must be unique across your account. |
| `name` | string, maxLength 255 | yes | Display name. |
| `send_invite` | boolean | no | Defaults to true. |
| `metadata` | object | no | Arbitrary key/value pairs echoed back on reads. |
| `metadata.plan` | string | no |  |

### Example request

```bash
curl -X POST 'https://api.acme.example/api/v2/customers' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{
    "email": "jane@example.com",
    "name": "Jane Doe",
    "send_invite": true,
    "metadata": {
        "plan": "pro"
    }
}'
```

### Responses

**201** — The created customer.

| Field | Type | Description |
| --- | --- | --- |
| `id` | integer |  |
| `email` | string<email> |  |
| `name` | string |  |
| `status` | string, one of active, invited, archived |  |
| `created_at` | string<date-time> |  |

```json
{
    "id": 42,
    "email": "jane@example.com",
    "name": "Jane Doe",
    "status": "invited",
    "created_at": "2026-02-01T11:00:00Z"
}
```

**422** — Validation failed.

```json
{
    "message": "The email has already been taken.",
    "errors": {
        "email": [
            "The email has already been taken."
        ]
    }
}
```
