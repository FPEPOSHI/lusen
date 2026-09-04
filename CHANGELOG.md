# Changelog

All notable changes to this project are documented here.

## 0.3.0 — 2026-09-04

### Added

- `#[ApiResponse(type:)]`, taking the same grammar as `@response`, so a
  response body can be described with Lusen's own attribute rather than
  another tool's. Without it the Scramble layer was the only way to attach a
  schema to a status, which made a compatibility shim look like the
  recommended path. An example is generated from the type when none is
  written.

## 0.2.0 — 2026-09-04

Everything in this release came from pointing 0.1.0 at a real application
and writing down what it could not read. Its API was thoroughly documented
already — in `@response` shapes, in documentation-only DTOs, in docblocks
above validation rules and in another tool's attributes — and Lusen read
none of it. Endpoints missing documentation there went from 75 of 77 to 29.

### Added


- **Responses from written types.** `@response array{status: true, data: Order}`
  is now read into a response schema, with the PHPStan grammar most codebases
  already use: nested shapes, `list<T>`, `array<T>`, `T[]`, `?T`, optional
  `key?:`, literals, and a union of string literals as an enum. An API that
  answers with plain arrays through a base-controller envelope — which has no
  resource for Lusen to parse — can now document its responses without
  adopting anything new.

- **Classes named in those shapes are read as schemas**: public typed
  properties become fields and their docblock summaries become descriptions.
  Documentation-only DTOs are a common way to keep response shapes beside the
  code, and they are better evidence than inference. Nothing is marked
  required, because a class cannot say which fields are always present.

- Precedence is unchanged and now spans three sources: an `#[ApiResponse]`
  attribute beats a `@response` docblock, which beats a shape inferred from a
  resource's `toArray()`.

- **Scramble's attributes are read.** `#[Group]`, `#[Response]` and the
  `#[QueryParameter]` family become groups, responses and parameters, with
  `type:` resolved through the same reader that handles `@response`. A
  codebase documented once should not have to be documented again to change
  tools. Scramble does not need to be installed: the attributes are matched by
  name and read without being instantiated, so this still works after the
  dependency is gone. Lusen's own attributes continue to win, and
  `ScrambleExtractor::class` can be dropped from `extractors`.

  **Upgrading with a published config:** your `extractors` list replaces the
  package's, so add `Lusen\Extract\ScrambleExtractor::class` to it (before
  `AttributeExtractor`) or the extractor will not run.

- **Parameter descriptions and examples from the rules.** A docblock above an
  entry in `rules()` becomes the parameter's description, and its `@example`
  becomes the example — typed as written, so `3` is a number and the generated
  request does not quote it. A rule-derived note is kept alongside the
  sentence rather than replacing it. `attributes()` and `messages()` are not
  used: they carry labels and error text, and are usually `__()` calls that a
  reader which never boots the application cannot resolve.

- **Response envelopes.** `return $this->sendResponse([...])` is followed into
  the helper it names, so an API that wraps every success in
  `{status: true, data: …}` documents the wrapper along with the payload, in
  the place the helper puts it. Read out of the code rather than declared in
  configuration, so it cannot contradict what ships or double-wrap a shape
  somebody already wrote out. When the payload is beyond reach the envelope is
  still documented, with the payload left `any`.

### Fixed

- A guard clause could be documented as the success response. The first return
  statement won, so `if (! allowed) return $this->sendError(...)` documented
  the error envelope as the 200 — and nothing on the page would have told a
  reader it was wrong. A return in the method body now beats one nested inside
  a conditional.

- `true`, `false` and `null` in an array literal arrive as constant fetches
  rather than scalars, so a field written as `'active' => true` documented as
  untyped instead of boolean.

- The build cache did not invalidate when the package itself changed, despite
  its own documentation saying it did. Upgrading Lusen kept serving endpoints
  analysed by the previous version, so a release that taught an extractor to
  read something new appeared not to work until someone ran `--fresh`.

## 0.1.0 — 2026-09-04

First tagged release. Nothing below shipped before it, so the Changed and
Fixed entries describe work done on the way here rather than anything a
consumer would have run into.

Released as `0.x` deliberately: the emitted surfaces and the endpoint
identifiers are stable enough to build on, but the configuration shape and the
IR are still moving, and whether the MCP server stays in this package is an
open question. Pin with `^0.1`.

### Changed

- Laravel 11 is no longer supported. Every 11.x release is now covered by a
  security advisory, so Composer's default policy refuses to install one at
  all — a package claiming to support it would be sending people at a
  framework they cannot install and should not be running. The requirement is
  `^12.0`, and CI tests Laravel 12 on PHP 8.2, 8.3 and 8.4.

### Fixed

- `resources/js/lusen.js` was `export-ignore`d, so the one file the docs read
  at runtime for copy buttons, the theme toggle and search was missing from
  every installed package. Pages still rendered — nothing on them waits for
  JavaScript — so the three features simply never appeared, and never failed
  in this repository, where the file is present. It ships now, and a test
  pins every runtime path against `.gitattributes`.
