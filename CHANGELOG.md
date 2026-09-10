# Changelog

All notable changes to this project are documented here.

## 0.7.1 — 2026-09-10

### Added

- **`lusen:check` reports a FormRequest it read no fields from.** The two read
  failures below were silent for a day because nothing asks whether an
  endpoint still has a request body — the page just stopped having one. The
  check now names the class when an action type-hints a FormRequest and no
  parameters came out of it, which is the moment to look rather than the day
  after.

  It asks about the FormRequest rather than about the body on purpose. "A POST
  with no documented body" would flag every state transition in an API —
  `POST /orders/{id}/ready` takes nothing and is right to — and nobody could
  ever clear it, which is the fastest way to teach a team to stop reading the
  output. An action that declares a FormRequest and yields no fields is a
  different claim and always a real one. Endpoints that take no input, and
  those documented by hand with `#[ApiParam]`, are not reported.

  `Endpoint` carries the class for it (`requestClass`), so the finding
  survives the incremental cache. Stored caches from before this are
  discarded once, since an entry that predates the field would report clean.

### Fixed

- **A `rules()` that composed its answer documented no request body at all.**
  `return array_merge($this->sharedRules(), [...])` is what a FormRequest of
  any size looks like once its rules are shared with a base class or a trait,
  and the reader only understood `return [...]`. Anything else read as no
  rules, which is not a thinner page but no page: the parameter table, the
  fields in the example body, the OpenAPI `requestBody` and the playground
  form all come off that one array. An endpoint's entire reference could
  disappear because somebody factored three lines out of two requests, and
  nothing failed while it happened.

  Composition is now followed where it can be read: `array_merge()`, the
  spread that spells the same thing, `+`, and the method on the other side of
  the call — `parent::rules()` into a base class, `$this->sharedRules()` into
  a trait. The files those live in are recorded as the endpoint's sources, so
  the incremental cache notices when one is edited.

- **A rule string built by concatenation lost the whole field.**
  `'required|string|max:'.self::LIMIT` and `'in:'.implode(',', self::TYPES)`
  are the ordinary way a limit reaches a rule, and neither is a literal, so
  the field they belonged to was dropped rather than typed. Class constants are read
  (which defines a class, and constructs nothing — the same bargain
  `Rule::enum` already made), and where a piece genuinely cannot be read it
  now costs that one rule instead of the field: `'in:'.$variable` documents as
  `required|string` with no enum, rather than as nothing at all, or as an enum
  of one empty string.

## 0.7.0 — 2026-09-09

### Added

- **Laravel 13.** A fresh `laravel new` is Laravel 13 now, and the package
  could not be installed on one at all. The constraints admit `^13.0`
  alongside `^12.0`, the suite runs against both in CI (Laravel 13 on PHP 8.3
  and 8.4, since the framework itself needs 8.3), and Testbench 11 and Pest
  5 are allowed for the same reason.

### Fixed

- **`Route::apiResource` documented `update` twice under one id, and kept one.**
  Laravel registers `update` as `PUT|PATCH` under a single route name, and a
  route name is an endpoint's id — so the two verbs became two endpoints with
  one identity. The OpenAPI document carried the same `operationId` twice,
  which is invalid; the static site wrote one page and then overwrote it with
  the other; the search index offered two results for one URL; and a warm
  rebuild reported four files written when nothing had changed, because the
  two were taking turns. The verb Laravel lists first keeps the bare name, so
  a route documented for years keeps its anchors, and every further verb is
  suffixed with its own: `customers.update` and `customers.update.patch`.
  Found by installing the package into a fresh application, which is what
  the next person will do.
- **A `@response` written as JSON was misread as a type.** `@response 422
  {"message": "…"}` is how Scribe spells a response, and a codebase arriving
  from that tool has one on every action. The body was handed to the type
  reader, which made an `any` schema of it and generated the word "example"
  as its example, so every such response documented nothing. The value is
  now the example and the shape is read off it.
