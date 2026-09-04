<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\Enums\SchemaType;
use Lusen\SpecBuilder;
use Lusen\Tests\Fixtures\EnvelopeController;

function envelopeSpec(): ApiSpec
{
    return app(SpecBuilder::class)->build();
}

beforeEach(function (): void {
    Route::get('api/ping', [EnvelopeController::class, 'pong'])->name('ping');
    Route::get('api/report', [EnvelopeController::class, 'report'])->name('report');
    Route::delete('api/nothing', [EnvelopeController::class, 'nothing'])->name('nothing');
});

it('follows the response helper and documents the envelope it wraps', function (): void {
    $schema = envelopeSpec()->endpoint('ping')?->responses[0]->schema;

    expect(array_keys($schema?->properties ?? []))->toBe(['status', 'data'])
        ->and($schema->properties['status']->type)->toBe(SchemaType::Boolean);
});

it('puts the payload where the helper actually puts it', function (): void {
    $data = envelopeSpec()->endpoint('ping')?->responses[0]->schema?->properties['data'];

    expect($data?->properties['pong']->type)->toBe(SchemaType::Integer)
        ->and($data->properties['ip']->type)->toBe(SchemaType::String);
});

it('still documents the envelope when the payload is beyond reach', function (): void {
    // A reader who does not know their result arrives under `data` will look
    // in the wrong place, so the wrapper is worth stating on its own.
    $schema = envelopeSpec()->endpoint('report')?->responses[0]->schema;

    expect(array_keys($schema?->properties ?? []))->toBe(['status', 'data'])
        ->and($schema->properties['data']->type)->toBe(SchemaType::Any);
});

it('carries a helper that returns no content through as 204', function (): void {
    $responses = envelopeSpec()->endpoint('nothing')?->responses;

    expect($responses[0]->status)->toBe(204)
        ->and($responses[0]->schema)->toBeNull();
});

it('documents the main return rather than a guard clause', function (): void {
    Route::get('api/guarded', [EnvelopeController::class, 'guarded'])->name('guarded');

    // The error envelope returns first. Documenting it as the success response
    // would be worse than documenting nothing, because nothing about the page
    // would tell the reader it is wrong.
    $schema = envelopeSpec()->endpoint('guarded')?->responses[0]->schema;

    expect(array_keys($schema?->properties ?? []))->toBe(['status', 'data'])
        ->and($schema->properties['data']->properties['ok']->type)->toBe(SchemaType::Boolean);
});
