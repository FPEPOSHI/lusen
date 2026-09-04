<?php

declare(strict_types=1);

use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\HttpMethod;
use Lusen\Support\Versions;

function versioned(string $uri, ?string $version, bool $deprecated = false): Endpoint
{
    return Endpoint::make(HttpMethod::Get, $uri)->with(version: $version, deprecated: $deprecated ?: null);
}

it('reads a version out of the leading path segments', function (string $uri, ?string $expected): void {
    expect(Versions::fromUri($uri))->toBe($expected);
})->with([
    ['api/v1/orders', 'v1'],
    ['api/v2/orders', 'v2'],
    ['/api/v10/orders', 'v10'],
    ['v3/orders', 'v3'],
    ['api/v2.1/orders', 'v2.1'],
    ['api/2026-01-15/orders', '2026-01-15'],
    ['API/V2/orders', 'v2'],
    ['api/orders', null],
    ['api/orders/v2', null],
    ['api/reports/2026-01-15', null],
    ['', null],
]);

it('stops looking at the first segment that is not a version', function (): void {
    // A prefix Lusen does not know about hides the version rather than making
    // one up out of a segment further along.
    expect(Versions::fromUri('api/public/v1/orders'))->toBeNull();
});

it('reads a version out of a route name when the path has none', function (): void {
    expect(Versions::fromRouteName('api.v2.orders.index'))->toBe('v2')
        ->and(Versions::fromRouteName('v3.orders.index'))->toBe('v3')
        ->and(Versions::fromRouteName('orders.index'))->toBeNull()
        ->and(Versions::fromRouteName(null))->toBeNull();
});

it('strips the version segment to get the identity of an operation', function (): void {
    expect(Versions::strip('api/v1/orders/{order}'))->toBe('api/orders/{order}')
        ->and(Versions::strip('api/v2/orders/{order}'))->toBe('api/orders/{order}')
        ->and(Versions::strip('api/orders'))->toBe('api/orders');
});

it('orders versions newest first, numerically', function (): void {
    expect(Versions::sort(['v2', 'v10', 'v1', 'v9']))->toBe(['v10', 'v9', 'v2', 'v1']);
});

it('orders point releases within a major version', function (): void {
    expect(Versions::sort(['v2', 'v2.10', 'v2.2']))->toBe(['v2.10', 'v2.2', 'v2']);
});

it('falls back to natural ordering for versions it cannot parse', function (): void {
    expect(Versions::sort(['2026-01-15', '2026-02-01']))->toBe(['2026-02-01', '2026-01-15']);
});

it('catalogues nothing when no endpoint declares a version', function (): void {
    expect(Versions::catalogue([versioned('api/orders', null)]))->toBe([]);
});

it('makes the newest version current', function (): void {
    $catalogue = Versions::catalogue([versioned('api/v1/a', 'v1'), versioned('api/v2/a', 'v2')]);

    expect(array_map(fn ($v): string => $v->name, $catalogue))->toBe(['v2', 'v1'])
        ->and($catalogue[0]->current)->toBeTrue()
        ->and($catalogue[1]->current)->toBeFalse();
});

it('honours a configured current version', function (): void {
    $catalogue = Versions::catalogue(
        [versioned('api/v1/a', 'v1'), versioned('api/v2/a', 'v2')],
        ['current' => 'v1'],
    );

    expect($catalogue[1]->name)->toBe('v1')
        ->and($catalogue[1]->current)->toBeTrue();
});

it('ignores a configured current version that does not exist', function (): void {
    // A typo must not leave every version looking superseded.
    $catalogue = Versions::catalogue(
        [versioned('api/v1/a', 'v1'), versioned('api/v2/a', 'v2')],
        ['current' => 'v7'],
    );

    expect($catalogue[0]->name)->toBe('v2')
        ->and($catalogue[0]->current)->toBeTrue();
});

it('reads deprecations as a list, as a map of sunset dates, or both', function (): void {
    $catalogue = Versions::catalogue(
        [versioned('api/v1/a', 'v1'), versioned('api/v2/a', 'v2'), versioned('api/v3/a', 'v3')],
        ['deprecated' => ['v1', 'v2' => '2026-06-01']],
    );

    expect($catalogue[1]->name)->toBe('v2')
        ->and($catalogue[1]->deprecated)->toBeTrue()
        ->and($catalogue[1]->sunset)->toBe('2026-06-01')
        ->and($catalogue[2]->deprecated)->toBeTrue()
        ->and($catalogue[2]->sunset)->toBeNull();
});

