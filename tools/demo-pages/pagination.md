---
title: Pagination
section: Guides
order: 10
---

Every list endpoint pages the same way, so this is documented once here rather
than repeated on each one.

## Requesting a page

| Parameter | Description |
| --- | --- |
| `page` | Page number, 1-indexed. Defaults to `1`. |
| `per_page` | Results per page, 1–100. Defaults to `25`. |

## Reading the response

List responses wrap results in `data` and report position in `meta`.

```json
{
  "data": [],
  "meta": { "page": 1, "per_page": 25, "total": 0 }
}
```

You have reached the end when `page * per_page >= meta.total`.

## Iterating safely

Records are returned newest first, so inserting during a long iteration shifts
later pages. For a full export, filter to a fixed window rather than walking
the whole collection.
