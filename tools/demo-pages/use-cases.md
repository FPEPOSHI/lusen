---
title: Use cases
section: Getting started
order: 15
---

Three things teams build with the Acme Commerce API, in the order people
usually build them.

## Syncing customers from your CRM

Keep an external system in step with Acme without polling everything. Pull the
customers that changed, reconcile them locally, and write back only what moved.

```bash
curl 'https://api.acme.example/api/v2/customers?status=active&per_page=100' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Accept: application/json'
```

Page through with `page` until `meta.total` is exhausted. Customers are
returned newest first, so a run that starts mid-import will not skip records
already seen.

## Taking an order and refunding it

The order lifecycle is three calls. Create the order with an `Idempotency-Key`
so a network retry cannot double-charge, read it back to confirm payment, and
refund all or part of it if the customer changes their mind.

1. `POST /api/v2/orders` — creates the order in `pending`.
2. `GET /api/v2/orders/{order}` — poll until `status` is `paid`.
3. `POST /api/v2/orders/{order}/refunds` — full or partial, repeatable up to the
   order total.

Prefer a webhook over polling step 2 in production.

## Reacting to events instead of polling

Register a webhook endpoint once and Acme posts to it whenever something
happens. Every delivery carries an `X-Acme-Signature` header — verify it
before you trust the body.

```bash
curl -X POST 'https://api.acme.example/api/v2/webhooks' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{"url":"https://example.com/hooks/acme","events":["order.paid"]}'
```

The response includes a signing secret. Store it; it is shown once.