- **A scope was read and then shown nowhere a person looks.** Passport's
  `scopes:` and Sanctum's `abilities:` were extracted, and then the page said
  "send a bearer token" and the Markdown twin said "(bearer token)", with the
  scope surviving only as an OpenAPI security requirement — and not even
  there for a bearer scheme, which OpenAPI gives no scopes. Both surfaces now
  say "a bearer token with the `orders:write` scope", from one sentence the
  scheme builds, so they cannot drift.
- **Two ways of returning a resource documented no response.**
  `(new OrderResource($order))->response()->setStatusCode(201)` is the idiom
  Laravel's own documentation gives for a 201, and
  `response()->json(new OrderResource($order), 201)` is the other one people
  write; the return analyser saw neither as a resource, so every store
  action written either way had no response at all. Both are followed to
  the resource now, and the status the chain or the wrapper adds is kept.
  The second is documented without the `data` wrapper, because
  `json_encode()` serialises a resource without `toResponse()` and so
  without it - a recording of exactly that call is what showed the schema
  and the body disagreeing.
- **`lusen:build --fresh` was undone by the next build.** It switched the
  cache off for the run, which re-analysed everything and stored none of it,
  so the stale entries were still there for the next ordinary build to hand
  back. A fresh build now ignores what is stored and stores what it found.
- **A new recording did not reach a cached endpoint.** `lusen:record`
  followed by `lusen:build` could change nothing: the per-endpoint
  fingerprint covers the source files an endpoint was read from, and a
  recording is a JSON file no extractor parses. The cache is now keyed on
  the recordings file as well, so recording anything re-analyses
  everything, which is what recording means.

## 0.6.4 — 2026-09-08

### Fixed

- **Runtime mode advertised URLs it did not serve.** Every runtime page told
  the script the search index lived at `search-index.json`, and nothing
  answered there — so the search box, which only appears once the index has
  been fetched, never appeared at all. The discovery document named `/docs.md`
  and `/docs/spec.json`, and nothing answered there either. And `llms.txt`
  linked every endpoint to a `.md` twin that only static output wrote. All of
  it is served now: the index's Markdown twin at `/docs.md`, the spec, the
  search index, the Postman collection, and the Markdown twin of every
  endpoint, group and page at the same path static output writes it to.
- **The two modes built two discovery documents, and they had drifted.** One
  named surfaces the other served; both named a sitemap whether or not one had
  been written, which without a canonical origin it never is. There is one
  emitter now, told which surfaces exist, and a test that fetches every URL it
  names. A surface whose emitter is switched off is no longer listed either.

## 0.6.3 — 2026-09-08

### Fixed

- **Every field below the top level of a request body lost its description.**
  A docblock above `items.*.product_id` reached the parameter it described and
  went no further: the nested walk carried the type, the constraints and
  whether the field was required, and dropped the one sentence a caller cannot
  work out from any of them. It travels on the schema now, which is what
  survives the walk, so a nested table says what a top-level one always did.
- **A docblock's first sentence ran into its second.** DocBlock takes the full
  stop off a summary because a page title carries none, and a field's docblock
  has no title: the two paragraphs met in the description column as "never
  returns an error `price` is still the full unit price". The stop goes back
  on before the join.

## 0.6.2 — 2026-09-08

### Fixed

- **A group description was printed rather than rendered.** It is the landing
  copy for the page most likely to be somebody's first, so authors write it as
  prose: headings, a numbered workflow, a path in backticks. All of it arrived
  on the page as literal asterisks and flattened into one paragraph, which is
  what an endpoint's description already avoided by going through the Markdown
  converter. The group page now does the same.

  The index and the meta description take only the opening paragraph
  (`Group::lede()`). A description that narrates six steps is right on the
  group's own page and wrong in a list of every group, where it pushes the
  next one off the screen, and wrong again in a meta description, where it is
  truncated mid-workflow.
- **A group spanning two controllers took the description of whichever sorted
  first.** A route reaching across into another controller brought that
  controller's copy with it and spoke for the whole group. The description
  most of the group carries wins now, which only decides anything in exactly
  that case and decides it the way a reader would.

