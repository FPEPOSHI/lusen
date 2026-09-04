---
title: "List webhook endpoints"
operation_id: "v2.webhooks.index"
method: GET
path: "/api/v2/webhooks"
group: "Webhooks"
api_version: "v2"
authenticated: true
canonical: "https://fpeposhi.github.io/lusen/endpoints/v2-webhooks-index.html"
---

# List webhook endpoints

Part of the [Acme Commerce API](/lusen/index.html) documentation.

## GET /api/v2/webhooks

Full URL: `https://api.acme.example/api/v2/webhooks`

API version: `v2`.

Authentication: required (bearer token).

### Example request

```bash
curl -X GET 'https://api.acme.example/api/v2/webhooks' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Accept: application/json'
```

### Responses

**200** — Your configured webhook endpoints.
