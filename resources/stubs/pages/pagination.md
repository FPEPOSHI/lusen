---
title: Pagination
section: Guides
order: 10
---

Describe how list endpoints page, once, here — rather than repeating it on
every endpoint.

## Requesting a page

Name the parameters and their limits.

| Parameter | Description |
| --- | --- |
| `page` | Page number, 1-indexed. |
| `per_page` | Results per page. |

## Reading the response

Show the envelope your list endpoints return, and say which field tells the
reader whether more pages exist.

```json
{
  "data": [],
  "meta": { "page": 1, "per_page": 25, "total": 0 }
}
```

## Iterating safely

Explain what happens if records change mid-iteration, and recommend an
approach — a stable sort, a cursor, or a snapshot.