## 0.6.1 — 2026-09-08

### Added

- **Groups and operations can say where they belong.** `#[ApiGroup(order: 1)]`
  places a group in the navigation and `#[ApiDoc(order: 1)]` places an
  operation inside its group. Alphabetical is the right default for a
  reference, and the wrong one for a group whose operations are the steps of a
  sequence: an onboarding flow that starts at "Add a bank account" because B
  sorts before R reads as a list of unrelated calls rather than a path through
  the product.

  Ordering is partial. What states a place takes it, lowest first; everything
  else follows behind in the order it had before. So naming the three groups a
  reader should meet first costs three numbers, not twenty-three, and adding a
  controller next month still needs no edit anywhere.

  Scramble's `#[Group(weight: 1)]` is read as the same thing. An API that
  already ordered its groups for Scramble arrives with that order intact,
  which is the point of reading another tool's attributes at all.
- **A page per group.** `groups/{slug}.html`, with a Markdown twin at
  `groups/{slug}.md`. The index lists every group and each endpoint page
  answers one operation, and neither is the page for somebody asking what can
  be done with orders at all — the question a search engine or an assistant is
  asked before it knows an operation's name. The page opens with the group's
  description, states the base URL, the version and whether a credential is
  needed, and lists the operations. It lists rather than repeats, so it does
  not compete with the endpoint pages for their own queries. It sits in the
  sidebar, the sitemap, the search index and the reading order ahead of its
  operations, and carries `CollectionPage` structured data naming each of
  them. A group nobody described is summarised by naming its operations rather
  than left blank.

  Group links — the sidebar heading, the breadcrumb on every endpoint page,
  the JSON-LD breadcrumb — now point at these pages rather than at a section
  of the index. The index sections keep their anchors, so an existing link
  still lands.

### Fixed

- **A group's description never reached the page.** The second argument of
  `#[ApiGroup('Users', 'Create and read user accounts.')]` is documented as
  the group's landing copy, and the index, the Markdown mirror, `llms.txt`,
  the OpenAPI tag and the Postman folder all render it — but nothing carried
  it from the attribute to the group. Groups are derived from their endpoints
  after extraction, and an endpoint had nowhere to hold it, so every group in
  every build was undescribed. It now travels on the endpoint the way the
  group's order does. Scramble's `#[Group(description: …)]` was lost the same
  way and is read too.

## 0.6.0 — 2026-09-07

### Added

- **`php artisan lusen:record`.** A generated example satisfies the schema and
  nothing else: it says `"status": "string"` where your API says `"paid"`, and
  a reader who copies one into a client has learned the shape without ever
  seeing the thing. Your test suite is already producing real responses, so
  this borrows them — it runs your suite with capture switched on, writes what
  came back to `.lusen-recordings.json`, and the build uses those bodies.

  Recording happens during your tests because they have already booted the
  application, which a docs build never does. The build only ever reads the
  file, so `lusen:build` still runs against a checkout with no `.env`.

  The first recording of an operation wins, so a suite with random fixtures
  does not rewrite the file on every CI run — `--fresh` asks for all of them
  again. Fields named in `record.redact` have their values replaced wherever
  they appear, because a suite that mints a real-looking token would otherwise
  mint it into a file you commit. An `#[ApiResponse(example: …)]` still wins: it
  was written on purpose.
- **Python and Go request examples.** `python` (requests) and `go` (net/http)
  join cURL, JavaScript and the two PHP clients. Supported and off by default:
  six tabs is a strip nobody reads, and which two your readers actually write
  is a question only you can answer. All of them render the same assembled
  request, so no tab can drift from another or from the playground.

### Fixed

- **A nested request body documented nothing about its contents.** A body with
  an `items` array rendered as `items · array · yes` and stopped. The fields
  inside were in the spec the whole time — `RuleTree` exists to turn
  `items.*.product_id` into an array of objects — and the HTML table iterated
  top-level parameters and never descended, so nobody could build the request
  from the page. The Markdown mirror already got this right, which was the real
  defect: two implementations of one walk, and only one of them wired into both
  surfaces. There is one now, and the request and response tables are the same
  table.
