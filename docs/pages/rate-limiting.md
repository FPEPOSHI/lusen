---
title: "Rate limiting"
page_id: "rate-limiting"
section: "Getting started"
canonical: "https://fpeposhi.github.io/lusen/pages/rate-limiting.html"
---

# Rate limiting

Part of the [Acme Commerce API](/lusen/index.html) documentation.

Requests are rate limited per client. Exceeding a limit returns `429 Too Many Requests`.

## Limits

| Limit | Endpoints |
| --- | --- |
| 60 requests per minute | Authentication, Customers |
| 10 requests per minute | Orders |
| 300 requests per minute | Products |

The remaining 16 endpoints declare no limit of their own.

## Staying within the limit

A `429` response carries a `Retry-After` header giving the number of seconds to wait. Honour it rather than retrying immediately — a tight retry loop will keep you locked out.

Two habits keep you well clear of the limit:

- Request larger pages instead of more pages. One call for 100 records costs a quarter of four calls for 25.
- Cache anything that does not change often, and prefer webhooks over polling where they exist.
