---
title: Use cases
section: Getting started
order: 15
---

Replace this page with the two or three things people actually build with your
API. Written well, it is the page most likely to be found by someone who does
not yet know your endpoint names — and the one an AI assistant will quote when
asked what your API is for.

Keep each use case concrete: the goal, the calls it takes, and a working
example.

## Syncing customers from your CRM

Describe the scenario in a sentence, then show the sequence.

1. Fetch the customers changed since your last sync.
2. Reconcile them against your own records.
3. Write back anything that changed.

```bash
curl 'https://api.example.com/api/customers?updated_since=2026-01-01' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Accept: application/json'
```

## Reacting to an order being paid

Explain when to poll and when to use a webhook, and which one you recommend.

## Backfilling historical data

Cover the boring parts people hit in production: pagination limits, rate
limits, and how to resume after a failure.