- **The second tab of a code block sat flush against its heading.** Blocks
  carry a top margin that separates the first one from "Example request", and a
  rule zeroed it on every block after the first so stacked blocks would not
  double-space. They never stack in tabbed mode, so the rule did nothing until
  somebody clicked the second tab.
- **The full URL's host failed contrast in both themes**, at 2.6:1 on white and
  4.2:1 on slate-950, against the 4.5:1 small text needs.

### Changed

- **The line under an operation.** Whether a call needs a token now sits
  directly under the method and path rather than trailing the URL — it is part
  of what the operation is, not a footnote to where it lives. The host and the
  path are no longer one undifferentiated string: the host says which
  environment the call goes to, the path is what the page is about, and reading
  them apart is what stops somebody firing a documented call at production
  because they never noticed the word "sandbox". There is a copy button, which
  reads the URL from the page rather than an attribute so switching the base URL
  changes what it copies. And the full stop after the URL is gone, because a
  period pressed against the last character of a URL comes along with a
  drag-select.
- **Field tables mute the parent path.** `items[].product_id` renders with
  `items[].` in the muted colour and `product_id` at full strength, aligned
  rather than indented — the path already says where a field lives, and an
  indent says it a second time at the cost of the aligned left edge that makes
  a column scannable. The whole path stays one string to search for and to copy.

### Upgrading

If you have published the views (`vendor:publish --tag=lusen-views`), your copy
of `partials/schema-table.blade.php` and `partials/endpoint.blade.php` shadows
the package's, so **the nested request body fix will not reach you**. The table
partial now takes `rows` rather than a `schema`. Re-publish, or delete the two
files if you never customised them.

## 0.5.3 — 2026-09-07

### Fixed

- **A site with a logo configured said its own name twice.** The logo carried
  the API's title as its `alt` text and the title was printed again directly
  after it, so the name appeared twice stacked the moment the image failed to
  load, and was read out twice to a screen reader every time. An image sitting
  beside its own name in text is decorative, so the `alt` is empty now and the
  two sit on one row — 24px against 14px text, with the name truncating rather
  than squashing the logo.

### Added

- Lusen has a mark. It is the profile of the plateau it is named after: high
  ground above Kukës, an undercut face, the valley far below. It inherits
  `currentColor` for use beside text, and there is a badge form for a favicon.
  Both are export-ignored — a consumer installs a documentation generator, not
  its logo.

## 0.5.2 — 2026-09-07

### Added

- **What changed inside an operation between two versions.** The versioning
  page could already name the operations `v2` added and the ones `v1` had that
  it dropped, and then stopped at "the other eight exist in both versions at
  the same path" — the sentence a reader migrating has the most questions
  about. Both editions of all eight are in the spec, so the answer was
  derivable and simply never asked for.

  `Diff\VersionDiff` pairs the two editions on the operation key — method plus
  the version-free path — which is the same key supersession uses, so "changed
  since v1" and "superseded by v2" cannot disagree about which two endpoints
  are the same operation. It appears on the versioning page, above the
  parameter table on an endpoint page, and in the Markdown mirror and
  `llms-full.txt`. An operation that changed in no way is left out: a migration
  list naming forty untouched endpoints buries the three that moved.
- **Somewhere for a site to point its documentation back at its product.** API
  documentation is often the most-read thing a company publishes and the only
  page a technical evaluator opens before deciding, and it was a dead end.
  Three surfaces under a new `product` config section, all off until
  configured: an announcement strip above everything on the page, a link back
  to the site in the sidebar and the footer, and one button under the
  previous/next links and at the end of the index.

  Not in the sidebar. That column is how a reader gets between pages, and a
  coloured button in it competes with the navigation on every screen of every
  page. And none of it reaches `llms.txt`, the Markdown mirrors or the OpenAPI
  document — those exist so a model can learn how the API works, and a call to
  action retrieved as though it were part of the reference is noise in
  somebody's context window at best.

