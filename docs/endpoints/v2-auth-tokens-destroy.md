---
title: "Revoke the current token"
operation_id: "v2.auth.tokens.destroy"
method: DELETE
path: "/api/v2/auth/tokens/current"
group: "Authentication"
api_version: "v2"
authenticated: true
canonical: "https://fpeposhi.github.io/lusen/endpoints/v2-auth-tokens-destroy.html"
---

# Revoke the current token

Part of the [Acme Commerce API](/lusen/index.html) documentation.

## DELETE /api/v2/auth/tokens/current

Invalidates the token used to make this call. Idempotent — revoking an already-revoked token still returns 204.

Full URL: `https://api.acme.example/api/v2/auth/tokens/current`

API version: `v2`.

Authentication: required (bearer token).

### Example request

```bash
curl -X DELETE 'https://api.acme.example/api/v2/auth/tokens/current' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Accept: application/json'
```

### Responses

**204** — Revoked. No body is returned.
