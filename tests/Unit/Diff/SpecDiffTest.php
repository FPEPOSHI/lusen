<?php

declare(strict_types=1);

use Lusen\Diff\Change;
use Lusen\Diff\Severity;
use Lusen\Diff\SpecDiff;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\ApiVersion;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\HttpMethod;
use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Ir\Enums\SchemaType;
use Lusen\Ir\Group;
use Lusen\Ir\Parameter;
use Lusen\Ir\Response;
use Lusen\Ir\Schema;
use Lusen\Ir\SecurityScheme;

/**
 * Built by hand and compared in memory: the whole point of putting the diff
 * behind the IR is that it needs no application, no routes and no filesystem.
 */
function diffSpec(Endpoint ...$endpoints): ApiSpec
{
    return new ApiSpec(title: 'Test API', groups: [new Group('Users', array_values($endpoints))]);
}

function diffEndpoint(string $uri = 'api/users/{user}', string $name = 'users.show'): Endpoint
{
    return Endpoint::make(HttpMethod::Get, $uri, $name);
}

/**
 * @param  list<Change>  $changes
 * @return list<string>
 */
function kinds(array $changes): array
{
    return array_values(array_map(static fn (Change $c): string => $c->kind, $changes));
}

function firstChange(ApiSpec $before, ApiSpec $after): Change
{
    $changes = SpecDiff::between($before, $after);

    expect($changes)->toHaveCount(1);

    return $changes[0];
}

it('reports nothing when nothing moved', function (): void {
    expect(SpecDiff::between(diffSpec(diffEndpoint()), diffSpec(diffEndpoint())))->toBe([]);
});

it('calls a removed endpoint breaking and an added one additive', function (): void {
    $removed = firstChange(diffSpec(diffEndpoint()), diffSpec());

    expect($removed->kind)->toBe('endpoint.removed')
        ->and($removed->severity)->toBe(Severity::Breaking)
        ->and($removed->subject)->toBe('GET /api/users/{user}');

    $added = firstChange(diffSpec(), diffSpec(diffEndpoint()));

    expect($added->kind)->toBe('endpoint.added')
        ->and($added->severity)->toBe(Severity::Additive);
});

it('reads a changed id as a rename rather than a removal and an addition', function (): void {
    // The id is the operationId, the anchor and the citation target, so this
    // is the one thing that breaks without any request changing shape.
    $change = firstChange(
        diffSpec(diffEndpoint(name: 'users.show')),
        diffSpec(diffEndpoint(name: 'users.detail')),
    );

    expect($change->kind)->toBe('endpoint.renamed')
        ->and($change->severity)->toBe(Severity::Breaking)
        ->and($change->detail)->toContain('`users.show`')
        ->and($change->detail)->toContain('`users.detail`');
});

it('treats a new required parameter as breaking and a new optional one as not', function (): void {
    $before = diffSpec(diffEndpoint());

    $required = firstChange($before, diffSpec(diffEndpoint()->with(parameters: [
        new Parameter('idempotency_key', ParameterLocation::Body, Schema::string(), required: true),
    ])));

    expect($required->kind)->toBe('parameter.added')
        ->and($required->severity)->toBe(Severity::Breaking);

    $optional = firstChange($before, diffSpec(diffEndpoint()->with(parameters: [
        new Parameter('note', ParameterLocation::Body, Schema::string()),
    ])));

    expect($optional->severity)->toBe(Severity::Additive);
});

it('reports a parameter that became required, and one that stopped being', function (): void {
    $optional = diffSpec(diffEndpoint()->with(parameters: [
        new Parameter('email', ParameterLocation::Body, Schema::string()),
    ]));

    $required = diffSpec(diffEndpoint()->with(parameters: [
        new Parameter('email', ParameterLocation::Body, Schema::string(), required: true),
    ]));

    expect(firstChange($optional, $required))
        ->kind->toBe('parameter.required')
        ->severity->toBe(Severity::Breaking);

    expect(firstChange($required, $optional))
        ->kind->toBe('parameter.optional')
        ->severity->toBe(Severity::Additive);
});

