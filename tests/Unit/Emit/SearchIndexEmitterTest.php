<?php

declare(strict_types=1);

use Lusen\Emit\SearchIndexEmitter;
use Lusen\Ir\Page;
use Lusen\Ir\Section;
use Lusen\Support\Links;

function searchIndex(): SearchIndexEmitter
{
    return new SearchIndexEmitter(new Links('/docs', static: true));
}

function indexedSpec()
{
    return fixtureSpec()->withSections([
        new Section('Getting started', [
            new Page(
                id: 'introduction',
                title: 'Introduction',
                markdown: "Everything about users.\n\n## At a glance\n\n## Making a request",
                section: 'Getting started',
            ),
        ]),
    ]);
}

it('emits one json file', function (): void {
    $file = searchIndex()->emit(indexedSpec())[0];

    expect($file->path)->toBe('search-index.json')
        ->and($file->contentType)->toBe('application/json')
        ->and(json_decode($file->contents, true))->toHaveKeys(['version', 'items']);
});

it('indexes prose pages before endpoints', function (): void {
    $kinds = array_column(searchIndex()->index(indexedSpec())['items'], 'kind');

    expect($kinds[0])->toBe('page')
        ->and($kinds[1])->toBe('endpoint');
});

it('indexes a page by its summary and headings', function (): void {
    $page = searchIndex()->index(indexedSpec())['items'][0];

    expect($page['title'])->toBe('Introduction')
        ->and($page['url'])->toBe('/docs/pages/introduction.html')
        ->and($page['context'])->toBe('Getting started')
        ->and($page['text'])->toContain('Everything about users.')
        ->toContain('At a glance');
});

it('indexes an endpoint by method, path, summary and parameter names', function (): void {
    $items = searchIndex()->index(indexedSpec())['items'];
    $endpoint = $items[1];

    expect($endpoint['title'])->toBe('List users')
        ->and($endpoint['method'])->toBe('GET')
        ->and($endpoint['path'])->toBe('/api/users')
        ->and($endpoint['context'])->toBe('Users')
        ->and($endpoint['text'])->toContain('per_page');
});

it('links entries to their own pages', function (): void {
    $items = searchIndex()->index(indexedSpec())['items'];

    expect($items[1]['url'])->toBe('/docs/endpoints/users-index.html');
});

it('keeps entry text bounded so a large api still ships a usable index', function (): void {
    foreach (searchIndex()->index(indexedSpec())['items'] as $item) {
        expect(mb_strlen($item['text'] ?? ''))->toBeLessThanOrEqual(400);
    }
});

it('omits empty fields rather than shipping nulls', function (): void {
    $spec = fixtureSpec()->withSections([]);
    $items = searchIndex()->index($spec)['items'];

    foreach ($items as $item) {
        expect($item)->not->toContain(null)
            ->and($item)->not->toContain('');
    }
});

it('is deterministic', function (): void {
    expect(searchIndex()->emit(indexedSpec())[0]->contents)
        ->toBe(searchIndex()->emit(indexedSpec())[0]->contents);
});
