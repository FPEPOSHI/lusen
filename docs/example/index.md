# Acme Commerce API

Everything you need to sell: customers, orders, refunds, the product catalogue and webhooks. REST over HTTPS, JSON in and out, bearer-token authenticated.

Version 2.4.1.
Base URL: `https://api.acme.example`

## API versions

| Version | Status | Endpoints |
| --- | --- | --- |
| `v2` | current | 15 |
| `v1` | deprecated — retires 2026-09-01 | 9 |

Write new integrations against `v2`. Every endpoint below states the version it belongs to, and any endpoint with a newer edition links to it.

## Getting started

- [Introduction](/example/pages/introduction.md) — Everything you need to sell: customers, orders, refunds, the product catalogue and webhooks. REST over HTTPS, JSON in and out, bearer-token authenticated.
- [Use cases](/example/pages/use-cases.md) — Three things teams build with the Acme Commerce API, in the order people usually build them.
- [Versioning](/example/pages/versioning.md) — This API serves 2 versions at once. The version is part of the path, so a request names the version it wants.
- [Authentication](/example/pages/authentication.md) — Authenticated endpoints expect a bearer token.
- [Errors](/example/pages/errors.md) — Errors are returned with a conventional HTTP status and a JSON body.
- [Rate limiting](/example/pages/rate-limiting.md) — Requests are rate limited per client. Exceeding a limit returns 429 Too Many Requests.

## Guides

- [Pagination](/example/pages/pagination.md) — Every list endpoint pages the same way, so this is documented once here rather than repeated on each one.

## About Lusen

- [How these docs are built](/example/pages/how-lusen-works.md) — What Lusen is, how it turns a Laravel application into a site like this one, and where the example that produced this site lives.

## [Authentication (v2)](/example/groups/v2-authentication.md)

Exchange an API key pair for a bearer token, and revoke it when you are done.

- [POST /api/v2/auth/tokens](/example/endpoints/v2-auth-tokens-store.md) — Issue an access token
- [DELETE /api/v2/auth/tokens/current](/example/endpoints/v2-auth-tokens-destroy.md) — Revoke the current token

## [Customers (v2)](/example/groups/v2-customers.md)

Create and manage the people who place orders.

- [GET /api/v2/customers](/example/endpoints/v2-customers-index.md) — List customers
- [POST /api/v2/customers](/example/endpoints/v2-customers-store.md) — Create a customer
- [GET /api/v2/customers/{customer}](/example/endpoints/v2-customers-show.md) — Retrieve a customer
- [PATCH /api/v2/customers/{customer}](/example/endpoints/v2-customers-update.md) — Update a customer
- [DELETE /api/v2/customers/{customer}](/example/endpoints/v2-customers-destroy.md) — Delete a customer

## [Orders (v2)](/example/groups/v2-orders.md)

Place, read and refund orders.

- [GET /api/v2/orders](/example/endpoints/v2-orders-index.md) — List orders
- [POST /api/v2/orders](/example/endpoints/v2-orders-store.md) — Create an order
- [GET /api/v2/orders/{order}](/example/endpoints/v2-orders-show.md) — Retrieve an order
- [POST /api/v2/orders/{order}/refunds](/example/endpoints/v2-orders-refunds-store.md) — Refund an order

## [Products (v2)](/example/groups/v2-products.md)

The public product catalogue.

- [GET /api/v2/products](/example/endpoints/v2-products-index.md) — List products
- [GET /api/v2/products/{product}](/example/endpoints/v2-products-show.md) — Retrieve a product

## [Webhooks (v2)](/example/groups/v2-webhooks.md)

Receive signed callbacks when things happen in your account.

- [GET /api/v2/webhooks](/example/endpoints/v2-webhooks-index.md) — List webhook endpoints
- [POST /api/v2/webhooks](/example/endpoints/v2-webhooks-store.md) — Register a webhook endpoint

## [Authentication (v1)](/example/groups/v1-authentication.md)

Unchanged in v2 apart from the path.

- [POST /api/v1/auth/tokens](/example/endpoints/v1-auth-tokens-store.md) — Issue an access token
- [DELETE /api/v1/auth/tokens/current](/example/endpoints/v1-auth-tokens-destroy.md) — Revoke the current token

## [Customers (v1)](/example/groups/v1-customers.md)

Reading and creating customers. Updating and deleting them arrived in v2.

- [GET /api/v1/customers](/example/endpoints/v1-customers-index.md) — List customers
- [POST /api/v1/customers](/example/endpoints/v1-customers-store.md) — Create a customer
- [GET /api/v1/customers/{customer}](/example/endpoints/v1-customers-show.md) — Retrieve a customer

## [Orders (v1)](/example/groups/v1-orders.md)

Placing and reading orders. Refunds arrived in v2.

- [GET /api/v1/orders](/example/endpoints/v1-orders-index.md) — List orders
- [POST /api/v1/orders](/example/endpoints/v1-orders-store.md) — Create an order

## [Products (v1)](/example/groups/v1-products.md)

The public product catalogue, with the search route v2 replaced.

- [GET /api/v1/products](/example/endpoints/v1-products-index.md) — List products
- [GET /api/v1/products/search](/example/endpoints/v1-products-search.md) — Search products

## Machine-readable

- [OpenAPI 3.1](/example/openapi.json)
- [llms.txt](/example/llms.txt)
- [Full corpus](/example/llms-full.txt)