it('does not pair a path parameter with a query parameter of the same name', function (): void {
    $before = diffSpec(diffEndpoint()->with(parameters: [
        new Parameter('id', ParameterLocation::Path, Schema::integer(), required: true),
    ]));

    $after = diffSpec(diffEndpoint()->with(parameters: [
        new Parameter('id', ParameterLocation::Query, Schema::integer()),
    ]));

    // A removal and an addition, not a silent relocation.
    expect(kinds(SpecDiff::between($before, $after)))->toBe(['parameter.removed', 'parameter.added']);
});

it('calls a changed parameter type breaking', function (): void {
    $change = firstChange(
        diffSpec(diffEndpoint()->with(parameters: [
            new Parameter('user', ParameterLocation::Path, Schema::integer(), required: true),
        ])),
        diffSpec(diffEndpoint()->with(parameters: [
            new Parameter('user', ParameterLocation::Path, Schema::string(), required: true),
        ])),
    );

    expect($change->kind)->toBe('schema.type')
        ->and($change->severity)->toBe(Severity::Breaking)
        ->and($change->detail)->toContain('`integer`')
        ->and($change->detail)->toContain('`string`');
});

it('does not fail a build because the extractors learned a type', function (): void {
    // A model cast landing turns `any` into `integer` on every endpoint that
    // returns the field. That is a better docs build, and a gate that goes
    // red for it is a gate somebody switches off.
    $before = diffSpec(diffEndpoint()->with(responses: [
        new Response(200, schema: Schema::object(['price' => new Schema(type: SchemaType::Any)])),
    ]));

    $after = diffSpec(diffEndpoint()->with(responses: [
        new Response(200, schema: Schema::object(['price' => Schema::number()])),
    ]));

    $change = firstChange($before, $after);

    expect($change->kind)->toBe('schema.typed')
        ->and($change->severity)->toBe(Severity::Notice);

    expect(SpecDiff::breaks(SpecDiff::between($before, $after)))->toBeFalse();
});

it('reports a removed response field, by the path the docs call it', function (): void {
    $change = firstChange(
        diffSpec(diffEndpoint()->with(responses: [
            new Response(200, schema: Schema::object([
                'data' => Schema::arrayOf(Schema::object(['id' => Schema::integer(), 'email' => Schema::string()])),
            ])),
        ])),
        diffSpec(diffEndpoint()->with(responses: [
            new Response(200, schema: Schema::object([
                'data' => Schema::arrayOf(Schema::object(['id' => Schema::integer()])),
            ])),
        ])),
    );

    expect($change->kind)->toBe('response.field.removed')
        ->and($change->severity)->toBe(Severity::Breaking)
        ->and($change->detail)->toContain('`data[].email`');
});

it('separates a dropped success from a dropped error', function (): void {
    $endpoint = diffEndpoint()->with(responses: [
        new Response(200, schema: Schema::object(['id' => Schema::integer()])),
        new Response(404),
    ]);

    $withoutError = diffEndpoint()->with(responses: [
        new Response(200, schema: Schema::object(['id' => Schema::integer()])),
    ]);

    $withoutSuccess = diffEndpoint()->with(responses: [new Response(404)]);

    // Losing a documented 404 is the docs drifting; losing the 200 is the
    // API no longer doing what somebody's code expects.
    expect(firstChange(diffSpec($endpoint), diffSpec($withoutError)))
        ->kind->toBe('response.undocumented')
        ->severity->toBe(Severity::Notice);

    expect(firstChange(diffSpec($endpoint), diffSpec($withoutSuccess)))
        ->kind->toBe('response.removed')
        ->severity->toBe(Severity::Breaking);
});

it('flags an endpoint that started requiring authentication, and one that stopped', function (): void {
    $public = diffSpec(diffEndpoint());
    $private = diffSpec(diffEndpoint()->with(authenticated: true));

    expect(firstChange($public, $private))
        ->kind->toBe('endpoint.authenticated')
        ->severity->toBe(Severity::Breaking);

    // Nothing breaks when an endpoint opens up, but it is the last change
    // that should go out unnoticed.
    expect(firstChange($private, $public))
        ->kind->toBe('endpoint.public')
        ->severity->toBe(Severity::Notice);
});

