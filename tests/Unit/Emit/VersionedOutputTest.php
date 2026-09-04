<?php

declare(strict_types=1);

use Lusen\Emit\DiscoveryEmitter;
use Lusen\Emit\HtmlEmitter;
use Lusen\Emit\LlmsTxtEmitter;
use Lusen\Emit\MarkdownEmitter;
use Lusen\Emit\OpenApiEmitter;
use Lusen\Emit\PostmanEmitter;
use Lusen\Emit\SearchIndexEmitter;
use Lusen\Ir\ApiSpec;
use Lusen\Pages\DefaultPages;
use Lusen\Support\Links;

function versionedLinks(): Links
{
    return new Links('/docs', static: true, canonicalOrigin: 'https://docs.test');
}

/**
 * The view data an endpoint page was built with. `recordingRenderer()` comes
 * from HtmlEmitterTest, which is where the fake is documented.
 *
 * @return array<string, mixed>
 */
function renderedWith(ApiSpec $spec, string $id): array
{
    $renderer = recordingRenderer();

    (new HtmlEmitter($renderer, versionedLinks()))->endpoint($spec->endpoint($id), $spec);

    /** @var array<string, mixed> $data */
    $data = $renderer->calls[0]['data'];

    return $data;
}

it('names the version in the title and the meta description', function (): void {
    $spec = versionedFixtureSpec();

    $older = renderedWith($spec, 'v1.users.index');
    $newer = renderedWith($spec, 'v2.users.index');

    // Both operations are called "List users". Without the version these are
    // two pages competing for one query with the same snippet.
    expect($older['title'])->toBe('List users (v1) — Test API')
        ->and($newer['title'])->toBe('List users (v2) — Test API')
        ->and($older['description'])->not->toBe($newer['description'])
        ->and($older['description'])->toBe('List users (v1)');
});

it('leaves titles alone when there is only one version', function (): void {
    expect(renderedWith(fixtureSpec(), 'users.index')['title'])->toBe('List users — Test API');
});

it('carries the version and the successor in the markdown front matter', function (): void {
    $spec = versionedFixtureSpec();
    $markdown = (new MarkdownEmitter(versionedLinks()))->endpoint($spec->endpoint('v1.users.index'), $spec);

    expect($markdown)->toContain('api_version: "v1"')
        ->toContain('superseded_by: "v2.users.index"')
        ->toContain('deprecated: true')
        ->toContain('API version: `v1`.')
        ->toContain('[`GET /api/v2/users`](/docs/endpoints/v2-users-index.md)');
});

it('says nothing about a successor an endpoint does not have', function (): void {
    $spec = versionedFixtureSpec();
    $markdown = (new MarkdownEmitter(versionedLinks()))->endpoint($spec->endpoint('v2.users.index'), $spec);

    expect($markdown)->not->toContain('newer version')
        ->and($markdown)->not->toContain('superseded_by');
});

it('opens the markdown index with the versions on offer', function (): void {
    $index = (new MarkdownEmitter(versionedLinks()))->index(versionedFixtureSpec());

    expect($index)->toContain('## API versions')
        ->toContain('| `v2` | current | 1 |')
        ->toContain('| `v1` | deprecated — retires 2026-06-01 | 2 |')
        ->toContain('Write new integrations against `v2`.')
        ->toContain('## Users (v1)');
});

it('keeps the version out of an unversioned index', function (): void {
    expect((new MarkdownEmitter(versionedLinks()))->index(fixtureSpec()))
        ->not->toContain('## API versions');
});

it('tells the llms corpus which version each endpoint belongs to', function (): void {
    $full = (new LlmsTxtEmitter(versionedLinks()))->full(versionedFixtureSpec());

    expect($full)->toContain('## API versions')
        ->toContain('## Users (v2)')
        ->toContain('API version: `v1`.')
        ->toContain('**A newer version of this operation exists**');
});

