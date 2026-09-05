# Writing documentation with Lusen

Lusen works out most of your API from code you have already written. This guide
covers the rest: the parts only you can write, where to put them, and how they
interact with what is generated.

---

## The one rule

**What you write always wins.** Lusen never overwrites, edits or argues with
something you stated explicitly.

Everything resolves in the same order, from strongest to weakest:

| | Source | Example |
| --- | --- | --- |
| 1 | **Attributes** | `#[ApiDoc(summary: 'List users')]` |
| 2 | **Docblocks** | `/** List users. */` |
| 3 | **Your code** | `rules()`, `toArray()`, model casts, migrations, middleware |
| 4 | **Conventions** | `created_at` is a timestamp; `index` on a Customers controller is "List customers" |
| 5 | **Nothing** | the field documents as `any`, rather than as a guess |

Level 5 is deliberate. Lusen would rather say "we don't know" than tell a reader
something plausible and wrong — an unknown type documented as `string` is a
guess someone builds a client against.

---

## What you write vs what Lusen works out

**Lusen works out**, with no input from you:

- Endpoints, verbs, paths and path parameters — from your routes
- Which endpoints need credentials, which scheme, and which scopes — from middleware
- Rate limits — from `throttle` middleware
- Request bodies and query strings — from FormRequest `rules()`
- Response shapes — from `JsonResource::toArray()`
- Field types, lengths, nullability and enums — from model casts and migrations
- Groups — from your controllers or URI segments
- Introduction, Versioning, Authentication, Errors and Rate limiting pages —
  from all of the above

**You write:**

- Prose pages — what the API is *for*, use cases, guides, anything editorial
- Summaries and descriptions, if your docblocks don't already have them
- Examples, where a generated one isn't good enough
- Anything inference cannot see — a header a middleware adds, a response only
  returned in production, a parameter read straight off the request

---

## Prose pages

Endpoint reference answers *"how do I call this"*. Pages answer *"what is this
for, and why would I"* — which is the question a search engine or an AI
assistant is usually asked first. An API documented only as a list of
operations cannot be found by anyone who doesn't already know an operation's
name.

Pages are Markdown files in `resources/docs`. Get the starters:

```bash
php artisan vendor:publish --tag=lusen-pages
```

### Front matter

All of it is optional.

```markdown
---
title: Handling webhooks
section: Guides
order: 20
description: Verify a signature and respond in time.
---

We POST a signed payload to your endpoint for each subscribed event...
```

| Key | Default | Notes |
| --- | --- | --- |
| `title` | the first `# heading`, else the filename | Becomes the `<h1>`, the sidebar entry and the `<title>` |
| `section` | the parent directory name, else `Getting started` | Groups pages in the sidebar |
| `order` | `0` | Sorts within a section; ties break alphabetically |
| `description` | the first paragraph | Used for the meta description and the `llms.txt` entry |
| `id` | the filename slug | The URL and the stable anchor. Changing it breaks inbound links |

A file in a subdirectory takes the directory name as its section, so
`resources/docs/guides/webhooks.md` lands under **Guides** with no front matter
at all.

Section order comes from config; anything unlisted follows alphabetically:

```php
'pages' => [
    'sections' => ['Getting started', 'Guides', 'Reference'],
],
```

### Replacing a generated page

Lusen generates **Introduction**, **Versioning**, **Authentication**, **Errors**
and **Rate limiting** when you haven't written them. To take one over, write a
file with the same id:

```markdown
---
title: Introduction
---

Our own words, not the generated summary.
```

Nothing merges — yours replaces it entirely. To keep the generated ones and add
nothing else, write no files. To turn generation off completely:

```php
'pages' => ['generate' => false],
```

Note that generated pages are **derived, never invented**: there is no
Authentication page if nothing in your app requires credentials, and the Errors
table lists only statuses your endpoints actually return. If a generated page
looks thin, that usually means the codebase is thin on that subject.

### Writing pages well

The docs are read by people, crawlers and models, and the same things help all
three:

- **Lead with the answer.** The first paragraph becomes your meta description
  and your `llms.txt` entry. Make it a sentence that stands alone.
- **Use `##` headings.** They become the on-page table of contents and stable
  deep-link anchors. Anchors are derived from heading text, so renaming a
  heading breaks links to it.
- **Be concrete.** "Three things teams build with this API" is findable; "About
  our platform" is not.
- **Show the calls.** Fenced code blocks are highlighted at build time. Use
  ` ```bash ` and ` ```json `.

---

## Examples