it('reports a newly required scope', function (): void {
    $change = firstChange(
        diffSpec(diffEndpoint()->with(authenticated: true, security: new SecurityScheme(scopes: ['orders:read']))),
        diffSpec(diffEndpoint()->with(authenticated: true, security: new SecurityScheme(scopes: ['orders:read', 'orders:write']))),
    );

    expect($change->kind)->toBe('scope.required')
        ->and($change->severity)->toBe(Severity::Breaking)
        ->and($change->detail)->toContain('`orders:write`');
});

it('knows which way a bound tightens', function (): void {
    $with = fn (array $constraints): ApiSpec => diffSpec(diffEndpoint()->with(parameters: [
        new Parameter('note', ParameterLocation::Body, new Schema(constraints: $constraints)),
    ]));

    expect(firstChange($with(['maxLength' => 255]), $with(['maxLength' => 50])))
        ->kind->toBe('schema.constraint')
        ->severity->toBe(Severity::Breaking);

    expect(firstChange($with(['maxLength' => 50]), $with(['maxLength' => 255])))
        ->severity->toBe(Severity::Additive);

    // A rule read statically can go missing because it moved somewhere the
    // parser cannot follow, so an absent bound is never called a change.
    expect(SpecDiff::between($with(['maxLength' => 50]), $with([])))->toBe([]);
});

it('reads an enum both ways round', function (): void {
    $accepts = fn (array $values): ApiSpec => diffSpec(diffEndpoint()->with(parameters: [
        new Parameter('status', ParameterLocation::Query, Schema::enum($values)),
    ]));

    expect(firstChange($accepts(['open', 'closed']), $accepts(['open'])))
        ->severity->toBe(Severity::Breaking);

    expect(firstChange($accepts(['open']), $accepts(['open', 'closed'])))
        ->severity->toBe(Severity::Additive);

    // A response gaining a member breaks no request, but a client matching
    // on the old set will not recognise it.
    $returns = fn (array $values): ApiSpec => diffSpec(diffEndpoint()->with(responses: [
        new Response(200, schema: Schema::object(['status' => Schema::enum($values)])),
    ]));

    expect(firstChange($returns(['open']), $returns(['open', 'closed'])))
        ->severity->toBe(Severity::Notice);
});

it('calls a response field that can now be null breaking, and a request field that can not additive', function (): void {
    $response = fn (bool $nullable): ApiSpec => diffSpec(diffEndpoint()->with(responses: [
        new Response(200, schema: Schema::object(['name' => new Schema(nullable: $nullable)])),
    ]));

    expect(firstChange($response(false), $response(true)))
        ->kind->toBe('schema.nullable')
        ->severity->toBe(Severity::Breaking);

    $request = fn (bool $nullable): ApiSpec => diffSpec(diffEndpoint()->with(parameters: [
        new Parameter('name', ParameterLocation::Body, new Schema(nullable: $nullable)),
    ]));

    expect(firstChange($request(false), $request(true)))->severity->toBe(Severity::Additive);
});

it('reports a version that is no longer served', function (): void {
    $before = new ApiSpec(title: 'Test API', versions: [new ApiVersion('v2'), new ApiVersion('v1')]);
    $after = new ApiSpec(title: 'Test API', versions: [new ApiVersion('v2')]);

    expect(firstChange($before, $after))
        ->kind->toBe('version.removed')
        ->subject->toBe('v1')
        ->severity->toBe(Severity::Breaking);
});

it('notices a deprecation without failing on it', function (): void {
    $changes = SpecDiff::between(
        diffSpec(diffEndpoint()),
        diffSpec(diffEndpoint()->with(deprecated: true)),
    );

    expect(kinds($changes))->toBe(['endpoint.deprecated'])
        ->and(SpecDiff::breaks($changes))->toBeFalse();
});

it('counts what it found, by severity', function (): void {
    $changes = SpecDiff::between(
        diffSpec(diffEndpoint(), diffEndpoint('api/orders', 'orders.index')),
        diffSpec(diffEndpoint()->with(deprecated: true, parameters: [
            new Parameter('note', ParameterLocation::Body, Schema::string()),
        ])),
    );

    expect(SpecDiff::tally($changes))->toBe(['breaking' => 1, 'additive' => 1, 'notice' => 1])
        ->and(SpecDiff::breaks($changes))->toBeTrue();
});
