---
title: "Authentication (v1)"
group: "Authentication"
api_version: "v1"
canonical: "https://lusen.oda.al/example/groups/v1-authentication.html"
---

# Authentication (v1)

Part of the [Acme Commerce API](/example/index.html) documentation.

Unchanged in v2 apart from the path.

Base URL: `https://api.acme.example`

1 of 2 operations require authentication.

## Operations

- [POST /api/v1/auth/tokens](/example/endpoints/v1-auth-tokens-store.md) — Issue an access token
- [DELETE /api/v1/auth/tokens/current](/example/endpoints/v1-auth-tokens-destroy.md) — Revoke the current token
