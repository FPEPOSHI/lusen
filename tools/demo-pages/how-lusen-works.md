---
title: How these docs are built
section: About Lusen
order: 10
description: What Lusen is, how it turns a Laravel application into a site like this one, and where the example that produced this site lives.
---

Everything on this site — the pages, the OpenAPI document, `llms.txt`, the
sitemap, the search index, the Postman collection — was written by
[Lusen](https://github.com/fpeposhi/lusen), a Laravel package that documents
the application it is installed in. The Acme Commerce API does not exist. What
exists is the [example folder](https://github.com/fpeposhi/lusen/tree/main/tools)
that describes it, and a build script that runs the package's real emitters
over that description. Nothing here is a mockup, and this page is one of the
files in that folder.

## Three stages

Lusen works in three stages, with a plain data structure in the middle.

**Collect.** It reads the route table of the host application and keeps the
routes that match `api/*`, then reads the Markdown pages the team wrote in
`resources/docs`. Routes are sorted by path, so the output never depends on
the order they were registered in.

**Extract.** Each route passes through a pipeline of extractors. The first
reads what the route itself says: path parameters, authentication from
middleware, the API version from the URL. The next read the code behind it —
the controller's docblock, the form request's validation rules for the request
body, the API resource's `toArray()` for the response, and the model's casts
and migrations for the types a resource leaves unstated. Recorded responses
from the test suite replace generated examples where they exist, and the
attributes a team wrote — Lusen's own, or the ones another tool left behind —
have the last word.

All of it is static analysis. Lusen parses the source rather than running it,
so a build never boots the application, never calls a validation rule and
never opens a database connection. It succeeds in CI against a checkout with
no `.env`, and it degrades to fewer details rather than to a failed build when
something cannot be read.

**Emit.** The result is a serialisable description of the API: groups,
endpoints, parameters, schemas, responses, examples, and the prose pages
beside them. Every surface on this site is an emitter over that one
description, which is why they cannot disagree with each other.

## The surfaces

Each one answers a different reader.

| Surface | For |
| --- | --- |
| [Endpoint pages](/endpoints/v2-orders-store.html) and [group pages](/groups/v2-orders.html) | A person, one question per page |
| The `.md` twin of every page — [this one](/endpoints/v2-orders-store.md), say | A model or an agent, without markup to wade through |
| [`openapi.json`](/openapi.json) | Generated clients — OpenAPI 3.1, so the schemas are real JSON Schema |
| [`llms.txt`](/llms.txt) and [`llms-full.txt`](/llms-full.txt) | Retrieval models, as an index and as the whole API in one file |
| [`search-index.json`](/search-index.json) | The search box on every page, with no server behind it |
| [`sitemap.xml`](/sitemap.xml) | Crawlers |
| [`postman.json`](/postman.json) | Poking the API before writing code |
| [`/.well-known/api-docs`](/.well-known/api-docs) | An agent that has one URL and needs to find the rest |

Every file is static. A web server serves the output as flat files with no PHP
on the request path, and every page reads completely with JavaScript
disabled. An application that installs Lusen also gets an MCP server, so an
assistant can query the documentation instead of scraping it; that one needs a
running application, so it is the only surface this static site cannot show.

## What is derived on this site

The example [states only the endpoints](https://github.com/fpeposhi/lusen/blob/main/tools/demo-spec.php).
Everything about versions is worked out from them: that `v2` is current and
`v1` is deprecated, the retirement date, which `v1` operation each `v2`
operation supersedes, and the *Changed since v1* list on an operation both
versions expose. The [versioning page](/pages/versioning.html), the
introduction, the authentication page and the errors page were written by
Lusen from what the endpoints expose, since nobody wrote them here. The
[use cases](/pages/use-cases.html) and [pagination](/pages/pagination.html)
pages were written by hand, because a use case is not something a tool can
derive.

## Try it on your own application

```bash
composer require fpeposhi/lusen
php artisan lusen:build
```

That is the whole setup: Lusen discovers `api/*` routes and documents them with
no configuration. The [README](https://github.com/fpeposhi/lusen#readme) covers
what you get, and [AUTHORING.md](https://github.com/fpeposhi/lusen/blob/main/AUTHORING.md)
covers what to write where the inference falls short.

## The example

The [`tools/`](https://github.com/fpeposhi/lusen/tree/main/tools) folder holds
everything that produced this site:

- [`demo-spec.php`](https://github.com/fpeposhi/lusen/blob/main/tools/demo-spec.php) —
  the fictional API, as the description Lusen's extractors would have built
- [`demo-pages/`](https://github.com/fpeposhi/lusen/tree/main/tools/demo-pages) —
  the pages written by hand, including this one
- [`build-showcase.php`](https://github.com/fpeposhi/lusen/blob/main/tools/build-showcase.php) —
  the build, which runs the same emitters `lusen:build` runs

Every written page on this site links to its own source under *Edit this
page*, which is what a real deployment gets from one configuration line.
