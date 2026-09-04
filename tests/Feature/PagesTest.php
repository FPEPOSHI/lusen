<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lusen\Collect\PageCollector;
use Lusen\Ir\ApiSpec;
use Lusen\SpecBuilder;
use Lusen\Tests\Fixtures\UserController;

function docsDir(): string
{
    return sys_get_temp_dir().'/lusen-pages-'.bin2hex(random_bytes(4));
}

function writePage(string $dir, string $name, string $contents): void
{
    $path = $dir.'/'.$name;

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0o755, true);
    }

    file_put_contents($path, $contents);
}

beforeEach(function (): void {
    $this->docs = docsDir();
    mkdir($this->docs, 0o755, true);

    config()->set('lusen.pages.path', $this->docs);
    app()->bind(PageCollector::class, fn (): PageCollector => new PageCollector($this->docs));

    Route::get('api/users', [UserController::class, 'index'])->name('users.index');
    Route::middleware('auth:sanctum')->get('api/users/{user}', [UserController::class, 'show'])->name('users.show');
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->docs));
});

it('reads a page with front matter', function (): void {
    writePage($this->docs, 'use-cases.md', "---\ntitle: Use cases\nsection: Guides\norder: 5\n---\n\nWhat people build.\n");

    $page = app(SpecBuilder::class)->build()->page('use-cases');

    expect($page?->title)->toBe('Use cases')
        ->and($page?->section)->toBe('Guides')
        ->and($page?->order)->toBe(5)
        ->and($page?->markdown)->toContain('What people build.');
});

it('falls back to the first heading for a title', function (): void {
    writePage($this->docs, 'webhooks.md', "# Handling webhooks\n\nBody text.\n");

    expect(app(SpecBuilder::class)->build()->page('webhooks')?->title)->toBe('Handling webhooks');
});

it('does not render the title heading twice', function (): void {
    writePage($this->docs, 'webhooks.md', "# Handling webhooks\n\nBody text.\n");

    // The layout renders the title as the h1, so the body must not repeat it.
    expect(app(SpecBuilder::class)->build()->page('webhooks')?->markdown)
        ->not->toContain('# Handling webhooks');
});

it('falls back to the filename when there is no title or heading', function (): void {
    writePage($this->docs, 'rate-limits.md', "Some prose.\n");

    expect(app(SpecBuilder::class)->build()->page('rate-limits')?->title)->toBe('Rate Limits');
});

it('takes the section from a subdirectory name', function (): void {
    writePage($this->docs, 'guides/webhooks.md', "Body.\n");

    expect(app(SpecBuilder::class)->build()->page('webhooks')?->section)->toBe('Guides');
});

it('generates the standard pages alongside authored ones', function (): void {
    writePage($this->docs, 'use-cases.md', "---\ntitle: Use cases\n---\n\nWhat people build.\n");

    $ids = array_map(fn ($p): string => $p->id, app(SpecBuilder::class)->build()->pages());

    expect($ids)->toContain('use-cases')
        ->toContain('introduction')
        ->toContain('authentication');
});

it('lets an authored page replace the generated one of the same name', function (): void {
    writePage($this->docs, 'introduction.md', "---\ntitle: Introduction\n---\n\nMy own words.\n");

    $page = app(SpecBuilder::class)->build()->page('introduction');

    expect($page?->markdown)->toContain('My own words.')
        ->and($page?->markdown)->not->toContain('At a glance');
});

it('can be told not to generate anything', function (): void {
    config()->set('lusen.pages.generate', false);
    writePage($this->docs, 'only.md', "---\ntitle: Only\n---\n\nJust this.\n");

    $ids = array_map(fn ($p): string => $p->id, app(SpecBuilder::class)->build()->pages());

    expect($ids)->toBe(['only']);
});

it('still produces prose for an app that authored none', function (): void {
    $spec = app(SpecBuilder::class)->build();

    expect($spec->sections)->not->toBe([])
        ->and($spec->page('introduction'))->not->toBeNull();
});

it('orders sections by configuration, then alphabetically', function (): void {
    writePage($this->docs, 'a.md', "---\ntitle: A\nsection: Zebra\n---\n\nx\n");
    writePage($this->docs, 'b.md', "---\ntitle: B\nsection: Guides\n---\n\nx\n");

    $names = array_map(fn ($s): string => $s->name, app(SpecBuilder::class)->build()->sections);

    expect($names)->toBe(['Getting started', 'Guides', 'Zebra']);
});

it('orders pages within a section by their order field', function (): void {
    writePage($this->docs, 'second.md', "---\ntitle: Second\nsection: Guides\norder: 20\n---\n\nx\n");
    writePage($this->docs, 'first.md', "---\ntitle: First\nsection: Guides\norder: 10\n---\n\nx\n");

    $section = app(SpecBuilder::class)->build()->sections[1];

    expect(array_map(fn ($p): string => $p->title, $section->pages))->toBe(['First', 'Second']);
});

it('keeps the spec round-trippable with pages', function (): void {
    writePage($this->docs, 'guide.md', "---\ntitle: Guide\n---\n\nProse with `code`.\n");

    $spec = app(SpecBuilder::class)->build();

    expect(ApiSpec::fromJson($spec->toJson())->toJson())->toBe($spec->toJson());
});