- The generated introduction listed one bullet per group by its plain name, so
  a versioned API got "Customers" twice with nothing to tell the two apart. It
  names the version, and the at-a-glance table now lists the versions on offer.
- The fallback group name skipped a hardcoded `v1`, `v2`, `v3`, so an API that
  had reached its fourth version filed every endpoint under a group called
  "V4". Any version-shaped segment is skipped now.
- An endpoint page's breadcrumb rebuilt its group's anchor from the group name
  alone, which pointed at nothing once two versions of a group existed. It
  looks the group up instead.

- `ui.logo` and `ui.favicon` were configuration keys nothing read; they are
  wired up now. `ui.accent` was removed rather than wired — the stylesheet is
  prebuilt with literal Tailwind class names, which is what lets the package
  install without Node, so a palette cannot come from config.

- `/.well-known/api-docs` was advertised by `llms.txt`, the index page and the
  README but only existed when the runtime renderer was on, so static
  deployments pointed agents at a dead URL. `DiscoveryEmitter` now writes it,
  and the advertised path is mode-aware.
- Static pages no longer claim `Accept: text/markdown` works — flat files
  cannot negotiate. They point at the `.html`→`.md` swap, which works in both
  modes.
- `ui.snippets` listed four languages when two were implemented, and only cURL
  was rendered. The list is now filtered to what exists, and every configured
  language is shown.
- `ui.dark_mode` had no toggle behind it. There is one now, cycling
  light/dark/system, with a pre-paint script so a chosen theme does not flash.

### Added

- **API versioning.** An API serving `/api/v1/…` next to `/api/v2/…` is
  documented as the two versions it is: the reference is grouped by version
  newest first, every page badges the version it belongs to, and each older
  endpoint links to its replacement — matched on method and version-free path,
  so `GET /api/v1/orders` finds `GET /api/v2/orders` without being told.
  Titles, meta descriptions, OpenAPI tags, Postman folders, group anchors and
  search results all name the version, so two editions of one operation stop
  competing with each other for the same query.
- A derived **Versioning** page: the versions on offer, which one to write
  against, and what the newest added or dropped compared with the one before
  it. Absent when there is only one version.
- `versions.current` and `versions.deprecated` in config, for the two things a
  URL cannot say. Deprecating a version deprecates every endpoint in it, and
  the value — when you give one — is the date it stops answering. A version
  whose every endpoint already carries `@deprecated` is concluded deprecated
  on its own.
- `#[ApiDoc(version: 'v2')]`, for an API that negotiates its version in a
  header and so leaves nothing in the path to read. A `v2.*` route name is
  read too.
- `Ir\ApiVersion` and `Support\Versions`; `Endpoint::$version`,
  `Endpoint::$supersededBy`, `Group::$version` and `ApiSpec::$versions` in the
  IR, and the versions in `/.well-known/api-docs`.

- Authentication schemes can be declared (`auth.scheme`, `auth.headers`) for
  custom header pairs such as client id plus client secret, which leave no
  trace in middleware. Several headers are emitted as several `apiKey` schemes
  required together, and appear in every generated example.
- Code fences in prose pages are highlighted the same way endpoint pages are.

- Authentication is documented as it actually is: bearer, basic, API key or
  OAuth2, with the scopes Passport's `scopes:` and Sanctum's `abilities:`
  middleware name. An API mixing schemes now documents all of them.
- File uploads are typed as binary and their bodies sent as
  `multipart/form-data`, with accepted types and size limits from the rules.
- Responses carry headers; a throttled endpoint returning 429 documents
  `Retry-After` on the response itself.
- `php artisan lusen:check` reports endpoints missing documentation, with
  `--strict` for CI.

- Collect → Extract → IR → Emit pipeline.
- `ApiSpec` intermediate representation, deterministically serialisable.
- `RouteCollector` with include/exclude/middleware/name filtering.
- `RouteExtractor` (path parameters, auth from middleware, fallback groups and
  summaries) and `AttributeExtractor`.
- Attributes: `ApiDoc`, `ApiGroup`, `ApiParam`, `ApiResponse`, `Authenticated`,
  `Hidden`.
- `OpenApiEmitter` (OpenAPI 3.1) and `LlmsTxtEmitter` (`llms.txt`,
  `llms-full.txt`).
- `php artisan lusen:build` with `--only`, `--dry-run` and `--path`.
- Runtime preview at `/docs` with content negotiation, plus a
  `/.well-known/api-docs` discovery document.
- Tailwind v4 docs UI, readable with JavaScript disabled.
- `Support\Examples` — realistic example values from author annotation, enum
  cases, parameter-name hints, then format and type, clamped to `maxLength`.
- `Support\Snippets` — runnable cURL and `fetch` request examples, shown on every
  endpoint in both the HTML page and the Markdown corpus.
- `docs/` showcase site rendered from the package's real Blade views by
  `tools/build-showcase.php`, GitHub Pages ready.
