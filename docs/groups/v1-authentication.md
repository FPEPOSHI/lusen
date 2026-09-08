---
title: "Authentication (v1)"
group: "Authentication"
api_version: "v1"
canonical: "https://lusen.oda.al/groups/v1-authentication.html"
---

# Authentication (v1)

Part of the [Acme Commerce API](/index.html) documentation.

Unchanged in v2 apart from the path.

Base URL: `https://api.acme.example`

1 of 2 operations require authentication.

## Operations

- [POST /api/v1/auth/tokens](/endpoints/v1-auth-tokens-store.md) — Issue an access token
- [DELETE /api/v1/auth/tokens/current](/endpoints/v1-auth-tokens-destroy.md) — Revoke the current token
