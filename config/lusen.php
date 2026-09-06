<?php

declare(strict_types=1);

use Lusen\Extract\AttributeExtractor;
use Lusen\Extract\ControllerExtractor;
use Lusen\Extract\ExternalAttributeExtractor;
use Lusen\Extract\FormRequestExtractor;
use Lusen\Extract\ResourceExtractor;
use Lusen\Extract\RouteExtractor;
use Lusen\Support\AskAi;

return [

    /*
    |--------------------------------------------------------------------------
    | Identity
    |--------------------------------------------------------------------------
    |
    | Every key in this file has a working default. Lusen documents your API
    | with no configuration at all; this file only exists for when you want
    | to override something.
    |
    */

    'title' => env('LUSEN_TITLE', env('APP_NAME', 'Laravel').' API'),

    'version' => env('LUSEN_VERSION', '1.0.0'),

    'description' => null,

    'base_url' => env('LUSEN_BASE_URL', env('APP_URL', 'http://localhost')),

    /*
     | Other base URLs the same API answers on, as label => URL - a sandbox,
     | a regional host. They appear in a switcher in the docs, which rewrites
     | the base URL in every example on the page, and as `servers` in the
     | OpenAPI document.
     */
    'servers' => [
        // 'Production' => 'https://api.example.com',
        // 'Sandbox' => 'https://sandbox.example.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | API versions
    |--------------------------------------------------------------------------
    |
    | Lusen reads the version out of the URL: `/api/v2/orders` belongs to `v2`.
    | Nothing here needs setting for that to work. It is for the two things a
    | URL cannot say — which version you want people writing against, and
    | which ones are on their way out.
    |
    */

    'versions' => [
        /*
         | Turn detection off entirely. Every endpoint then documents as
         | unversioned, whatever its URL says.
         */
        'enabled' => true,

        /*
         | The version to present as the one to use. Defaults to the newest
         | detected, which is right for almost every API.
         */
        'current' => env('LUSEN_API_VERSION'),

        /*
         | Versions on the way out. Either form works, and they can be mixed;
         | a value is the date the version stops answering.
         |
         |     'deprecated' => ['v1'],
         |     'deprecated' => ['v1' => '2026-06-01'],
         |
         | Every endpoint in a deprecated version is documented as deprecated,
         | and each one links to its replacement in the newest version that
         | has one.
         */
        'deprecated' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Which routes get documented
    |--------------------------------------------------------------------------
    */

    'routes' => [
        /*
         | Only routes matching one of these URI patterns are considered.
         */
        'include' => [
            'api/*',
        ],

        /*
         | Removed even if they matched above. These are the usual suspects
         | that ship with a Laravel app and should never appear in your docs.
         */
        'exclude' => [
            'api/documentation*',
            'sanctum/*',
            'telescope*',
            'horizon*',
            '_debugbar*',
            '_ignition*',
        ],

        /*
         | When non-empty, a route must carry all of these middleware to be
         | documented. Useful for splitting a public API from an internal one.
         */
        'middleware' => [],

        /*
         | Route names matching these patterns are skipped.
         */
        'exclude_names' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Extraction pipeline
    |--------------------------------------------------------------------------
    |
    | Each entry enriches the endpoint and hands it to the next. Order matters
    | only in that later stages may read what earlier ones wrote; explicit
    | annotation always wins over inference regardless of position, so keep
    | AttributeExtractor last.
    |
    */

    'extractors' => [
        RouteExtractor::class,
        ControllerExtractor::class,
        FormRequestExtractor::class,
        ResourceExtractor::class,

        /*
         | Reads documentation attributes left by another tool, listed under
         | `attributes.external` below. Harmless if there are none, and safe
         | to remove if you would rather Lusen ignored them.
         */
        ExternalAttributeExtractor::class,

        AttributeExtractor::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Attributes from another documentation tool
    |--------------------------------------------------------------------------
    |
    | Namespaces whose attributes Lusen should read, so a codebase that has
    | been documented once keeps what it wrote when it changes tools. Groups,
    | responses and parameters are recognised by their short names within
    | these namespaces.
    |
    | Nothing needs to be installed: the attributes are read by name without
    | being instantiated, so this keeps working after the package they came
    | from is removed.
    |
    */

    'attributes' => [
        'external' => [
            'Dedoc\\Scramble\\Attributes\\',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | Lusen reads the scheme from middleware where it can: `auth.basic` is basic
    | auth, Passport's `scopes:` and Sanctum's `abilities:` name their scopes.
    |
    | A custom header scheme leaves no trace in middleware, so declare it here.
    | Listing more than one header means all of them are required together,
    | which is how a client id and client secret pair actually works.
    |
    */

    'auth' => [
        /*
         | bearer | basic | apiKey | oauth2
         */
        'scheme' => 'bearer',

        /*
         | For `apiKey`. Every header listed is required.
         |
         |     'headers' => ['X-Client-Id', 'X-Client-Secret'],
         */
        'headers' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Response field types are read from your models: casts first, then the
    | column definitions in your migrations. Casts win where the two disagree,
    | because a cast is what the value looks like by the time a resource
    | serialises it.
    |
    | Lusen finds the model behind a resource from an `@mixin` docblock, or by
    | the UserResource -> User naming convention. Add `@mixin` to a resource if
    | it is named unconventionally.
    |
    */

    'models' => [
        /*
         | Namespaces to look in for a resource's model, most likely first.
         */
        'namespaces' => [
            'App\Models',
            'App',
        ],

        /*
         | Where migrations live, relative to the project root. Migrations are
         | the only source that reports nullability.
         */
        'migrations' => [
            'database/migrations',
        ],

        /*
         | Set to false to type response fields from naming conventions alone.
         */
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Prose pages
    |--------------------------------------------------------------------------
    |
    | Endpoint reference answers "how do I call this". Pages answer "what is
    | this for" - the question a search engine or an AI assistant is usually
    | asked first. Write them as Markdown; one file per page.
    |
    | Front matter is optional:
    |
    |     ---
    |     title: Handling webhooks
    |     section: Guides
    |     order: 20
    |     ---
    |
    | A file in a subdirectory takes the directory name as its section, so
    | resources/docs/guides/webhooks.md lands under "Guides" with no front
    | matter at all.
    |
    */

    'pages' => [
        /*
         | Where your Markdown lives, relative to the project root.
         | Publish starter pages with:
         |     php artisan vendor:publish --tag=lusen-pages
         */
        'path' => 'resources/docs',

        /*
         | Generate Introduction, Authentication and Errors pages from the spec
         | when you have not written them. Everything generated is derived from
         | your actual routes, middleware and response codes - never invented.
         | A page you write always wins over the generated one of the same name.
         */
        'generate' => true,

        /*
         | Sidebar order. Sections not listed here follow alphabetically, so
         | adding one never silently reshuffles the rest.
         */
        'sections' => [
            'Getting started',
            'Guides',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Output
    |--------------------------------------------------------------------------
    */

    'output' => [
        /*
         | Where `php artisan lusen:build` writes, relative to the project root.
         | The default sits under public/ so your web server can serve the
         | docs as flat files with no PHP on the request path.
         */
        'path' => 'public/docs',

        /*
         | Public URL the output is served from. Used for canonical links,
         | the sitemap and cross-references between surfaces.
         */
        'url' => '/docs',

        /*
         | Enabled emitters. Drop any you do not want; each is independent.
         */
        'emitters' => [
            'html',
            'markdown',
            'openapi',
            'llms',
            'sitemap',
            'search',
            'postman',
            'discovery',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Contract diff
    |--------------------------------------------------------------------------
    |
    | `php artisan lusen:diff` compares this build against a recorded
    | baseline and reports what changed - and which of it breaks a client.
    | Record one with `--save`, commit it, and run `--strict` in CI.
    |
    */

    'diff' => [
        /*
         | Where the recorded baseline lives, relative to the project root.
         | It is the spec itself, so it diffs readably in a pull request.
         |
         | Deliberately not inside `.lusen/`: that directory holds the build
         | cache and is meant to be gitignored, and a baseline nobody commits
         | compares every run against nothing.
         */
        'baseline' => '.lusen-baseline.json',
    ],

    /*
    |--------------------------------------------------------------------------
    | Docs UI
    |--------------------------------------------------------------------------
    */

    'ui' => [
        /*
         | Optional URLs, used in the sidebar and the document head.
         | To change colours, publish the views: the stylesheet is prebuilt
         | with literal class names, so a palette cannot come from config.
         */
        'logo' => null,
        'favicon' => null,

        /*
         | Link every written page to its source, so a reader who spots a
         | mistake can fix it. `{path}` is replaced with the file's path
         | relative to the project root. Generated pages have no file behind
         | them and never get the link.
         |
         |     'edit_url' => 'https://github.com/acme/api/edit/main/{path}',
         */
        'edit_url' => env('LUSEN_EDIT_URL'),

        'dark_mode' => true,

        /*
         | Request-example languages shown on each endpoint, in order.
         | Supported: curl, javascript.
         */
        'snippets' => ['curl', 'javascript'],

        /*
         | Buttons that hand a page to an assistant with the question already
         | asked. The link carries the address of the page's Markdown twin, so
         | the model reads the source rather than the rendered page.
         |
         | Both halves are yours. Providers change their deep-link shape
         | without warning, and you should not have to wait for a release of
         | this package to fix a button or to add one nobody here has heard of.
         | `providers` is label => URL template; {prompt} is replaced with the
         | URL-encoded question. An empty list turns the buttons off.
         |
         | Nothing renders unless `seo.canonical_origin` (or an absolute
         | `output.url`) gives the page an address a model can actually fetch -
         | a prompt pointing at /docs/endpoints/x.md helps nobody.
         */
        'ask_ai' => [
            'providers' => [
                'ChatGPT' => 'https://chatgpt.com/?q={prompt}',
                'Claude' => 'https://claude.ai/new?q={prompt}',
            ],

            /*
             | {url} is the page's Markdown, {subject} what it documents, and
             | {title} your API's name.
             */
            'prompt' => AskAi::DEFAULT_PROMPT,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Try it
    |--------------------------------------------------------------------------
    |
    | A form on every endpoint page that sends the request and shows what came
    | back. Off by default: it is your API that gets called, from your reader's
    | browser, and that should be a decision rather than a surprise.
    |
    | There is no proxy. The request goes straight from the page to your API,
    | which means it works when the two share an origin - runtime mode, or docs
    | served from the API's own domain - and otherwise needs your API to allow
    | the docs origin:
    |
    |     Access-Control-Allow-Origin: https://docs.example.com
    |     Access-Control-Allow-Headers: authorization, content-type
    |
    | When that header is missing the browser blocks the response and the page
    | says so, naming both origins, rather than reporting a failure that looks
    | like your API is down.
    |
    */

    'try_it' => [
        'enabled' => env('LUSEN_TRY_IT', false),

        /*
         | Which methods get the form. The default is deliberately the safe
         | half of the API: a Send button beside DELETE /customers/{id}, on a
         | page whose base URL is production, is a mistake waiting for a
         | distracted reader.
         */
        'methods' => ['GET'],

        /*
         | Where a credential the reader types is kept.
         |
         |     session   until the tab closes  (the default)
         |     none      in memory; reloading asks again
         |     local     until they clear it, and only if they tick the box
         |
         | Lifetime is the only thing this changes. No browser store hides a
         | value from scripts running on the same site, and a token that has to
         | be attached to a fetch cannot be hidden from the code attaching it -
         | so the shorter it lives, the smaller the window. `local` shows the
         | reader a checkbox and a Forget button rather than deciding for them.
         |
         | It never leaves the browser except as a header on the request it
         | authenticates, and never appears in a copied example.
         */
        'persist_token' => 'session',

        /*
         | Send cookies with cross-origin requests, for a session-authenticated
         | API. Your API must also answer with
         | `Access-Control-Allow-Credentials: true` and name the docs origin
         | exactly - a wildcard is refused for credentialed requests.
         */
        'credentials' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Search engine optimisation
    |--------------------------------------------------------------------------
    */

    'seo' => [
        /*
         | Absolute origin used for canonical URLs and the sitemap. Falls back
         | to base_url. Set this if your docs are on a different host.
         */
        'canonical_origin' => null,

        'noindex' => false,

        'json_ld' => true,

        'sitemap' => true,

        /*
         | Optional W3C date for the sitemap's <lastmod>. Left unset, the tag
         | is omitted entirely - stamping "now" on every build is a lie that
         | teaches crawlers to ignore the field.
         */
        'lastmod' => null,

        'og_image' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | AI agents and generative search
    |--------------------------------------------------------------------------
    */

    'agents' => [
        /*
         | llms.txt index plus llms-full.txt whole-corpus file.
         */
        'llms_txt' => true,

        /*
         | A .md mirror of every page, and Markdown served for
         | `Accept: text/markdown` on the HTML URLs.
         */
        'markdown_mirror' => true,

        /*
         | /.well-known/api-docs discovery document pointing at every
         | machine-readable surface.
         */
        'well_known' => true,

        /*
         | Expose the spec over MCP via `php artisan lusen:mcp`.
         */
        'mcp' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime serving
    |--------------------------------------------------------------------------
    |
    | Off by default: static output is faster and needs no PHP per request.
    | Turn this on for local development, or when the docs must sit behind
    | your app's auth. Both modes render through the same Blade views.
    |
    */

    'runtime' => [
        'enabled' => env('LUSEN_RUNTIME', false),

        'path' => 'docs',

        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Build cache
    |--------------------------------------------------------------------------
    |
    | Source files are hashed so a rebuild only re-analyses what changed.
    | Safe to delete; safe to gitignore.
    |
    */

    'cache' => [
        /*
         | Enabled by default: a rebuild only re-analyses the endpoints whose
         | routes or source files actually changed. Files are compared by
         | content, not modification time, so a fresh checkout or a CI runner
         | still gets the benefit.
         */
        'enabled' => env('LUSEN_CACHE', true),

        /*
         | Relative to the project root. Safe to delete and safe to gitignore;
         | `lusen:build --fresh` ignores it for one run.
         */
        'path' => '.lusen/cache',
    ],

];