it('tags openapi operations with the version so a client cannot merge them', function (): void {
    $document = (new OpenApiEmitter)->document(versionedFixtureSpec());

    $tags = array_map(fn (array $tag): string => $tag['name'], $document['tags']);

    expect($tags)->toBe(['Users (v2)', 'Exports (v1)', 'Users (v1)'])
        ->and($document['paths']['/api/v1/users']['get']['tags'])->toBe(['Users (v1)'])
        ->and($document['paths']['/api/v2/users']['get']['tags'])->toBe(['Users (v2)'])
        ->and($document['paths']['/api/v1/users']['get']['deprecated'])->toBeTrue();
});

it('gives postman one folder per version of a resource', function (): void {
    $collection = (new PostmanEmitter)->collection(versionedFixtureSpec());

    $names = array_map(fn (array $folder): string => $folder['name'], $collection['item']);

    expect($names)->toBe(['Users (v2)', 'Exports (v1)', 'Users (v1)']);
});

it('tells two versions of one operation apart in search results', function (): void {
    $index = (new SearchIndexEmitter(versionedLinks()))->index(versionedFixtureSpec());

    $users = array_values(array_filter(
        $index['items'],
        fn (array $item): bool => ($item['title'] ?? '') === 'List users',
    ));

    expect($users)->toHaveCount(2)
        ->and($users[0]['context'])->toBe('Users · v2')
        ->and($users[1]['context'])->toBe('Users · v1');
});

it('lists the versions in the discovery document', function (): void {
    $document = (new DiscoveryEmitter(versionedLinks()))->document(versionedFixtureSpec());

    expect($document['api_versions'])->toBe([
        ['name' => 'v2', 'current' => true],
        ['name' => 'v1', 'deprecated' => true, 'sunset' => '2026-06-01'],
    ]);
});

it('leaves the discovery document alone for an unversioned api', function (): void {
    expect((new DiscoveryEmitter(versionedLinks()))->document(fixtureSpec()))
        ->not->toHaveKey('api_versions');
});

it('derives a versioning page from what the versions actually expose', function (): void {
    $pages = DefaultPages::fill(versionedFixtureSpec(), []);

    $versioning = array_values(array_filter($pages, fn ($page): bool => $page->id === 'versioning'))[0] ?? null;

    expect($versioning?->title)->toBe('Versioning')
        ->and($versioning?->markdown)->toContain('This API serves 2 versions at once.')
        ->toContain('The version is part of the path')
        ->toContain('| `v1` | deprecated — retires 2026-06-01 | 2 |')
        ->toContain('New integrations should use `v2`.')
        ->toContain('## `v2` compared with `v1`')
        ->toContain('In `v1` but not in `v2`:')
        ->toContain('- `GET /api/v1/exports` — List exports')
        ->toContain('The remaining operation exists in both versions at the same path.');
});

it('tells two versions of a group apart in the introduction', function (): void {
    $pages = DefaultPages::fill(versionedFixtureSpec(), []);
    $introduction = array_values(array_filter($pages, fn ($page): bool => $page->id === 'introduction'))[0];

    // Both versions have a Users group; a list naming both of them "Users"
    // answers nothing.
    expect($introduction->markdown)->toContain('- **Users (v2)**')
        ->toContain('- **Users (v1)**')
        ->toContain('| API versions | `v2` (current), `v1` (deprecated) |');
});

it('keeps the api versions row out of an unversioned introduction', function (): void {
    $pages = DefaultPages::fill(fixtureSpec(), []);
    $introduction = array_values(array_filter($pages, fn ($page): bool => $page->id === 'introduction'))[0];

    expect($introduction->markdown)->not->toContain('API versions')
        ->toContain('- **Users**');
});

it('writes no versioning page for an api with one version', function (): void {
    $ids = array_map(fn ($page): string => $page->id, DefaultPages::fill(fixtureSpec(), []));

    expect($ids)->not->toContain('versioning');
});
