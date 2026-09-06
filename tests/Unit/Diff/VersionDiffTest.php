<?php

declare(strict_types=1);

use Lusen\Diff\Change;
use Lusen\Diff\Severity;
use Lusen\Diff\VersionDiff;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\ApiVersion;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\HttpMethod;
use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Ir\Group;
use Lusen\Ir\Parameter;
use Lusen\Ir\Response;
use Lusen\Ir\Schema;

/**
 * Two versions of one operation, differing in whatever the test needs.
 *
 * @param  list<Parameter>  $v1Parameters
 * @param  list<Parameter>  $v2Parameters
 */
function versionSpec(array $v1Parameters = [], array $v2Parameters = [], array $v1Responses = [], array $v2Responses = []): ApiSpec
{
    $v1 = Endpoint::make(HttpMethod::Post, 'api/v1/orders', 'v1.orders.store')
        ->with(summary: 'Create an order', version: 'v1', parameters: $v1Parameters, responses: $v1Responses);

    $v2 = Endpoint::make(HttpMethod::Post, 'api/v2/orders', 'v2.orders.store')
        ->with(summary: 'Create an order', version: 'v2', parameters: $v2Parameters, responses: $v2Responses);

    return new ApiSpec(
        title: 'Test API',
        groups: [new Group('Orders', [$v2], version: 'v2'), new Group('Orders', [$v1], version: 'v1')],
        versions: [new ApiVersion('v2', current: true), new ApiVersion('v1')],
    );
}

it('pairs two versions of an operation despite different ids and paths', function (): void {
    // The whole reason this cannot reuse SpecDiff::between(): across versions
    // the ids and the paths both differ by design.
    $spec = versionSpec(
        v2Parameters: [new Parameter('idempotency_key', ParameterLocation::Body, Schema::string(), required: true)],
    );

    $changes = VersionDiff::between($spec, 'v1', 'v2');

    expect($changes)->toHaveKey('POST api/orders')
        ->and($changes['POST api/orders'][0]->kind)->toBe('parameter.added')
        ->and($changes['POST api/orders'][0]->severity)->toBe(Severity::Breaking);
});

it('never reports the version itself as a move or a rename', function (): void {
    // Every operation would otherwise report "moved from /api/v1/orders" and
    // an id change, on every version of every endpoint.
    $kinds = array_map(
        static fn (Change $change): string => $change->kind,
        VersionDiff::between(versionSpec(), 'v1', 'v2')['POST api/orders'] ?? [],
    );

    expect($kinds)->not->toContain('endpoint.moved')
        ->and($kinds)->not->toContain('endpoint.renamed');
});

it('leaves out an operation that did not change', function (): void {
    // A migration guide listing forty untouched endpoints buries the three
    // that matter.
    expect(VersionDiff::between(versionSpec(), 'v1', 'v2'))->toBe([]);
});

it('reads a response field that v2 stopped returning', function (): void {
    $spec = versionSpec(
        v1Responses: [new Response(201, schema: Schema::object(['id' => Schema::integer(), 'legacy_ref' => Schema::string()]))],
        v2Responses: [new Response(201, schema: Schema::object(['id' => Schema::integer()]))],
    );

    $changes = VersionDiff::between($spec, 'v1', 'v2')['POST api/orders'];

    expect($changes[0]->kind)->toBe('response.field.removed')
        ->and($changes[0]->detail)->toContain('`legacy_ref`');
});

it('answers for one endpoint against the version before it', function (): void {
    $spec = versionSpec(
        v2Parameters: [new Parameter('note', ParameterLocation::Body, Schema::string())],
    );

    $v2 = $spec->endpoint('v2.orders.store');
    $v1 = $spec->endpoint('v1.orders.store');

    expect(VersionDiff::forEndpoint($spec, $v2))->toHaveCount(1);

    // The oldest version has nothing behind it to have changed from.
    expect(VersionDiff::forEndpoint($spec, $v1))->toBe([]);
});

it('says nothing for an operation the previous version never had', function (): void {
    $spec = versionedFixtureSpec();

    // v2.users.index exists in both versions; v1.exports.index is v1 only.
    expect(VersionDiff::forEndpoint($spec, $spec->endpoint('v1.exports.index')))->toBe([]);
});

it('names the version served before a given one', function (): void {
    $spec = versionedFixtureSpec();

    expect(VersionDiff::previousVersion($spec, 'v2'))->toBe('v1')
        ->and(VersionDiff::previousVersion($spec, 'v1'))->toBeNull()
        ->and(VersionDiff::previousVersion($spec, 'v9'))->toBeNull()
        ->and(VersionDiff::previousVersion($spec, null))->toBeNull();
});
