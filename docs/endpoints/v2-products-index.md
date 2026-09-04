---
title: "List products"
operation_id: "v2.products.index"
method: GET
path: "/api/v2/products"
group: "Products"
api_version: "v2"
authenticated: false
canonical: "https://fpeposhi.github.io/lusen/endpoints/v2-products-index.html"
---

# List products

Part of the [Acme Commerce API](/lusen/index.html) documentation.

## GET /api/v2/products

Public catalogue. No credentials required, so this endpoint is safe to call from a browser.

Full URL: `https://api.acme.example/api/v2/products`

API version: `v2`.

Authentication: not required.

### Query parameters

| Name | Type | Required | Description |
| --- | --- | --- | --- |
| `per_page` | integer | no | Results per page, 1–100. |
| `page` | integer | no | Page number, 1-indexed. |
| `currency` | string, one of USD, EUR, GBP | no | Prices are converted to this currency. |
| `q` | string, maxLength 120 | no | Free-text search over name and description. Replaces the v1 search endpoint. |

### Example request

```bash
curl -X GET 'https://api.acme.example/api/v2/products' \
  -H 'Accept: application/json'
```

### Responses

**200** — A page of products.

```json
{
    "data": [
        {
            "id": 12,
            "name": "Field Notebook",
            "price": 1999,
            "currency": "USD",
            "in_stock": true
        }
    ],
    "meta": {
        "page": 1,
        "per_page": 25,
        "total": 1
    }
}
```
