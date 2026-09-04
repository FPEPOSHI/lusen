<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lusen\Emit\Contracts\Renderer;
use Lusen\Emit\HtmlEmitter;
use Lusen\Ir\ApiSpec;
use Lusen\SpecBuilder;
use Lusen\Support\Links;
use Lusen\Tests\Fixtures\OrderController;
use Lusen\Tests\Fixtures\UserController;

/**
 * Real Blade, so the views themselves are under test - not just the emitter's
 * bookkeeping.
 */
function staticEmitter(): HtmlEmitter
{
    return new HtmlEmitter(
        app(Renderer::class),
        new Links('/docs', static: true, canonicalOrigin: 'https://example.com'),
    );
}

function staticSpec(): ApiSpec
{
    return app(SpecBuilder::class)->build();
}

beforeEach(function (): void {
    Route::get('api/users', [UserController::class, 'index'])->name('users.index');
    Route::middleware('auth:sanctum')->get('api/users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::post('api/orders', [OrderController::class, 'store'])->name('orders.store');
});

it('renders an endpoint page as complete html', function (): void {
    $spec = staticSpec();
    $html = staticEmitter()->endpoint($spec->endpoint('users.index'), $spec);

    expect($html)->toStartWith('<!doctype html>')
        ->toContain('</html>')
        ->toContain('<h1')
        ->toContain('List users');
});

it('gives an endpoint page its own title, description and canonical', function (): void {
    $spec = staticSpec();
    $html = staticEmitter()->endpoint($spec->endpoint('users.index'), $spec);

    expect($html)->toContain('<title>List users — Test API</title>')
        ->toContain('<link rel="canonical" href="https://example.com/docs/endpoints/users-index.html">')
        ->toContain('name="description" content="A paginated list of every user."');
});

it('links the shared stylesheet instead of inlining it', function (): void {
    $spec = staticSpec();
    $html = staticEmitter()->endpoint($spec->endpoint('users.index'), $spec);

    expect($html)->toContain('<link rel="stylesheet" href="/docs/assets/lusen.css">')
        ->and($html)->not->toContain('<style>');
});

it('advertises the page markdown mirror in the head', function (): void {
    $spec = staticSpec();
    $html = staticEmitter()->endpoint($spec->endpoint('users.index'), $spec);

    expect($html)->toContain('type="text/markdown" href="/docs/endpoints/users-index.md"');
});

it('emits per-endpoint structured data with a breadcrumb', function (): void {
    $spec = staticSpec();
    $html = staticEmitter()->endpoint($spec->endpoint('users.index'), $spec);

    expect($html)->toContain('TechArticle')
        ->toContain('BreadcrumbList');
});

it('keeps a page self-contained: auth, url and a runnable example', function (): void {
    $spec = staticSpec();
    $html = staticEmitter()->endpoint($spec->endpoint('users.show'), $spec);

    // Code is highlighted at build time, so the command is wrapped in spans
    // rather than appearing as a raw string.
    expect($html)->toContain('Requires authentication')
        ->toContain('Send a bearer token')
        ->toContain('Example request')
        ->toContain('<span class="tok-cmd">curl</span>')
        ->toContain('/api/users/1');
});

it('navigates between endpoint pages by file, not anchor', function (): void {
    $spec = staticSpec();
    $html = staticEmitter()->endpoint($spec->endpoint('users.index'), $spec);

    expect($html)->toContain('href="/docs/endpoints/users-show.html"')
        ->and($html)->not->toContain('href="#users-show"');
});

it('marks the current endpoint in the sidebar', function (): void {
    $spec = staticSpec();
    $html = staticEmitter()->endpoint($spec->endpoint('users.index'), $spec);

    expect($html)->toContain('aria-current="page"');
});

it('lists endpoints on the index rather than repeating their content', function (): void {
    // Repeating every endpoint would make the index duplicate content
    // competing with the pages meant to rank for those operations.
    $html = staticEmitter()->index(staticSpec());

    expect($html)->toContain('href="/docs/endpoints/users-index.html"')
        ->and($html)->not->toContain('Example request')
        ->and($html)->not->toContain('curl -X');
});

it('still renders full detail inline at runtime, where there is only one page', function (): void {
    $this->get('/docs')
        ->assertOk()
        ->assertSee('Example request')
        ->assertSee('href="#users-show"', escape: false);
});

it('documents a form-request body on its own static page', function (): void {
    $spec = staticSpec();
    $html = staticEmitter()->endpoint($spec->endpoint('orders.store'), $spec);

    expect($html)->toContain('Body parameters')
        ->toContain('email')
        ->toContain('jane@example.com');
});

it('produces a document outline with no skipped heading levels', function (): void {
    $spec = staticSpec();
    $html = staticEmitter()->endpoint($spec->endpoint('orders.store'), $spec);

    preg_match_all('/<h([1-6])[\s>]/', $html, $matches);
    $levels = array_map('intval', $matches[1]);

    expect($levels[0])->toBe(1);

    foreach ($levels as $index => $level) {
        if ($index > 0) {
            expect($level - $levels[$index - 1])->toBeLessThanOrEqual(1);
        }
    }
});

it('emits a page for every prose page', function (): void {
    $paths = array_map(fn ($f): string => $f->path, staticEmitter()->emit(staticSpec()));

    expect($paths)->toContain('pages/introduction.html')
        ->toContain('pages/authentication.html');
});

it('renders a prose page as html with its own title and canonical', function (): void {
    $spec = staticSpec();
    $html = staticEmitter()->page($spec->page('introduction'), $spec);

    expect($html)->toStartWith('<!doctype html>')
        ->toContain('<title>Introduction — Test API</title>')
        ->toContain('<link rel="canonical" href="https://example.com/docs/pages/introduction.html">')
        ->toContain('<h1');
});

it('renders markdown tables and code from a prose page', function (): void {
    $spec = staticSpec();
    $html = staticEmitter()->page($spec->page('introduction'), $spec);

    expect($html)->toContain('lusen-prose')
        ->toContain('<table>')
        ->toContain('<pre><code class="language-bash">');
});

it('gives a prose page an on-page table of contents', function (): void {
    $spec = staticSpec();
    $html = staticEmitter()->page($spec->page('introduction'), $spec);

    expect($html)->toContain('On this page')
        ->toContain('href="#at-a-glance"');
});

it('marks prose pages as Article rather than TechArticle', function (): void {
    $spec = staticSpec();

    expect(staticEmitter()->page($spec->page('introduction'), $spec))->toContain('"@type":"Article"')
        ->and(staticEmitter()->endpoint($spec->endpoint('users.index'), $spec))->toContain('TechArticle');
});

it('links prose pages from the sidebar above the endpoint groups', function (): void {
    $spec = staticSpec();
    $html = staticEmitter()->page($spec->page('introduction'), $spec);

    $prosePosition = strpos($html, '/docs/pages/introduction.html');
    $endpointPosition = strpos($html, '/docs/endpoints/users-index.html');

    expect($prosePosition)->toBeLessThan($endpointPosition);
});

it('carries previous and next links across prose and reference alike', function (): void {
    $spec = staticSpec();

    // The last prose page should point forward into the endpoint reference.
    $pages = $spec->pages();
    $last = end($pages);

    expect(staticEmitter()->page($last, $spec))->toContain('rel="next"')
        ->and(staticEmitter()->page($spec->page('introduction'), $spec))->toContain('rel="next"');
});

it('marks the current prose page in the sidebar', function (): void {
    $spec = staticSpec();

    expect(staticEmitter()->page($spec->page('introduction'), $spec))->toContain('aria-current="page"');
});

it('renders prose inline at runtime instead of linking to pages', function (): void {
    $this->get('/docs')
        ->assertOk()
        ->assertSee('At a glance')
        ->assertSee('id="page-introduction"', escape: false);
});

it('renders every configured snippet language', function (): void {
    config()->set('lusen.ui.snippets', ['curl', 'javascript']);
    $spec = staticSpec();

    expect(staticEmitter()->endpoint($spec->endpoint('users.index'), $spec))
        ->toContain('>cURL<')
        ->toContain('>JavaScript<')
        ->toContain('const response');
});

it('does not claim content negotiation on a static page', function (): void {
    // Flat files cannot negotiate; the extension swap works in both modes.
    $html = staticEmitter()->index(staticSpec());

    expect($html)->not->toContain('Accept: text/markdown')
        ->and($html)->toContain('Markdown twin');
});

it('offers a theme toggle and applies a stored choice before paint', function (): void {
    // The head script is what prevents a flash of the wrong theme; assert on
    // it rather than on the selector, which the shipped script also mentions.
    $html = staticEmitter()->index(staticSpec());

    expect($html)->toContain("setAttribute('data-theme',t)")
        ->toContain('data-lusen-theme-label');
});

it('omits the theme toggle when dark mode is turned off', function (): void {
    config()->set('lusen.ui.dark_mode', false);

    expect(staticEmitter()->index(staticSpec()))->not->toContain("setAttribute('data-theme',t)");
});

it('renders a configured logo and favicon', function (): void {
    config()->set('lusen.ui.logo', 'https://example.com/logo.svg');
    config()->set('lusen.ui.favicon', 'https://example.com/icon.png');

    expect(staticEmitter()->index(staticSpec()))
        ->toContain('src="https://example.com/logo.svg"')
        ->toContain('<link rel="icon" href="https://example.com/icon.png">');
});

it('omits them when unset', function (): void {
    expect(staticEmitter()->index(staticSpec()))->not->toContain('rel="icon"');
});
