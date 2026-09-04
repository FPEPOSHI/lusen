---
title: "Errors"
page_id: "errors"
section: "Getting started"
canonical: "https://fpeposhi.github.io/lusen/pages/errors.html"
---

# Errors

Part of the [Acme Commerce API](/lusen/index.html) documentation.

Errors are returned with a conventional HTTP status and a JSON body.

## Status codes used by this API

| Status | Meaning |
| --- | --- |
| `401` | Unauthenticated |
| `404` | Not Found |
| `409` | Conflict |
| `422` | Unprocessable Entity |
| `429` | Too Many Requests |

## Error body

```json
{
    "message": "These credentials do not match our records."
}
```

A `429` means you have exceeded a rate limit. See [Rate limiting](rate-limiting) for the specific limits.