Examples are the single most useful thing on an endpoint page. A reader pastes
them; a model reads them to learn the shape of a call. Lusen generates one for
every endpoint, so **you only write one when the generated one is not good
enough**.

### What you get for free

Generated examples are schema-valid by construction. Values come from, in
order: an example you supplied, the first case of an enum, the field's name,
its format, then its type — and every string is checked against the field's
own `maxLength`, `minLength` and `regex` rules before being used.

```php
// StoreCustomerRequest
'email'  => 'required|email|max:255',
'status' => ['required', Rule::enum(CustomerStatus::class)],
'coupon' => ['nullable', 'string', 'regex:/^[A-Z0-9]+$/'],
```

produces

```json
{
  "email": "jane@example.com",
  "status": "active",
  "coupon": "COUPON"
}
```

You do not need to write that by hand.

### When to write one yourself

Write an example when the *realistic* value carries meaning the schema cannot:
a monetary amount in minor units, an ID format specific to your domain, a
payload whose fields relate to each other.

**A response example:**

```php
use Lusen\Attributes\ApiResponse;

#[ApiResponse(200, 'The order.', example: [
    'data' => [
        'id' => 8801,
        'total' => 4200,          // minor units — worth showing
        'currency' => 'USD',
        'items' => [
            ['product_id' => 12, 'quantity' => 2, 'unit_price' => 1999],
        ],
    ],
])]
#[ApiResponse(422, 'Validation failed.', example: [
    'message' => 'The email has already been taken.',
    'errors' => ['email' => ['The email has already been taken.']],
])]
public function show(Order $order): OrderResource
```

Repeat the attribute per status. A documented status replaces the generated one
for that status; other statuses keep theirs.

**A single parameter's example:**

```php
use Lusen\Attributes\ApiParam;

#[ApiParam('per_page', in: 'query', type: 'integer', example: 25)]
#[ApiParam('reference', example: 'REF-4821', description: 'Your own order reference.')]
```

**A literal in a resource** is used as-is, because it *is* the value:

```php
// UserResource::toArray()
'kind' => 'user',   // documented as string, with "user" as the example
```

### Errors are worth an example

Most APIs document success and leave failure to guesswork. One `422` example
with your real error envelope is often the highest-value thing you can add — it
is also what the generated **Errors** page will show.

---

## Attributes from another tool

A codebase documented once should not have to be documented again to change
tools, so Lusen reads documentation attributes it recognises from the
namespaces listed in `attributes.external`:

| Attribute | What Lusen takes |
| --- | --- |
| `#[Group(name:)]` | the endpoint's group |
| `#[Response(status:, description:, type:)]` | a response per status, with `type` read as a schema |
| `#[QueryParameter]`, `#[PathParameter]`, `#[BodyParameter]`, `#[HeaderParameter]` | the parameter, with its description, type, example and default |

```php
'attributes' => [
    'external' => [
        'Some\\Vendor\\Attributes\\',
    ],
],
```

Matching is on the full namespace, never the short name alone: an unrelated
`#[Response]` would otherwise be silently misdocumented. Nothing needs to be
installed — the attributes are read by name without being instantiated, so this
keeps working after the package they came from is removed.

Responses go through the same decoder as `#[ApiResponse]`, so two ways of
declaring the same response cannot document it differently.

**You do not need any of this to describe a response.** Lusen's own attribute
takes the same `type` grammar, and this exists for codebases migrating rather
than as a feature to adopt:

```php
#[ApiResponse(200, 'The order.', type: 'array{status: true, data: OrderShape}')]
#[ApiResponse(404, 'No order with that id.', type: 'ApiError')]
public function show(): JsonResponse { /* ... */ }
```

Lusen's own attributes win over external ones, and you can drop
`ExternalAttributeExtractor::class` from `extractors` to ignore them entirely.

---

## Describing a parameter

A FormRequest says what is valid; a docblock above the rule says what the field
is *for*. Lusen reads it:

```php
public function rules(): array
{
    return [
        /**
         * Where to deliver the order.
         *
         * @example DEPOT-7
         */
        'depot' => 'required|string|max:32',

        /**
         * How many crates, at most twelve.
         *
         * @example 3
         */
        'crates' => 'required|integer|max:12',
    ];
}
```

`@example` is typed the way you wrote it — `3` stays a number, so the example
request does not quote it — and it beats the value Lusen would have generated.

Anything the rules add that the schema could not express is kept alongside your
sentence rather than replacing it: "must be a JPEG" and "the customer's avatar"
answer different questions.

