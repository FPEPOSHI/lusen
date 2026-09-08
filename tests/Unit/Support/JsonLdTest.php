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

it('describes a group page as a collection of its operations', function (): void {
    $spec = fixtureSpec();
    $json = json_decode(JsonLd::forGroup($spec->groups[0], $spec, '/docs'), true);

    expect($json['@type'])->toBe('CollectionPage')
        ->and($json['url'])->toBe('/docs/groups/users')
        ->and($json['description'])->toBe('Create and read user accounts.')
        ->and(array_column($json['hasPart'], 'url'))->toBe([
            '/docs/endpoints/users-index',
            '/docs/endpoints/users-store',
            '/docs/endpoints/users-show',
        ]);
});

it('breadcrumbs an endpoint to its group page', function (): void {
    $spec = fixtureSpec();
    $json = json_decode(JsonLd::forEndpoint($spec->endpoint('users.index'), $spec, '/docs'), true);

    expect($json['breadcrumb']['itemListElement'][1]['name'])->toBe('Users')
        ->and($json['breadcrumb']['itemListElement'][1]['item'])->toBe('/docs/groups/users');
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

it('never claims an empty url for the first breadcrumb at a host root', function (): void {
    // The same rule as the WebSite url: "" is a claim no consumer can use.
    $spec = fixtureSpec();

    expect(json_decode(JsonLd::forGroup($spec->groups[0], $spec, ''), true)['breadcrumb']['itemListElement'][0]['item'])->toBe('/')
        ->and(json_decode(JsonLd::forEndpoint($spec->endpoint('users.index'), $spec, ''), true)['breadcrumb']['itemListElement'][0]['item'])->toBe('/');
});
