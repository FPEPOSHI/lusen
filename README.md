# Lusen

Fast, SEO- and AI-agent-friendly API documentation for Laravel.

Lusen reads your application's routes, form requests and resources, and emits
documentation as **static files** — HTML for people, OpenAPI and Markdown for
machines. There is no build step for consumers, no database, and no JavaScript
required to read a page.

> **Status: early.** Prose pages, route collection, FormRequest and attribute
> extraction, request-example generation, and the static HTML, Markdown, OpenAPI,
> `llms.txt`, sitemap, search and Postman surfaces all work, as does the MCP
> server.

## Install

```bash
composer require fpeposhi/lusen
```

That is the whole setup. Lusen discovers `api/*` routes and documents them with
no configuration.

```bash
php artisan lusen:build
```

Writes to `public/docs`, which your web server serves as flat files.

Prefer a live preview while developing? Set `LUSEN_RUNTIME=true` and visit
`/docs` — same Blade views, rendered on request.

## Why another one

Most Laravel API docs packages optimise for one reader: a developer with a
browser. Lusen assumes three, and treats them as equally important.

**People** get server-rendered HTML, one endpoint per page, styled with
Tailwind. Readable with JavaScript disabled.

**Search engines** get canonical URLs, real meta descriptions, JSON-LD
`TechArticle` and `BreadcrumbList` data, and a sitemap. Nothing is behind a
client-side router, so nothing is invisible to a crawler.

**AI agents and generative search** get first-class machine surfaces rather
than scraped HTML:

| Surface | What it is |
| --- | --- |
| `/docs/endpoints/{id}.md` | Every page mirrored in Markdown — swap `.html` for `.md` |
| `/docs/openapi.json` | OpenAPI 3.1 — real JSON Schema, so generated clients stop guessing |
| `/docs/llms.txt` | A curated index, one line per endpoint, per [llmstxt.org](https://llmstxt.org) |
| `/docs/llms-full.txt` | The entire API as Markdown in one file |
| `/.well-known/api-docs` | Discovery document listing every surface, from one guessable URL |
| `/docs/sitemap.xml` | Every page, for crawlers |
| `/docs/search-index.json` | Prebuilt index — search runs in the browser, with no server |
| `php artisan lusen:mcp` | An MCP server, so an agent queries your docs instead of scraping them |
| `/docs/pages/versioning.html` | Which API versions exist, and what changed between the newest two |
| `/docs/postman.json` | A Postman collection, for poking the API before writing code |
| `Accept: text/markdown` | Any docs URL returns Markdown instead of HTML (runtime mode) |

Two rules make those surfaces actually usable:

- **Every page is self-contained.** Each endpoint repeats the base URL, the
  auth requirement, every parameter and a complete example. A model that
  retrieves one page never needs a second one.
- **Identifiers are stable.** An endpoint's `operationId`, anchor and file
  name derive from its route name and do not change between builds, so links
  and citations keep working.

## See it

`docs/` is a showcase built from a fictional commerce API that serves two
versions at once — 24 endpoints across 9 groups, `v2` current and `v1`
deprecated with a retirement date. It is produced by running the package's real
emitters, so it is exactly what `lusen:build` writes, not a mockup:

```bash
php tools/build-showcase.php   # 7 prose pages, 24 endpoint pages, Markdown mirrors, OpenAPI, llms.txt, sitemap, search index
open docs/index.html
```

## Prose, not just endpoints

An endpoint list is useless to someone who does not already know your endpoint
names. Lusen builds a full documentation site: sidebar sections of written pages,
an on-page table of contents, and previous/next links across the whole thing.

Write pages as Markdown in `resources/docs`:

```markdown
---
title: Use cases
section: Guides
order: 10
---

Three things teams build with this API...
```

With no pages written at all, Lusen still generates **Introduction**,
**Authentication** and **Errors** from the spec itself — the auth page reflects
your actual middleware, and the error table lists the statuses your endpoints
really return. Everything generated is derived, never invented: if there is no
authenticated endpoint, there is no authentication page, and if the API serves
one version there is no versioning page. Anything you write wins over the
generated page of the same name.

For the editorial pages nothing can derive — use cases, quickstart — publish
starter stubs to edit:

```bash
php artisan vendor:publish --tag=lusen-pages
```

## MCP: let agents query the docs

Scraping HTML works and is worse — it breaks on every restyle and makes the
model pay for markup it cannot use. Lusen exposes the same documentation as MCP
tools:

```bash
php artisan lusen:mcp
```

Point a client at it. For Claude Code:

```bash
claude mcp add my-api -- php /path/to/your/app/artisan lusen:mcp
```

Or in a client's config file:

```json
{
  "mcpServers": {
    "my-api": {
      "command": "php",
      "args": ["/path/to/your/app/artisan", "lusen:mcp"]
    }
  }
}
```

Five tools:

| Tool | What it does |
| --- | --- |
| `search_documentation` | Keyword search across endpoints and guides |
| `list_endpoints` | The whole surface, grouped |
| `get_endpoint` | One endpoint in full — parameters, responses, example |
| `read_guide` | Introduction, versioning, authentication, errors, rate limiting, use cases |
| `build_request` | A runnable request with your values filled in |

Every tool returns the same Markdown a person reads, so there is one set of
documentation to keep correct rather than two.

## Documenting an endpoint

Most of it is inferred. Path parameters and auth come from the route; **request
bodies and query strings come from your FormRequest**, so an application that
already validates its input documents itself:

```php
public function rules(): array
{
    return [
        'email'              => 'required|email|max:255',
        'status'             => ['required', Rule::enum(OrderStatus::class)],
        'items'              => 'required|array|min:1',
        'items.*.product_id' => 'required|integer',
    ];
}
```

becomes a required `email` with `format: email` and `maxLength: 255`, a `status`
enum listing the enum's cases, and `items` as an array of objects — plus a
runnable cURL example whose values satisfy those rules. Lusen reads `rules()` by
parsing it, never by instantiating the request, so a docs build works in CI with
no `.env` and no database.

**Responses come from your API resources.** A `JsonResource`'s `toArray()` is a
precise statement of the response shape, so Lusen reads it — following nested
resources and collections, applying Laravel's `data` wrapper, and adding
pagination metadata when the collection is paginated.

**Field types come from your models.** A resource says `$this->price` and nothing
more, so Lusen looks at the model behind it: casts first, then the column
definitions in your migrations, which are also the only place nullability is
stated. So this:

```php
return ['price' => $this->price, 'sku' => $this->sku, 'status' => $this->status];
```

documents `price` as a number, `sku` as a nullable string with your column's
length limit, and `status` as an enum listing its real cases — with no
annotations anywhere. Anything nothing can answer stays untyped rather than
guessed; an unknown type documented as `string` is a guess a reader would act on.

**Prose comes from your docblocks.** If your controllers are commented, that is
your API reference — summary, description, `@group`, `@deprecated`,
`@authenticated`. Laravel's scaffolded comments ("Display a listing of the
resource") are ignored rather than repeated on every endpoint, and undocumented
resource actions get conventional wording instead: `index` on a Customers
controller reads "List customers", not "Index".

Attributes are how you say something inference cannot see, and they always win
over what was inferred.

```php
use Lusen\Attributes\{ApiDoc, ApiGroup, ApiParam, ApiResponse, Hidden};

#[ApiGroup('Users', 'Create and read user accounts.')]
final class UserController extends Controller
{
    #[ApiDoc(summary: 'List users', description: 'A paginated list of every user.')]
    #[ApiParam('per_page', in: 'query', type: 'integer', example: 25)]
    #[ApiResponse(200, 'A page of users.', example: ['data' => [['id' => 1, 'name' => 'Ada']]])]
    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(User::paginate());
    }

    #[Hidden]
    public function internalMetrics(): JsonResponse { /* ... */ }
}
```

## Two versions at once

An API that has lived long enough serves `/api/v1/orders` next to
`/api/v2/orders`, and documenting that badly is the worst thing a reference can
do: two pages titled "List orders", two identical meta descriptions competing
for the same query, and an assistant with no way to tell which one to recommend.

Lusen reads the version out of the path. Nothing to configure:

- The reference is grouped by version, newest first, and every page badges the
  version it belongs to.
- Each older endpoint links to its replacement — matched by method and path, so
  `GET /api/v1/orders` finds `GET /api/v2/orders` on its own.
- Titles, meta descriptions, OpenAPI tags, Postman folders and search results
  all name the version, so nothing competes with itself.
- A **Versioning** page is derived: which versions exist, which to use, and
  what the newest one added or dropped compared with the one before it.

The two things a URL cannot tell you are configurable:

```php
'versions' => [
    'current' => 'v2',                      // default: the newest found
    'deprecated' => ['v1' => '2026-06-01'], // a date, or just ['v1']
],
```

Deprecating a version marks every endpoint in it deprecated and points each one
at its successor. An API that negotiates its version in a header leaves nothing
in the path to read, so its controllers say so with
`#[ApiDoc(version: 'v2')]`.

## Writing the parts Lusen cannot infer

Prose pages, examples worth writing by hand, attributes, docblock tags and
every configuration knob are covered in **[AUTHORING.md](AUTHORING.md)**.

The short version: what you write always wins, and Lusen would rather document a
field as `any` than guess at it.

## Configuration

Every key has a working default; publish the file only if you need to change
something.

```bash
php artisan vendor:publish --tag=lusen-config
```

## Commands

```bash
php artisan lusen:build                      # emit every enabled surface
php artisan lusen:build --only=openapi       # just one
php artisan lusen:build --dry-run            # report without writing
php artisan lusen:build --path=/tmp/docs     # override the output directory
php artisan lusen:build --fresh              # ignore the incremental cache
php artisan lusen:check                      # report endpoints missing documentation
php artisan lusen:check --strict             # ...and fail CI when any are
```

Builds are incremental. An endpoint is re-analysed only when its route or one
of the files behind it actually changed — comparing contents, not timestamps,
so a fresh checkout or a CI runner still gets the benefit. Add `.lusen` to your
`.gitignore`.

## Contributing

```bash
composer install
composer test        # Pest + Orchestra Testbench
composer check       # Pint, PHPStan at level max, then the suite
```

The docs UI is Tailwind v4. `dist/lusen.css` is committed so installing Lusen
never requires Node — if you change classes in `resources/views`, run
`npm install && npm run build` and commit the CSS in the same change.

## Licence

MIT.
