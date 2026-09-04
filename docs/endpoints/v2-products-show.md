---
title: "Retrieve a product"
operation_id: "v2.products.show"
method: GET
path: "/api/v2/products/{product}"
group: "Products"
api_version: "v2"
authenticated: false
canonical: "https://fpeposhi.github.io/lusen/endpoints/v2-products-show.html"
---

# Retrieve a product

Part of the [Acme Commerce API](/lusen/index.html) documentation.

## GET /api/v2/products/{product}

Full URL: `https://api.acme.example/api/v2/products/{product}`

API version: `v2`.

Authentication: not required.

### Path parameters

| Name | Type | Required | Description |
| --- | --- | --- | --- |
| `product` | integer | yes | The product id. |

### Example request

```bash
curl -X GET 'https://api.acme.example/api/v2/products/1' \
  -H 'Accept: application/json'
```

### Responses

**200** — The product.

**404** — No product with that id.