it('deprecates a version whose every endpoint is already deprecated', function (): void {
    $catalogue = Versions::catalogue([
        versioned('api/v1/a', 'v1', deprecated: true),
        versioned('api/v1/b', 'v1', deprecated: true),
        versioned('api/v2/a', 'v2'),
    ]);

    expect($catalogue[1]->deprecated)->toBeTrue();
});

it('leaves a version alone when only some of its endpoints are deprecated', function (): void {
    $catalogue = Versions::catalogue([
        versioned('api/v1/a', 'v1', deprecated: true),
        versioned('api/v1/b', 'v1'),
        versioned('api/v2/a', 'v2'),
    ]);

    expect($catalogue[1]->deprecated)->toBeFalse();
});

it('never derives deprecation for the current version', function (): void {
    // Every endpoint in the newest version carrying @deprecated says
    // something, but not that the version to write against is finished.
    $catalogue = Versions::catalogue([
        versioned('api/v1/a', 'v1'),
        versioned('api/v2/a', 'v2', deprecated: true),
    ]);

    expect($catalogue[0]->name)->toBe('v2')
        ->and($catalogue[0]->deprecated)->toBeFalse();
});

it('points an older endpoint at the same operation in a newer version', function (): void {
    $endpoints = [
        Endpoint::make(HttpMethod::Get, 'api/v1/orders', 'v1.orders.index')->with(version: 'v1'),
        Endpoint::make(HttpMethod::Get, 'api/v2/orders', 'v2.orders.index')->with(version: 'v2'),
    ];

    $related = Versions::relate($endpoints, Versions::catalogue($endpoints));

    expect($related[0]->supersededBy)->toBe('v2.orders.index')
        ->and($related[1]->supersededBy)->toBeNull();
});

it('skips a version that dropped the operation and finds the one that kept it', function (): void {
    $endpoints = [
        Endpoint::make(HttpMethod::Get, 'api/v1/orders', 'v1.orders.index')->with(version: 'v1'),
        Endpoint::make(HttpMethod::Get, 'api/v2/invoices', 'v2.invoices.index')->with(version: 'v2'),
        Endpoint::make(HttpMethod::Get, 'api/v3/orders', 'v3.orders.index')->with(version: 'v3'),
    ];

    $related = Versions::relate($endpoints, Versions::catalogue($endpoints));

    expect($related[0]->supersededBy)->toBe('v3.orders.index');
});

it('pairs operations by path rather than by route name', function (): void {
    // The names share nothing; the paths are the same operation.
    $endpoints = [
        Endpoint::make(HttpMethod::Get, 'api/v1/orders/{order}', 'legacy.order')->with(version: 'v1'),
        Endpoint::make(HttpMethod::Get, 'api/v2/orders/{order}', 'orders.show')->with(version: 'v2'),
    ];

    $related = Versions::relate($endpoints, Versions::catalogue($endpoints));

    expect($related[0]->supersededBy)->toBe('orders.show');
});

it('does not pair operations that differ by method', function (): void {
    $endpoints = [
        Endpoint::make(HttpMethod::Get, 'api/v1/orders', 'v1.orders.index')->with(version: 'v1'),
        Endpoint::make(HttpMethod::Post, 'api/v2/orders', 'v2.orders.store')->with(version: 'v2'),
    ];

    $related = Versions::relate($endpoints, Versions::catalogue($endpoints));

    expect($related[0]->supersededBy)->toBeNull();
});

it('deprecates every endpoint in a deprecated version without un-deprecating others', function (): void {
    $endpoints = [
        Endpoint::make(HttpMethod::Get, 'api/v1/orders', 'v1.orders.index')->with(version: 'v1'),
        Endpoint::make(HttpMethod::Get, 'api/v2/orders', 'v2.orders.index')->with(version: 'v2'),
        Endpoint::make(HttpMethod::Get, 'api/v2/legacy', 'v2.legacy')->with(version: 'v2', deprecated: true),
    ];

    $related = Versions::relate($endpoints, Versions::catalogue($endpoints, ['deprecated' => ['v1']]));

    expect($related[0]->deprecated)->toBeTrue()
        ->and($related[1]->deprecated)->toBeFalse()
        // Its own @deprecated survives the current version not being deprecated.
        ->and($related[2]->deprecated)->toBeTrue();
});

it('leaves endpoints alone when the api has no versions at all', function (): void {
    $endpoints = [Endpoint::make(HttpMethod::Get, 'api/orders', 'orders.index')];

    expect(Versions::relate($endpoints, []))->toBe($endpoints);
});
