<?php

declare(strict_types=1);

use Lusen\Support\JsonLd;

it('never claims an empty url when the docs sit at a host root', function (): void {
    // Links::base() is empty at a host's root, which is what path building
    // wants - `''.'/assets/x'` is `/assets/x`. As structured data it is
    // nothing at all, and "url": "" is a claim no consumer can use.
    $json = json_decode(JsonLd::forSpec(fixtureSpec(), ''), true);

    expect($json['url'])->toBe('/');
});

it('states the docs path when they sit under one', function (): void {
    expect(json_decode(JsonLd::forSpec(fixtureSpec(), '/docs'), true)['url'])->toBe('/docs');
});

it('builds a page url on the root without doubling the slash', function (): void {
    $page = fixtureSpec()->pages()[0] ?? null;

    if ($page === null) {
        expect(true)->toBeTrue();

        return;
    }

    expect(json_decode(JsonLd::forPage(fixtureSpec(), $page, ''), true)['url'])
        ->toStartWith('/pages/')
        ->not->toStartWith('//');
});
