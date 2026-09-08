---
title: "Authentication (v2)"
group: "Authentication"
api_version: "v2"
canonical: "https://lusen.oda.al/groups/v2-authentication.html"
---

# Authentication (v2)

Part of the [Acme Commerce API](/index.html) documentation.

Exchange an API key pair for a bearer token, and revoke it when you are done.

Base URL: `https://api.acme.example`

1 of 2 operations require authentication.

## Operations

- [POST /api/v2/auth/tokens](/endpoints/v2-auth-tokens-store.md) — Issue an access token
- [DELETE /api/v2/auth/tokens/current](/endpoints/v2-auth-tokens-destroy.md) — Revoke the current token