### Changed

- **Shared response shapes are defined once and referenced.** Every endpoint
  returning a customer wrote the whole customer out again, so a generated
  client emitted six near-identical `Customer` types with nothing to tell it
  they were the same thing. `components/schemas` is what definitions are for,
  and 3.1 schemas are real JSON Schema, so a `$ref` costs no fidelity. The
  showcase document drops from 83 KB to 73 KB, nested shapes included:
  `Order.items` points at `OrderLine`.

  The name comes from the class the shape was read out of, because it becomes
  a type name in somebody's generated client and is therefore a stability
  contract like an endpoint id. Two versions of one resource that have since
  diverged are qualified — `V1Customer`, `V2Customer` — and anything that
  cannot be named honestly stays inline. A long document beats a wrong type
  name.
- **Scramble is supported without anybody configuring it.** Reading another
  tool's attributes was configuration, and Laravel merges a published config
  over the package's one key at a time: an application adding its own
  namespace to `attributes.external` replaced the list rather than extending
  it, and any application that published `config/lusen.php` before 0.3.0 has an
  `extractors` array without the reader in it and got no foreign attributes at
  all. Neither failed; both looked like the feature not working. The
  namespaces Lusen knows are a constant now, config adds to them, and a
  configured pipeline missing the reader gets it back.
  `attributes.read_external` is the switch for anyone who wants them ignored.
- Response bodies are tabbed by status code alone — `201`, `422` — rather than
  by code and reason phrase. A tab strip reading "201 Created" and "422
  Unprocessable Entity" spends its width on words every reader already knows,
  and the phrase is still on the status table directly above.
- The rail's links to a page's other representations each carry an icon, and
  the footer sentence does not: five pieces of small grey text in a vertical
  list are scanned, while a glyph wedged into "This page as Markdown, or the
  whole API as OpenAPI" interrupts a sentence.

## 0.5.1 — 2026-09-06

### Added

- **`php artisan lusen:diff`.** Compares this build against a recorded baseline
  and reports what changed — and which of it breaks a client. The docs already
  hold every parameter, every response field and every validation rule, which
  makes them the one artefact in a repository that can answer that question
  before a consumer does. Record a baseline with `--save`, commit it, run
  `--strict` on pull requests.

  Changes come back graded. **Breaking** is a removed endpoint or response
  field, a new required parameter, a changed type, a tightened bound, a newly
  required scope, or an operation id that moved — an id change is reported as
  a rename rather than as a removal beside an unrelated addition, because the
  thing that broke is the anchor and the `operationId`, not the request.
  **Added** is new surface. **Notice** is everything that cannot break a
  working client.

  The grading is the feature. A field the extractors could not type before and
  can type now is a better docs build, not an API change, so any transition to
  or from `any` is a Notice — without that, adding a cast to a model would
  fail CI on endpoints nobody touched. Losing a documented error status is
  docs drift; losing a documented success status is a contract change. An
  endpoint that stops requiring authentication breaks no client and cannot
  fail a build, but it is the last change that should go out unnoticed, so it
  is reported in those words.

  The baseline is the IR itself, pretty-printed, so it reads as a diff in a
  pull request. `sourceFiles` is stripped: those are absolute paths kept for
  the incremental cache, and in a committed file they are one developer's home
  directory and a spurious diff on every other machine.
- **Ask an assistant, with the question already written.** Every endpoint page,
  every prose page and the index carry a link that hands the page to ChatGPT or
  Claude with the prompt composed: read this URL, answer from what it says, and
  say so when it does not. The link names the page's Markdown twin rather than
  the rendered HTML, so the model reads the source instead of a screenshot of a
  layout; from the index it names `llms-full.txt`, because somebody asking about
  the API as a whole wants the model to have the whole thing.

  Both the providers and the prompt are configuration. Assistants change their
  deep-link shape without warning and new ones appear, and a docs site should
  not wait for a release of this package to keep a button working.
