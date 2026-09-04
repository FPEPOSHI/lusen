<?php

declare(strict_types=1);

use Lusen\Extract\AttributeExtractor;
use Lusen\Extract\ControllerExtractor;
use Lusen\Extract\FormRequestExtractor;
use Lusen\Extract\ResourceExtractor;
use Lusen\Extract\RouteExtractor;
use Lusen\Extract\ScrambleExtractor;

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
     | Extra servers shown in the docs UI switcher, as label => base URL.
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
         | Reads Scramble's attributes, so a codebase documented with it keeps
         | what it wrote. Harmless if you have never used it, and safe to
         | remove if you would rather Lusen ignored them.
         */
        ScrambleExtractor::class,

        AttributeExtractor::class,
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

        'dark_mode' => true,

        /*
         | Request-example languages shown on each endpoint, in order.
         | Supported: curl, javascript.
         */
        'snippets' => ['curl', 'javascript'],
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
