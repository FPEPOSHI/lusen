---
title: "Issue an access token"
operation_id: "v1.auth.tokens.store"
method: POST
path: "/api/v1/auth/tokens"
group: "Authentication"
api_version: "v1"
authenticated: false
deprecated: true
superseded_by: "v2.auth.tokens.store"
canonical: "https://fpeposhi.github.io/lusen/endpoints/v1-auth-tokens-store.html"
---

# Issue an access token

Part of the [Acme Commerce API](/lusen/index.html) documentation.

## POST /api/v1/auth/tokens

Exchanges an API key pair for a short-lived bearer token. Tokens expire after one hour; request a new one rather than caching indefinitely.

**Deprecated.**

**A newer version of this operation exists**: [`POST /api/v2/auth/tokens`](/lusen/endpoints/v2-auth-tokens-store.md).

Full URL: `https://api.acme.example/api/v1/auth/tokens`

API version: `v1`.

Authentication: not required.

### Body parameters

| Name | Type | Required | Description |
| --- | --- | --- | --- |
| `key_id` | string | yes | The public half of your API key pair. |
| `key_secret` | string | yes | The secret half. Never send this from a browser. |
| `scopes` | array | no | Defaults to every scope the key is entitled to. |

### Example request

```bash
curl -X POST 'https://api.acme.example/api/v1/auth/tokens' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{
    "key_id": "key id",
    "key_secret": "key secret",
    "scopes": [
        "orders:read"
    ]
}'
```

### Responses

**201** — A token you can use as a bearer credential.

```json
{
    "access_token": "act_7f3a9c2e5b1d8406",
    "token_type": "Bearer",
    "expires_in": 3600,
    "scopes": [
        "orders:read",
        "orders:write"
    ]
}
```

**422** — The key pair was rejected.

```json
{
    "message": "These credentials do not match our records."
}
```
