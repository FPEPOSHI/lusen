---
title: "Search products"
operation_id: "v1.products.search"
method: GET
path: "/api/v1/products/search"
group: "Products"
api_version: "v1"
authenticated: false
deprecated: true
canonical: "https://fpeposhi.github.io/lusen/endpoints/v1-products-search.html"
---

# Search products

Part of the [Acme Commerce API](/lusen/index.html) documentation.

## GET /api/v1/products/search

Removed in v2, where the list endpoint takes a `q` parameter instead. Kept here for integrations that have not moved yet.

**Deprecated.**

Full URL: `https://api.acme.example/api/v1/products/search`

API version: `v1`.

Authentication: not required.

### Query parameters

| Name | Type | Required | Description |
| --- | --- | --- | --- |
| `term` | string | yes | The search term. |

### Example request

```bash
curl -X GET 'https://api.acme.example/api/v1/products/search?term=term' \
  -H 'Accept: application/json'
```

### Responses

**200** — Matching products.
