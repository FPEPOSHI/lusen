<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lusen\Extract\AttributeExtractor;
use Lusen\Extract\ControllerExtractor;
use Lusen\Extract\FormRequestExtractor;
use Lusen\Extract\ResourceExtractor;
use Lusen\Extract\RouteExtractor;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Ir\Enums\SchemaType;
use Lusen\Ir\Parameter;
use Lusen\SpecBuilder;
use Lusen\Tests\Fixtures\PlainController;
use Lusen\Tests\Fixtures\ScrambledController;

function scrambledSpec(): ApiSpec
{
    return app(SpecBuilder::class)->build();
}

function scrambledParam(string $id, string $name): ?Parameter
{
    foreach (scrambledSpec()->endpoint($id)?->parameters ?? [] as $parameter) {
        if ($parameter->name === $name) {
            return $parameter;
        }
    }

    return null;
}

beforeEach(function (): void {
    Route::get('api/clients', [ScrambledController::class, 'index'])->name('clients.index');
    Route::get('api/clients/{id}', [ScrambledController::class, 'show'])->name('clients.show');
    Route::get('api/clients/ours', [ScrambledController::class, 'ours'])->name('clients.ours');
});

it('reads attributes whose class is not installed at all', function (): void {
    // Nothing declares Dedoc\Scramble\Attributes\Group; reflection still
    // reports its name and arguments, which is what makes this work for a
    // codebase that has already dropped the dependency.
    expect(class_exists('Dedoc\\Scramble\\Attributes\\Group'))->toBeFalse()
        ->and(scrambledSpec()->endpoint('clients.index')?->group)->toBe('Klienti');
});

it('reads the group weight as the group order', function (): void {
    // An API that already told Scramble which group comes first should not
    // have to say it twice.
    $group = scrambledSpec()->groups[0];

    expect($group->name)->toBe('Klienti')
        ->and($group->order)->toBe(4);
});

it('treats Scramble\'s unweighted default as no order at all', function (): void {
    // Scramble spells "no weight" as PHP_INT_MAX; carrying that into the IR
    // would serialize a nineteen-digit number meaning nothing.
    Route::get('api/widgets', [PlainController::class, 'index'])->name('widgets.index');

    $weightless = array_values(array_filter(
        scrambledSpec()->groups,
        fn ($group): bool => $group->name !== 'Klienti' && $group->name !== 'Lusen has the last word',
    ));

    expect($weightless[0]->order)->toBeNull();
});

it('documents a response per status, with a schema read from the declared type', function (): void {
    $responses = scrambledSpec()->endpoint('clients.index')?->responses ?? [];

    $statuses = array_map(fn ($response): int => $response->status, $responses);

    expect($statuses)->toBe([200, 422])
        ->and($responses[0]->description)->toBe('A page of clients.')
        ->and($responses[0]->schema?->properties['data']->properties['currency']->type)->toBe(SchemaType::String);
});

it('keeps a response whose type says nothing, for its description alone', function (): void {
    $responses = scrambledSpec()->endpoint('clients.index')?->responses ?? [];

    expect($responses[1]->status)->toBe(422)
        ->and($responses[1]->description)->toBe('The dates were unusable.')
        ->and($responses[1]->schema)->toBeNull();
});

it('documents a query parameter, appending the default to the description', function (): void {
    $limit = scrambledParam('clients.index', 'limit');

    expect($limit?->in)->toBe(ParameterLocation::Query)
        ->and($limit->schema->type)->toBe(SchemaType::Integer)
        ->and($limit->schema->example)->toBe(20)
        // The schema has nowhere to keep a default, and a caller needs it.
        ->and($limit->description)->toBe('How many per page. Defaults to `20`.');
});

it('leaves a parameter with no default alone', function (): void {
    expect(scrambledParam('clients.index', 'cursor')?->description)->toBe('Where to resume from.');
});

it('describes a path parameter and keeps it required whatever the attribute says', function (): void {
    $id = scrambledParam('clients.show', 'id');

    expect($id?->in)->toBe(ParameterLocation::Path)
        ->and($id->required)->toBeTrue()
        ->and($id->description)->toBe('The client id.')
        ->and($id->schema->example)->toBe(42);
});

it('lets Lusen own attributes overrule the foreign ones', function (): void {
    expect(scrambledSpec()->endpoint('clients.ours')?->group)->toBe('Lusen has the last word');
});

it('gives Lusen own attribute the same reach, through the same reader', function (): void {
    // #[ApiResponse(type:)] is not a Scramble concept - it is ours, taking the
    // grammar `@response` already uses, so nobody has to keep a foreign
    // attribute around just to describe a body.
    $responses = scrambledSpec()->endpoint('clients.ours')?->responses ?? [];

    expect(array_map(fn ($r): int => $r->status, $responses))->toBe([200, 404])
        ->and($responses[0]->schema?->properties['items']->items?->type)->toBe(SchemaType::String)
        ->and($responses[1]->schema?->properties['currency']->type)->toBe(SchemaType::String);
});

it('generates an example from the declared type when none is written', function (): void {
    $example = scrambledSpec()->endpoint('clients.ours')?->responses[0]->examples[0] ?? null;

    expect($example?->value)->toHaveKey('ok')->toHaveKey('items');
});

it('reads scramble attributes with nothing configured at all', function (): void {
    // The whole `attributes` section gone, as it is in an application that
    // never published the config - and as it effectively is in one that
    // published it before this support existed, since Laravel merges a
    // published file over ours whole rather than key by key.
    config()->set('lusen.attributes', null);

    expect(scrambledSpec()->endpoint('clients.index')?->group)->toBe('Klienti');
});

it('cannot lose scramble because an app listed its own namespace', function (): void {
    // The failure this is here to stop: `external` used to be the entire
    // list, so adding one namespace to it removed every namespace Lusen
    // ships. Config adds to the built-ins now, never replaces them.
    config()->set('lusen.attributes.external', ['Acme\\Docs\\Attributes\\']);

    expect(scrambledSpec()->endpoint('clients.index')?->group)->toBe('Klienti');
});

it('reads them even when a published extractor list has never heard of the reader', function (): void {
    // A published `extractors` array is a snapshot of the pipeline the day
    // somebody ran vendor:publish. This is that array from before the foreign
    // attribute reader existed.
    config()->set('lusen.extractors', [
        RouteExtractor::class,
        ControllerExtractor::class,
        FormRequestExtractor::class,
        ResourceExtractor::class,
        AttributeExtractor::class,
    ]);

    expect(scrambledSpec()->endpoint('clients.index')?->group)->toBe('Klienti');
});

it('still lets Lusen own attributes win when the reader was put back', function (): void {
    // Restoring it must not cost the ordering invariant: it goes in before
    // AttributeExtractor, so an #[ApiDoc] still overrules a foreign group.
    config()->set('lusen.extractors', [
        RouteExtractor::class,
        ControllerExtractor::class,
        AttributeExtractor::class,
    ]);

    expect(scrambledSpec()->endpoint('clients.ours')?->group)->toBe('Lusen has the last word');
});

it('ignores them all when the application says so', function (): void {
    config()->set('lusen.attributes.read_external', false);

    expect(scrambledSpec()->endpoint('clients.index')?->group)->not->toBe('Klienti');
});