`attributes()` and `messages()` are deliberately not used. They hold labels and
error text rather than descriptions, and in practice both are `__()` calls that
a reader which never boots your application cannot resolve.

---

## Responses without an API resource

Lusen reads response bodies from the API resource an action returns. Plenty of
codebases have none — they answer with plain arrays through a base-controller
helper — so it also reads the shape when you write it down.

```php
/**
 * List orders
 *
 * @response array{status: true, data: array{orders: list<OrderShape>, total: int}}
 * @response 422 array{status: false, message: string}
 */
public function index(): JsonResponse { /* ... */ }
```

The grammar is the PHPStan one you already use in static analysis:
`array{key: T, optional?: T}`, `list<T>`, `array<T>`, `T[]`, `?T`, `A|B`, and
literals. A union of string literals (`'paid'|'pending'`) becomes an enum. A
union of real types documents the first member, which is why
`array{...}|ApiError` reads as the success shape. Without a status code in
front, a `POST` is `201` and everything else is `200` — never `204`, since a
written shape says there is a body.

Any class named in a shape is read too:

```php
/** Documentation-only shape of an order's money amounts. */
class MoneyShape
{
    /** ISO 4217 currency code. */
    public string $currency;
    public ?float $exchange_rate;
    /** @var list<LineShape> */
    public array $lines;
}
```

Public typed properties become fields, their docblock summaries become
descriptions, and `@var` beats a bare `array` because it says more. Nothing is
marked required: a class has no way to say which of its fields are always
present, and claiming otherwise would be a promise it never made. Static and
non-public properties are skipped, and a class that refers to itself stops
rather than recursing.

Anything unreadable becomes `any`, never a guess. A hand-written `@response`
always beats a shape inferred from `toArray()`, and an `#[ApiResponse]`
attribute beats both.

### Response envelopes

Most APIs that answer with plain arrays send them through one helper on a base
controller:

```php
public function sendResponse(array $result): JsonResponse
{
    return response()->json(['status' => true, 'data' => $result], 200);
}
```

`return $this->sendResponse([...])` is followed into that helper, so the
envelope is documented along with the payload in the place the helper actually
puts it. It is read out of your code rather than declared in configuration,
which means it cannot contradict what you ship and cannot double-wrap a
`@response` you already wrote out in full.

When the payload is something Lusen cannot see into — a service call, say — the
envelope is still documented with the payload left `any`. A reader who does not
know their result arrives under `data` will look in the wrong place, so the
wrapper is worth stating on its own.

**Guard clauses are skipped.** A return sitting directly in the method body
wins over one nested inside an `if`, so this documents the success shape rather
than the error it returns first:

```php
public function show()
{
    if (! $this->allowed()) {
        return $this->sendError('nope');   // not the documented response
    }

    return $this->sendResponse($order);
}
```

---

## Attributes

Import from `Lusen\Attributes`. Use these when inference cannot see something,
or is wrong.

| Attribute | Where | What it does |
| --- | --- | --- |
| `#[ApiDoc]` | class, method | `summary`, `description`, `group`, `authenticated`, `deprecated`, `tags`, `version`. Leave a property out to keep the inferred value |
| `#[ApiGroup]` | class | Names the group and describes it — the description becomes the group's landing copy |
| `#[ApiParam]` | method, repeatable | A parameter inference cannot reach: a query filter, a custom header, a body field validated outside a FormRequest |
| `#[ApiResponse]` | method, repeatable | A response, per status. `type` takes the same grammar as `@response`, so `type: 'array{id: int}'` or `type: 'OrderShape'` documents the body; `example` overrides the generated one |
| `#[Authenticated]` | class, method | Force the auth flag on, or `#[Authenticated(false)]` to force it off |
| `#[Hidden]` | class, method | Exclude from the docs entirely |

```php
use Lusen\Attributes\{ApiDoc, ApiGroup, ApiParam, ApiResponse, Hidden};

#[ApiGroup('Customers', 'Create and manage the people who place orders.')]
final class CustomerController extends Controller
{
    #[ApiDoc(summary: 'List customers', description: 'Newest first.')]
    #[ApiParam('status', in: 'query', enum: ['active', 'invited'])]
    public function index(): AnonymousResourceCollection { /* ... */ }

    #[Hidden]
    public function internalMetrics(): JsonResponse { /* ... */ }
}
```

---

## Docblocks

If your controllers are already commented, that *is* your documentation — no
attributes needed.

