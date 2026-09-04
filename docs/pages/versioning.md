---
title: "Versioning"
page_id: "versioning"
section: "Getting started"
canonical: "https://fpeposhi.github.io/lusen/pages/versioning.html"
---

# Versioning

Part of the [Acme Commerce API](/lusen/index.html) documentation.

This API serves 2 versions at once. The version is part of the path, so a request names the version it wants.

## Versions

| Version | Status | Endpoints |
| --- | --- | --- |
| `v2` | current | 15 |
| `v1` | deprecated — retires 2026-09-01 | 9 |

New integrations should use `v2`.

## `v2` compared with `v1`

New in `v2`:

- `PATCH /api/v2/customers/{customer}` — Update a customer
- `DELETE /api/v2/customers/{customer}` — Delete a customer
- `GET /api/v2/orders/{order}` — Retrieve an order
- `POST /api/v2/orders/{order}/refunds` — Refund an order
- `GET /api/v2/products/{product}` — Retrieve a product
- `GET /api/v2/webhooks` — List webhook endpoints
- `POST /api/v2/webhooks` — Register a webhook endpoint

In `v1` but not in `v2`:

- `GET /api/v1/products/search` — Search products

The other 8 operations exist in both versions at the same path. Each one's page links to its newer edition.
