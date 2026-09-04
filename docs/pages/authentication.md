---
title: "Authentication"
page_id: "authentication"
section: "Getting started"
canonical: "https://fpeposhi.github.io/lusen/pages/authentication.html"
---

# Authentication

Part of the [Acme Commerce API](/lusen/index.html) documentation.

Authenticated endpoints expect a bearer token.

```bash
Authorization: Bearer YOUR_TOKEN
```

## Which endpoints need it

18 of 24 endpoints require authentication.

These endpoints are public and need no credentials:

- `POST /api/v2/auth/tokens`
- `GET /api/v2/products`
- `GET /api/v2/products/{product}`
- `POST /api/v1/auth/tokens`
- `GET /api/v1/products`
- `GET /api/v1/products/search`

## A complete request

```bash
curl -X DELETE 'https://api.acme.example/api/v2/auth/tokens/current' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Accept: application/json'
```

A request with a missing, malformed or expired token is rejected with `401`. 
A valid token that lacks permission for the operation is rejected with `403`.