```php
/**
 * Manage the people who place orders.
 *
 * @group Customers
 */
final class CustomerController extends Controller
{
    /**
     * List every customer.
     *
     * Returns a paginated list, newest first. Use the status filter to
     * narrow it.
     */
    public function index(): AnonymousResourceCollection
```

The first paragraph is the summary, the rest is the description. A trailing
full stop is stripped from the summary, because it becomes a heading.

| Tag | Effect |
| --- | --- |
| `@group Name` | Sets the group. On the class, applies to every action |
| `@deprecated` | Marks the endpoint deprecated |
| `@authenticated` | Forces the auth flag on |
| `@unauthenticated` | Forces it off, overriding what the middleware implied |
| `@ignore`, `@internal`, `@hideFromApiDocs` | Excludes the endpoint |
| `@mixin \App\Models\User` | On a **resource**: names the model behind it, so its fields can be typed |

Laravel's scaffolded comments — *"Display a listing of the resource"* — are
ignored on purpose. They are identical in every project, so lifting them would
give a hundred endpoints the same summary and the same meta description. You
get conventional wording instead: `index` on a Customers controller reads
**List customers**.

### Helping Lusen find your model

Field types come from the model behind a resource. Lusen finds it from `@mixin`,
then a `@property` tag, then the `UserResource` → `App\Models\User` convention.
If a resource is named unconventionally, one line fixes it:

```php
/**
 * @mixin \App\Models\Customer
 */
final class ClientResource extends JsonResource
```

---

## Authentication schemes

Lusen reads the scheme from middleware where it can: `auth.basic` is basic auth,
Passport's `scopes:` and Sanctum's `abilities:` name their scopes.

A custom header scheme leaves no trace in middleware, so declare it. Listing
more than one header means all of them are required together, which is how a
client id and secret pair actually works:

```php
'auth' => [
    'scheme'  => 'apiKey',                            // bearer | basic | apiKey | oauth2
    'headers' => ['X-Client-Id', 'X-Client-Secret'],
],
```

That produces two `apiKey` schemes required together in OpenAPI, sends both
headers in every generated example, and describes them on the generated
Authentication page. An API mixing schemes documents the most common one on
that page and notes the others; each endpoint page always states its own.

## API versions

Lusen reads the version out of the path: `/api/v2/orders` belongs to `v2`. It
recognises `v1`, `v2.1`, `v10` and dated versions like `2026-01-15`, and only
in the leading segments — `/api/reports/v2` is a resource called reports, not
a version, and Lusen will not pretend otherwise.

With two or more versions in play the reference is grouped by version, newest
first; each page badges its version; and every older endpoint links to its
replacement, matched by method and version-free path. One version changes
nothing about how the docs read.

Two things a URL cannot say:

```php
'versions' => [
    'enabled'    => true,
    'current'    => 'v2',                      // default: the newest found
    'deprecated' => ['v1' => '2026-06-01'],    // or just ['v1']
],
```

`current` is what "write new integrations against this" means, and it is what
the successor links point at — pin it to an older version while a new one is in
preview and readers stop being sent to the preview.

`deprecated` marks every endpoint in that version deprecated, in the HTML, the
Markdown, the OpenAPI `deprecated` flag and the Postman collection. The value,
when you give one, is the date the version stops answering.

Lusen also concludes a version is deprecated on its own when every endpoint in
it already carries `@deprecated` — except for the current version, where that
would be reading too much into it.

**A version that is not in the path.** An API that negotiates its version in an
`Accept` header leaves nothing for Lusen to read, so the controller says:

```php
#[ApiDoc(version: 'v2')]
final class OrderController extends Controller { /* ... */ }
```

A `v2.*` route name is read too, when the path is silent.

**The Versioning page** is derived and free: the versions, their standing, and
what the newest one added or dropped compared with the one before it. Write
`resources/docs/versioning.md` to replace it.

---

## Grouping, ordering and hiding

**Groups** come from `#[ApiGroup]`, then `@group`, then the first meaningful
URI segment — the version and a leading `api` are skipped. Endpoints within a
group follow route registration order; groups are alphabetical, after their
version when the API has more than one.

**Hiding** an endpoint, in order of preference:

1. `#[Hidden]` or `@ignore` on the action — lives next to the code, survives
   route renames
2. `routes.exclude_names` in config — for routes you don't own
3. `routes.exclude` URI patterns — for whole families

```php
'routes' => [
    'include' => ['api/*'],
    'exclude' => ['api/internal/*', 'telescope*'],
    'exclude_names' => ['debug.*'],
    'middleware' => [],   // when set, a route must carry all of these
],
```

