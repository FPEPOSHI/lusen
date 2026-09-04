---
title: "List customers"
operation_id: "v1.customers.index"
method: GET
path: "/api/v1/customers"
group: "Customers"
api_version: "v1"
authenticated: true
deprecated: true
superseded_by: "v2.customers.index"
canonical: "https://fpeposhi.github.io/lusen/endpoints/v1-customers-index.html"
---

# List customers

Part of the [Acme Commerce API](/lusen/index.html) documentation.

## GET /api/v1/customers

Returns a paginated list of customers, newest first. Use `status` to narrow the list, and `q` to search across name and email.

**Deprecated.**

**A newer version of this operation exists**: [`GET /api/v2/customers`](/lusen/endpoints/v2-customers-index.md).

Full URL: `https://api.acme.example/api/v1/customers`

API version: `v1`.

Authentication: required (bearer token).

### Query parameters

| Name | Type | Required | Description |
| --- | --- | --- | --- |
| `per_page` | integer | no | Results per page, 1–100. |
| `page` | integer | no | Page number, 1-indexed. |
| `status` | string, one of active, invited, archived | no | Only customers in this state. |

### Example request

```bash
curl -X GET 'https://api.acme.example/api/v1/customers' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Accept: application/json'
```

### Responses

**200** — A page of customers.

| Field | Type | Description |
| --- | --- | --- |
| `data` | array |  |
| `data[].id` | integer |  |
| `data[].email` | string<email> |  |
| `data[].name` | string |  |
| `data[].status` | string, one of active, invited, archived |  |
| `data[].created_at` | string<date-time> |  |

```json
{
    "data": [
        {
            "id": 1,
            "email": "jane@example.com",
            "name": "Jane Doe",
            "status": "active",
            "created_at": "2026-01-15T09:30:00Z"
        },
        {
            "id": 2,
            "email": "sam@example.com",
            "name": "Sam Reyes",
            "status": "invited",
            "created_at": "2026-01-14T16:02:11Z"
        }
    ],
    "meta": {
        "page": 1,
        "per_page": 25,
        "total": 2
    }
}
```

**401** — Missing or expired bearer token.