- `FormRequestExtractor` — documents request bodies and query strings from the
  validation rules an application already has. Reads `rules()` by parsing it, so
  a docs build never boots the app or touches a database.
- `Schema::$required`, so nested object schemas carry their required property
  names into OpenAPI.
- A three-state `dark:` variant, so an explicit theme choice can override the
  operating system.
- `HtmlEmitter` — the static site: an index plus one page per endpoint, each with
  its own title, meta description, canonical URL and JSON-LD. The stylesheet is a
  linked, shared file rather than inlined once per page.
- `MarkdownEmitter` — a `.md` mirror of every page, with YAML front matter
  carrying the stable identifiers. Swap `.html` for `.md` on any endpoint URL.
- `Support\Links` — one place that knows every URL, so static and runtime modes
  can address pages differently without the views knowing.
- `Emit\Markdown` — shared Markdown blocks, so the per-page mirror and the
  llms-full corpus cannot drift.
- **Prose pages.** `Page` and `Section` in the IR, `PageCollector` reading
  Markdown with front matter from `resources/docs`, and sidebar sections above
  the endpoint reference.
- `Pages\DefaultPages` — Introduction, Authentication and Errors derived from
  the spec when unwritten. Derived, never invented: no auth page without
  detected middleware, no error table without real statuses.
- Publishable starter pages (`vendor:publish --tag=lusen-pages`) for the
  editorial content that cannot be derived — use cases, quickstart, pagination.
- On-page table of contents, and previous/next across prose and reference alike.
- `Support\MarkdownDocument` (CommonMark + GFM, stable heading anchors) and
  `Support\Navigation`.
- `SitemapEmitter` — `sitemap.xml` over every page. `lastmod` is omitted unless
  a real date is configured; `priority` and `changefreq` are omitted entirely.
- `SearchIndexEmitter` — a prebuilt `search-index.json`, so search needs no
  server, no API key and no third party.
- A derived **Rate limiting** page, built from `throttle` middleware. Named
  limiters are reported by name rather than given an invented number.
- `Ir\RateLimit`, extracted per route; the most restrictive limit wins.
- Build-time syntax highlighting (`Support\Highlighter`) for JSON and shell, so
  code reads correctly with JavaScript disabled and costs no CDN request.
- Labelled code blocks with copy buttons, and semantic response status pills.
- A sticky sidebar that scrolls independently of the content.
- Route-model-bound path parameters (`/users/{user}`) are typed as integers,
  so examples read `/api/users/1` rather than `/api/users/user`.
- `ResourceExtractor` — documents the success response from the API resource an
  action returns, including nested resources, collections, Laravel's `data`
  wrapper and pagination metadata. Reads `toArray()` by parsing it.
- `SchemaType::Any` for fields whose type cannot be determined, so an unknown
  type is never documented as `string`.
- `Support\FieldTypes` — conservative type inference from field names; genuinely
  ambiguous names are left untyped rather than guessed.
- Response bodies are documented as field tables in both HTML and Markdown.
- `Support\Ast`, a shared parse-and-cache layer for the source-reading
  extractors.
- **MCP server** (`php artisan lusen:mcp`) exposing `search_documentation`,
  `list_endpoints`, `get_endpoint`, `read_guide` and `build_request`. Every tool
  returns the same Markdown a reader sees. JSON-RPC 2.0 over stdio, implemented
  directly rather than on an SDK.
- The `/.well-known/api-docs` discovery document advertises the MCP server.
- **Model-driven response types.** `src/Extract/Models/` resolves the fields a
  resource leaves untyped, from model casts and migration columns. Casts win
  where the two disagree; migrations are the only source of nullability.
- `ModelLocator` finds a resource's model from `@mixin`, a `@property` tag, or
  the naming convention — verifying the class is really an Eloquent model.
- Column length limits, enum columns, enum casts and nullability now reach the
  response schema, and generated examples follow the derived types.
- `ControllerExtractor` — summary, description, `@group`, `@deprecated` and
  `@authenticated`/`@unauthenticated` from PHPDoc, plus `@ignore`, `@internal`
  and `@hideFromApiDocs` for exclusions.
- Laravel's scaffolded docblocks are ignored rather than repeated across every
  endpoint; a real description underneath one is still kept.
- Undocumented resource actions get conventional wording — `index` on a
  Customers controller reads "List customers" rather than "Index".
- **Incremental builds.** `Build\BuildCache` reuses endpoints whose route and
  source files are unchanged, roughly 10x faster on a warm build. Files are
  compared by content rather than modification time, so fresh checkouts and CI
  runners still benefit.
- Dependencies are recorded at the point files are opened, so a nested
  resource or a migration behind a model invalidates the right endpoint.
- `lusen:build --fresh` ignores the cache; builds report how much was reused.
- `PostmanEmitter` — a v2.1 collection with a `{{baseUrl}}` variable, bearer
  auth declared once, optional query parameters present but disabled, body
  parameters documented as a Markdown table, and documented responses imported
  as saved examples. The collection id is stable, so re-importing updates it
  rather than duplicating it.