- **PHP request examples, in both flavours.** `PHP (Laravel)` for an application
  with Laravel's HTTP client, `PHP (Guzzle)` for everything else, alongside cURL
  and JavaScript. This package documents Laravel applications and whoever
  integrates with one is usually writing PHP too, so both are on by default.
  They render `RequestModel` like every other snippet, so they cannot drift from
  the cURL block above them or from the request the playground sends, and the
  highlighter has learned PHP so no tab sits grey beside a coloured one.

### Changed

- Response bodies are tabbed by status code alone — `201`, `422` — rather than
  by code and reason phrase. A tab strip reading "201 Created" and "422
  Unprocessable Entity" spends its width on words every reader already knows,
  and the phrase is still on the status table directly above, where it is the
  first thing said about a status rather than a repetition of it.
- The page's other representations — Markdown, OpenAPI, Copy for an LLM, the
  assistants and the edit link — each carry an icon. Five pieces of small grey
  text in a row have to be read; a document, braces, a clipboard, a sparkle and
  a pencil can be scanned. Inline SVG from one partial, inheriting
  `currentColor`, so no second request and no second colour rule.

## 0.5.0 — 2026-09-05

Sending the request, and the two-column reference every developer already
knows how to read.

### Added

- **Try it.** A dialog on every endpoint page that sends the request and shows
  what came back - status, time, size and body. It is a `<dialog>` where the
  browser has one, which is what brings the focus trap, Escape-to-close and
  the dimmed page without any of that being written by hand; where it is not,
  the same panel opens in place under the button. **Off by default**, and
  limited to `GET` when it is on: a Send button beside a write, on a page
  whose base URL is production, is a mistake waiting for a distracted reader.
  `#[ApiDoc(tryIt: false)]` withdraws one endpoint; nothing can opt an
  endpoint *into* a playground the site has turned off.

  There is no proxy, because a package of flat files has nowhere to put one.
  The request goes straight from the browser to the API, which works
  same-origin - runtime mode, or docs on the API's own domain - and otherwise
  needs the API to allow the docs origin. When it does not, the page says so
  in those words, names both origins and prints the two headers that would fix
  it. A browser reports a blocked response and a dead API identically, and
  "Failed to fetch" sends people to look at the wrong thing.

  The dialog repeats what a reader needs before they send - the operation, the
  host it goes to, the scheme, the rate limit, whether it is deprecated, and
  every field's type and description - because it covers the page that says
  all that. Beside the form, the call as it currently stands, updated as the
  fields are edited, so nobody has to press send to find out what send would
  do.
- **A *Set up to test* section on the Authentication page.** The credential is
  typed once there and every Try it on the site sends it; the fields in each
  dialog are the same store, so changing one changes the other. An API that
  wants a bearer token wants the same token on every endpoint, and retyping it
  per page is what stops people using a playground by the third endpoint.

  `persist_token` decides how long it lives: `session` until the tab closes -
  the default - `none` for in-memory only, or `local`, which shows the reader a
  *Remember on this browser* checkbox rather than deciding for them. Lifetime
  is the only lever there is: no browser store hides a value from scripts on
  the same origin, and a token that has to be attached to a fetch cannot be
  hidden from the code attaching it. So it is scoped to the base URL it is for,
  never written into a copied example, masked in the preview, and one *Forget*
  button clears it.
- **Response bodies are tabbed by status.** An endpoint documenting a 200, a
  404 and a 422 used to put two bodies nobody asked for between the reader and
  the one they wanted.

- **Ask an assistant, with the question already written.** Every page offers
  *Ask ChatGPT* and *Ask Claude*; the index points them at `llms-full.txt`, so
  a question about the API as a whole starts with the whole API. The link
  carries a prompt naming the page's Markdown twin rather than the rendered
  page, because that is the file a model can read without paying for markup.

  Which assistants, and what they are asked, is configuration.
  `ui.ask_ai.providers` is label => URL template with `{prompt}` in it, so a
  provider changing its deep-link shape or a site preferring one nobody here
  has heard of is a config edit rather than a release; `ui.ask_ai.prompt`
  takes `{url}`, `{subject}` and `{title}`. An empty provider list turns the
  buttons off. Nothing renders at all without `seo.canonical_origin`: the
  prompt asks a model to fetch an address, and a relative path is not one.

