---
title: "Introduction"
page_id: "introduction"
section: "Getting started"
canonical: "https://fpeposhi.github.io/lusen/pages/introduction.html"
---

# Introduction

Part of the [Acme Commerce API](/lusen/index.html) documentation.

Everything you need to sell: customers, orders, refunds, the product catalogue and webhooks. REST over HTTPS, JSON in and out, bearer-token authenticated.

## At a glance

| | |
| --- | --- |
| Version | `2.4.1` |
| API versions | `v2` (current), `v1` (deprecated) |
| Base URL | `https://api.acme.example` |
| Sandbox | `https://sandbox.acme.example` |
| Endpoints | 24 across 9 groups |
| Format | JSON request and response bodies |

## What you can do

- **Authentication (v2)** — Exchange an API key pair for a bearer token, and revoke it when you are done. (2 endpoints)
- **Customers (v2)** — Create and manage the people who place orders. (5 endpoints)
- **Orders (v2)** — Place, read and refund orders. (4 endpoints)
- **Products (v2)** — The public product catalogue. (2 endpoints)
- **Webhooks (v2)** — Receive signed callbacks when things happen in your account. (2 endpoints)
- **Authentication (v1)** — Unchanged in v2 apart from the path. (2 endpoints)
- **Customers (v1)** — Reading and creating customers. Updating and deleting them arrived in v2. (3 endpoints)
- **Orders (v1)** — Placing and reading orders. Refunds arrived in v2. (2 endpoints)
- **Products (v1)** — The public product catalogue, with the search route v2 replaced. (2 endpoints)

## Making a request

Every endpoint speaks JSON. This one needs no credentials, so you can run it right now:

```bash
curl -X GET 'https://api.acme.example/api/v2/products' \
  -H 'Accept: application/json'
```

## Reading this documentation

Each endpoint has its own page listing every parameter, every response status and a request you can copy and run. 
Pages are also published as Markdown — swap `.html` for `.md` on any endpoint URL — and the whole API is available as an OpenAPI 3.1 document.
