---
title: "Register a webhook endpoint"
operation_id: "v2.webhooks.store"
method: POST
path: "/api/v2/webhooks"
group: "Webhooks"
api_version: "v2"
authenticated: true
canonical: "https://fpeposhi.github.io/lusen/endpoints/v2-webhooks-store.html"
---

# Register a webhook endpoint

Part of the [Acme Commerce API](/lusen/index.html) documentation.

## POST /api/v2/webhooks

We POST a signed JSON payload to your URL for each subscribed event. Verify the `X-Acme-Signature` header before trusting a delivery.

Full URL: `https://api.acme.example/api/v2/webhooks`

API version: `v2`.

Authentication: required (bearer token).

### Body parameters

| Name | Type | Required | Description |
| --- | --- | --- | --- |
| `url` | string<uri> | yes | Must be HTTPS. |
| `events` | array | yes | At least one event. |

### Example request

```bash
curl -X POST 'https://api.acme.example/api/v2/webhooks' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{
    "url": "https://example.com",
    "events": [
        "order.paid"
    ]
}'
```

### Responses

**201** — The registered endpoint, including the signing secret.

```json
{
    "id": "whk_3f9",
    "url": "https://example.com/hooks/acme",
    "events": [
        "order.paid"
    ],
    "signing_secret": "whsec_5d4c3b2a1908"
}
```

**422** — Validation failed.
