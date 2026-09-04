---
title: "Revoke the current token"
operation_id: "v1.auth.tokens.destroy"
method: DELETE
path: "/api/v1/auth/tokens/current"
group: "Authentication"
api_version: "v1"
authenticated: true
deprecated: true
superseded_by: "v2.auth.tokens.destroy"
canonical: "https://fpeposhi.github.io/lusen/endpoints/v1-auth-tokens-destroy.html"
---

# Revoke the current token

Part of the [Acme Commerce API](/lusen/index.html) documentation.

## DELETE /api/v1/auth/tokens/current

Invalidates the token used to make this call. Idempotent — revoking an already-revoked token still returns 204.

**Deprecated.**

**A newer version of this operation exists**: [`DELETE /api/v2/auth/tokens/current`](/lusen/endpoints/v2-auth-tokens-destroy.md).

Full URL: `https://api.acme.example/api/v1/auth/tokens/current`

API version: `v1`.

Authentication: required (bearer token).

### Example request

```bash
curl -X DELETE 'https://api.acme.example/api/v1/auth/tokens/current' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Accept: application/json'
```

### Responses

**204** — Revoked. No body is returned.
