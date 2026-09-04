<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lusen\Emit\OpenApiEmitter;
use Lusen\Extract\Resources\ResourceReader;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\Schema;
use Lusen\SpecBuilder;
use Lusen\Support\Ast;
use Lusen\Tests\Fixtures\ProfileController;

function resourceSpec()
{
    return app(SpecBuilder::class)->build();
}

function successSchema(string $id): ?Schema
{
    foreach (resourceSpec()->endpoint($id)?->responses ?? [] as $response) {
        if ($response->isSuccess()) {
            return $response->schema;
        }
    }

    return null;
}

beforeEach(function (): void {
    Ast::flushCache();
    ResourceReader::flushCache();

    Route::get('api/profiles', [ProfileController::class, 'index'])->name('profiles.index');
    Route::get('api/profiles/all', [ProfileController::class, 'all'])->name('profiles.all');
    Route::get('api/profiles/{profile}', [ProfileController::class, 'show'])->name('profiles.show');
    Route::post('api/profiles', [ProfileController::class, 'store'])->name('profiles.store');
    Route::get('api/bare', [ProfileController::class, 'bare'])->name('profiles.bare');
    Route::get('api/literal', [ProfileController::class, 'literal'])->name('profiles.literal');
    Route::delete('api/profiles/{profile}', [ProfileController::class, 'destroy'])->name('profiles.destroy');
    Route::get('api/teams', [ProfileController::class, 'team'])->name('profiles.team');
    Route::get('api/unknown', [ProfileController::class, 'unknown'])->name('profiles.unknown');
});

it('documents a single resource wrapped in data', function (): void {
    $schema = successSchema('profiles.show');

    expect($schema?->type->value)->toBe('object')
        ->and($schema?->properties)->toHaveKey('data')
        ->and($schema?->properties['data']->properties)->toHaveKeys(['id', 'name', 'email']);
});

it('documents a collection as an array inside data', function (): void {
    $data = successSchema('profiles.all')?->properties['data'] ?? null;

    expect($data?->type->value)->toBe('array')
        ->and($data?->items?->properties)->toHaveKey('id');
});

it('adds pagination links and meta only for a paginated collection', function (): void {
    expect(successSchema('profiles.index')?->properties)->toHaveKeys(['data', 'links', 'meta'])
        ->and(successSchema('profiles.index')?->properties['meta']->properties)->toHaveKey('current_page')
        ->and(successSchema('profiles.all')?->properties)->not->toHaveKey('meta');
});

it('honours a resource that opts out of wrapping', function (): void {
    $schema = successSchema('profiles.bare');

    expect($schema?->properties)->toHaveKey('id')
        ->and($schema?->properties)->not->toHaveKey('data');
});

it('types fields from laravel naming conventions', function (): void {
    $data = successSchema('profiles.show')?->properties['data'];

    expect($data?->properties['id']->type->value)->toBe('integer')
        ->and($data?->properties['created_at']->format)->toBe('date-time')
        ->and($data?->properties['orders_count']->type->value)->toBe('integer')
        ->and($data?->properties['is_active']->type->value)->toBe('boolean')
        ->and($data?->properties['email']->format)->toBe('email')
        ->and($data?->properties['avatar_url']->format)->toBe('uri');
});

it('treats an explicit cast as authoritative', function (): void {
    $data = successSchema('profiles.show')?->properties['data'];

    expect($data?->properties['reputation']->type->value)->toBe('integer')
        ->and($data?->properties['balance']->type->value)->toBe('number')
        ->and($data?->properties['verified']->type->value)->toBe('boolean')
        ->and($data?->properties['nickname']->type->value)->toBe('string');
});

it('types a literal value from the literal itself', function (): void {
    expect(successSchema('profiles.show')?->properties['data']->properties['kind']->type->value)
        ->toBe('string');
});

it('leaves a field it cannot type as any rather than guessing string', function (): void {
    // Documenting an unknown type as `string` is a guess a reader would act on.
    expect(successSchema('profiles.show')?->properties['data']->properties['mystery']->type->value)
        ->toBe('any');
});

it('reads a nested array literal as a nested object', function (): void {
    $address = successSchema('profiles.show')?->properties['data']->properties['address'];

    expect($address?->type->value)->toBe('object')
        ->and($address?->properties)->toHaveKeys(['city', 'postcode']);
});

it('recurses into a nested resource', function (): void {
    $team = successSchema('profiles.show')?->properties['data']->properties['team'];

    expect($team?->type->value)->toBe('object')
        ->and($team?->properties)->toHaveKeys(['id', 'name']);
});

it('recurses into a nested resource collection', function (): void {
    $posts = successSchema('profiles.show')?->properties['data']->properties['posts'];

    expect($posts?->type->value)->toBe('array')
        ->and($posts?->items?->properties)->toHaveKeys(['id', 'title']);
});

it('terminates on a cyclic resource graph', function (): void {
    // UserResource -> PostResource -> UserResource. Depth is capped rather
    // than tracked; the point is that it returns at all.
    expect(successSchema('profiles.show'))->not->toBeNull();
});

it('reads the value of a conditional field', function (): void {
    $settings = successSchema('profiles.show')?->properties['data']->properties['settings'];

    expect($settings?->type->value)->toBe('array');
});

it('reads a literal response body and its status', function (): void {
    $endpoint = resourceSpec()->endpoint('profiles.literal');
    $response = $endpoint?->responses[0];

    expect($response?->status)->toBe(202)
        ->and($response?->schema?->properties)->toHaveKeys(['status', 'queued'])
        ->and($response?->schema?->properties['queued']->type->value)->toBe('integer');
});

it('documents noContent as a 204 with no schema', function (): void {
    $response = resourceSpec()->endpoint('profiles.destroy')?->responses[0];

    expect($response?->status)->toBe(204)
        ->and($response?->schema)->toBeNull();
});

it('uses 201 for a post that returns a resource', function (): void {
    expect(resourceSpec()->endpoint('profiles.store')?->responses[0]->status)->toBe(201);
});

it('attaches a generated example alongside the schema', function (): void {
    $response = resourceSpec()->endpoint('profiles.show')?->responses[0];

    expect($response?->examples)->not->toBe([])
        ->and($response?->examples[0]->value)->toHaveKey('data');
});

it('records the resource file for incremental rebuilds', function (): void {
    expect(implode(' ', resourceSpec()->endpoint('profiles.show')?->sourceFiles ?? []))
        ->toContain('UserResource.php');
});

it('leaves an endpoint alone when the return says nothing readable', function (): void {
    expect(resourceSpec()->endpoint('profiles.unknown')?->responses)->toBe([]);
});

it('round-trips a derived response schema through the ir', function (): void {
    $spec = resourceSpec();

    expect(ApiSpec::fromJson($spec->toJson())->toJson())->toBe($spec->toJson());
});

it('uses a literal value from the resource as the example', function (): void {
    $kind = successSchema('profiles.show')?->properties['data']->properties['kind'];

    expect($kind?->example)->toBe('user');
});

it('emits an untyped field as a valid empty schema object, not an array', function (): void {
    // PHP encodes an empty array as [], which is not a valid JSON Schema.
    $document = (new OpenApiEmitter)->document(resourceSpec());
    $json = json_encode($document, JSON_UNESCAPED_SLASHES);

    expect($json)->toContain('"postcode":{}')
        ->and($json)->not->toContain('"postcode":[]');
});