---

## Identity and branding

```php
'title'       => env('LUSEN_TITLE', 'Acme Commerce API'),
'version'     => '2.4.1',
'description' => 'Everything you need to sell.',   // the introduction's opening line
'base_url'    => env('LUSEN_BASE_URL'),
'servers'     => ['Sandbox' => 'https://sandbox.acme.example'],

'ui' => [
    'logo'      => null,                      // URL, shown above the title
    'favicon'   => null,                      // URL
    'dark_mode' => true,                      // shows the light/dark/system toggle
    'snippets'  => ['curl', 'javascript'],    // request examples, in order
    'edit_url'  => null,                      // 'https://github.com/acme/api/edit/main/{path}'
],

'try_it' => [
    'enabled'       => false,                 // see "Letting readers send the request"
    'methods'       => ['GET'],
    'persist_token' => 'session',             // session | local | none
    'credentials'   => false,
],

'seo' => [
    'canonical_origin' => 'https://example.com',  // scheme + host, no path
    'noindex'          => false,
    'og_image'         => null,
    'lastmod'          => null,                   // a real date, or omitted
],
```

`canonical_origin` is worth setting: without it there are no canonical URLs and
no sitemap, because a sitemap needs absolute URLs and a relative canonical is
worse than none.

`servers` are the other base URLs the same API answers on. They become a
switcher in the sidebar that rewrites the base URL in every example on the
page, so a reader working against your sandbox copies a sandbox request — and
they are the `servers` array in the OpenAPI document.

### Letting readers send the request

```php
'try_it' => [
    'enabled'       => env('LUSEN_TRY_IT', false),
    'methods'       => ['GET'],       // widen at your own risk
    'persist_token' => 'session',     // session | none
    'credentials'   => false,         // send cookies cross-origin
],
```

Every endpoint whose method is listed gets a **Try it** dialog: one field per
parameter, prefilled with the same values the printed example uses, a field per
header the auth scheme requires, and the body as JSON where there is one.

The request is sent by the reader's browser straight to your API — there is no
proxy — so it works when the docs and the API share an origin, and otherwise
needs your API to allow the docs origin with
`Access-Control-Allow-Origin`. When it does not, the page explains that rather
than reporting a failure that looks like an outage.

Readers set their credential once, in a **Set up to test** section on the
Authentication page, and every dialog on the site uses it. Where it is kept is
`persist_token`: `session` (until the tab closes, the default), `none` (memory
only) or `local`, which offers the reader a *Remember on this browser*
checkbox. That choice is about lifetime, not secrecy — no browser store hides a
value from scripts on the same origin — so the shorter it lives, the smaller
the window. It is scoped to the base URL it belongs to, never appears in a
copied example, and a *Forget* button clears it.

`#[ApiDoc(tryIt: false)]` withholds the form from one operation. It can only
ever remove: an endpoint cannot opt into a playground the site has turned off.

`edit_url` puts an *Edit this page* link beside every page somebody wrote.
`{path}` is replaced with the file's path relative to your project root, so
`resources/docs/guides/webhooks.md` lands on the right file in your code host.
Generated pages have no file behind them and never get the link.

There is no colour setting. The stylesheet is prebuilt with literal Tailwind
class names — which is what lets Lusen install without Node — so a palette
cannot come from config. Change colours by publishing the views.

### Changing the pages themselves

```bash
php artisan vendor:publish --tag=lusen-views
```

Views land in `resources/views/vendor/lusen`. Both the static build and the
runtime preview render through them, so a change shows up in both. Two things
to keep if you edit them:

- Every page must be readable with JavaScript disabled. Search, the snippet
  tabs, the copy buttons, the base-URL switcher and the menu on small screens
  are all enhancements over markup that already works without them.
- Tailwind classes must appear as literal strings — the CSS is prebuilt, so a
  class assembled from a variable will not exist.

---

## Checking your work

```bash
php artisan lusen:check            # what is still undocumented
php artisan lusen:check --strict   # exit non-zero, for CI
php artisan lusen:check --json     # the same findings, for a script
```

It reports endpoints with no description, no documented response, or
undescribed parameters. Adding it to CI is what stops documentation rotting:
a new route with nothing written about it turns the build red.

```bash
php artisan lusen:build            # write the docs
php artisan lusen:build --dry-run  # see what would be written
```

Set `LUSEN_RUNTIME=true` and visit `/docs` to preview while you write; the docs
rebuild on every request, so there is nothing to re-run.
