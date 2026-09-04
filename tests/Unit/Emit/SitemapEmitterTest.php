<?php

declare(strict_types=1);

use Lusen\Emit\SitemapEmitter;
use Lusen\Ir\Page;
use Lusen\Ir\Section;
use Lusen\Support\Links;

function sitemap(?string $origin = 'https://example.com', ?string $lastmod = null): SitemapEmitter
{
    return new SitemapEmitter(new Links('/docs', static: true, canonicalOrigin: $origin), $lastmod);
}

function specWithPages()
{
    return fixtureSpec()->withSections([
        new Section('Getting started', [new Page(id: 'introduction', title: 'Introduction')]),
    ]);
}

it('lists the index, then prose, then reference', function (): void {
    expect(sitemap()->urls(specWithPages()))->toBe([
        'https://example.com/docs/index.html',
        'https://example.com/docs/pages/introduction.html',
        'https://example.com/docs/endpoints/users-index.html',
        'https://example.com/docs/endpoints/users-store.html',
        'https://example.com/docs/endpoints/users-show.html',
    ]);
});

it('emits valid sitemap xml', function (): void {
    $xml = sitemap()->emit(specWithPages())[0];

    expect($xml->path)->toBe('sitemap.xml')
        ->and($xml->contentType)->toBe('application/xml')
        ->and($xml->contents)->toStartWith('<?xml version="1.0" encoding="UTF-8"?>')
        ->toContain('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">')
        ->toContain('<loc>https://example.com/docs/index.html</loc>');

    expect(simplexml_load_string($xml->contents))->not->toBeFalse();
});

it('writes nothing without a canonical origin', function (): void {
    // A sitemap requires absolute URLs; with no origin there is nothing valid
    // to write, and a relative sitemap is worse than none.
    expect(sitemap(origin: null)->emit(specWithPages()))->toBe([]);
});

it('omits lastmod rather than stamping every build with now', function (): void {
    expect(sitemap()->emit(specWithPages())[0]->contents)->not->toContain('<lastmod>');
});

it('includes lastmod when a real date is configured', function (): void {
    expect(sitemap(lastmod: '2026-02-01')->emit(specWithPages())[0]->contents)
        ->toContain('<lastmod>2026-02-01</lastmod>');
});

it('omits priority and changefreq, which search engines ignore', function (): void {
    $xml = sitemap()->emit(specWithPages())[0]->contents;

    expect($xml)->not->toContain('<priority>')
        ->and($xml)->not->toContain('<changefreq>');
});

it('escapes urls that contain xml-significant characters', function (): void {
    $links = new Links('/docs&x', static: true, canonicalOrigin: 'https://example.com');

    expect((new SitemapEmitter($links))->emit(specWithPages())[0]->contents)
        ->toContain('&amp;')
        ->and(simplexml_load_string((new SitemapEmitter($links))->emit(specWithPages())[0]->contents))
        ->not->toBeFalse();
});