### Fixed

- The JavaScript request example was never highlighted. `Highlighter` knew
  JSON and shell, and everything else fell through to plain escaped text - so
  every endpoint page showed a coloured cURL block above a grey JavaScript one,
  which reads as a bug in the docs rather than as a limit of the tokenizer. It
  is written for what `Snippets::javascript()` emits rather than for the
  language, which is the same bargain the other two make.

### Changed

- **Endpoint pages are two columns from `xl` up**: what the endpoint is on the
  left, what a call to it looks like on the right, with the examples sticky
  beside the reference. The parameter you are reading about and the example
  that uses it are now on screen together rather than a scroll apart. One
  column below that, in document order - reference first, then the examples.
- The theme toggle moved to the footer. It is set once and then never touched
  again, and it was holding the place at the top of the sidebar that belongs to
  what the reader came for.
- The contents column on endpoint pages went with it: the margin belongs to
  the call, and a list of four links to sections already on screen was paying
  for it with the width the parameter table needed. Prose pages keep theirs,
  and every section anchor stays exactly where it was.
- **The page is 96rem wide, not 80.** An endpoint page is three things side by
  side now - navigation, reference, examples - and at 80rem the parameter
  table paid for it, wrapping a type like "string, one of pending, paid,
  shipped, refunded" over three lines. The sidebar widens with it, so
  "Register a webhook endpo…" is a whole summary again. Nothing changes below
  1280px, where the viewport was always the constraint.
- **Static output links the script instead of inlining it.** It carries the
  playground now, and inlining put tens of kilobytes into every endpoint page.
  The runtime renderer still inlines: one page, no second request to save.
- `Support\RequestModel` assembles a request; `Snippets` renders what it
  returns. The page hands the browser the same model, so the request a reader
  copies and the request a reader sends cannot drift apart. `Snippets::url()`
  moved to `RequestModel::url()`.

## 0.4.0 — 2026-09-04

The reading experience, which had been built for one screen and one reader.

### Added

- **Navigation on every screen size.** The sidebar was `hidden lg:block` and
  the search box lived inside it, so a reader who arrived on a phone — from a
  search result, which is the common case for a per-endpoint page — had no
  navigation, no search and no way out of the page they landed on. The
  navigation is now rendered once and moved by CSS: a column beside the content
  on wide screens, the end of the document on narrow ones, with a bar at the
  top of the page linking to it. The script upgrades that link to a panel, so
  reaching the navigation does not mean losing your place. Nothing here needs
  JavaScript to work.
- **A contents column beside every page.** Prose pages had a boxed table of
  contents; endpoint pages had nothing, and a wide screen left a third of
  itself empty. Both now carry one, along with the page's other
  representations. Every section of an endpoint page — each parameter table,
  the example, the responses — has a stable anchor derived from the endpoint
  id, so `#users-index-responses` is a citable deep link rather than a place
  to scroll to.
- **Copy for an LLM**, on every page. It copies the page's Markdown twin, not
  the rendered HTML: pasting documentation into a model is what people do now,
  and the rendered page makes the model pay for navigation and markup it
  cannot use.
- **Tabbed request examples.** They ship stacked and labelled — that is what
  reads with no JavaScript, and what a model retrieving the HTML sees — and
  become tabs where the script runs, because two languages stacked push the
  responses a screen and a half down the page. The chosen language is
  remembered across pages.
- **A base URL switcher**, which `config/lusen.php` had described for two
  releases without one existing. `servers` now produces a control that rewrites
  the base URL everywhere it appears on the page, so a reader working against
  a sandbox copies a sandbox request.
- **`ui.edit_url`**, putting an *Edit this page* link beside every page
  somebody wrote. `{path}` is replaced with the file's path relative to the
  project root. Generated pages have no file behind them and never get one.
- **`lusen:check --json`**, so coverage can go in a dashboard or a PR comment
  without parsing two-column console output.
- Search keyboard handling: `⌘K` or `/` to focus, arrows to move through the
  results, enter to open one. The results are a proper combobox listbox now
  rather than a list with a listbox role and nothing to select.
- Print styles, and `prefers-reduced-motion` is respected. What prints is the
  endpoint, not thirty navigation links and a search box that cannot search;
  every request example prints, since paper has no tabs to switch.

### Fixed

- Descriptions written in Markdown reached places no renderer sees them.
  `<meta name="description">`, JSON-LD, `llms.txt`, the search index and the
  index listing all printed the source text, so a snippet under a search
  result read *Send the `Idempotency-Key` header* — backticks included.
  `Str::summarise()` now reduces Markdown to the text it renders as; the page
  body and the Markdown mirror are untouched. Underscores are left alone,
  since `customer_id` is more common in API prose than `_emphasis_`.
- A prose page recorded the absolute path it was read from, which put a
  machine-specific value in an IR that has to serialise identically everywhere
  — and handed it to anyone who asked `/docs` for JSON. Pages now record where
  they were written relative to the project root.

## 0.3.2 — 2026-09-04

### Fixed

- The on-page table of contents had a bottom margin and nothing on top, so it
  sat hard against the heading above it on every prose page.

## 0.3.1 — 2026-09-04

Two fixes, both found by pointing 0.3.0 at a real application and reading
what it produced rather than trusting that it worked.

### Fixed

- Middleware declared in a group was invisible. `gatherMiddleware()` reports
  middleware as it was written, so a route in Laravel's `api` group reported
  the string `api` and everything inside it — the throttle every request is
  subject to, and sometimes the authentication — went undocumented. An
  application throttling its whole API had every endpoint claiming to declare
  no limit. Groups are resolved to their contents now, with the group name
  kept so a `routes.middleware` filter still matches it.
- Header credentials declared in `auth.headers` were dropped whenever
  middleware identified a scheme, and unreachable even when they survived,
  because a scheme's headers were only consulted for `apiKey`. They are
  additive, not alternative: an API wanting a bearer token *and* a client id
  pair wants all three headers on every request. They now appear in the
  authentication page, the label and every generated request, and the OpenAPI
  emitter defines a component for each one rather than referencing keys it
  never declared.
- The single-limit sentence read "Every endpoint allows **Rate limited by the
  `api-global` limiter**", which only ever parsed for a numeric limit.

## 0.3.0 — 2026-09-04

### Added

- `#[ApiResponse(type:)]`, taking the same grammar as `@response`, so a
  response body can be described with Lusen's own attribute rather than
  another tool's. Without it the compatibility layer was the only way to
  attach a schema to a status, which made a shim for people leaving another
  tool look like the path for people arriving. An example is generated from
  the type when none is written.

### Fixed

- A description written with a list, an indented block or a fenced example
  arrived as one run-on sentence. `DocBlock` joined every line of a paragraph
  with a space, so structure the author put there deliberately was destroyed
  before anything could render it. Descriptions now keep the shape they were
  written in, and endpoint pages render them as Markdown rather than printing
  them as text — so a list is a list, and backticks are code.

### Changed

- `ScrambleExtractor` is now `ExternalAttributeExtractor`, and the attribute
  namespaces it reads are configuration (`attributes.external`) rather than a
  constant in its source. Naming one tool in a class name made a general
  mechanism look like a special case, and left no way to point it at a
  different tool without changing the package. The default still reads what
  0.2.0 read, so nothing stops working; a published config needs
  `Lusen\Extract\ExternalAttributeExtractor::class` in its `extractors` list
  in place of the old name.
- Both response attributes are decoded by one `ResponseFactory`, so an
  external `#[Response]` and Lusen's `#[ApiResponse]` cannot describe the same
  declaration differently.

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
