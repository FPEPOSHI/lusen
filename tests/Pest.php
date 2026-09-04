<?php

declare(strict_types=1);

use Lusen\Ir\ApiSpec;
use Lusen\Ir\ApiVersion;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\HttpMethod;
use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Ir\Example;
use Lusen\Ir\Group;
use Lusen\Ir\Parameter;
use Lusen\Ir\Response;
use Lusen\Ir\Schema;
use Lusen\Tests\TestCase;

// Unit tests cover the IR and the emitters, both of which are pure by
// design and must keep working without a booted application - that is the
// point of the IR seam, so only Feature tests get Testbench.
uses(TestCase::class)->in('Feature');

/**
 * The spec every emitter test renders.
 *
 * Hand-built rather than extracted, on purpose: emitters must be provable
 * against an ApiSpec alone, with no application, no routes and no reflection.
 * If a test here needs a booted app, an emitter is reaching past the IR.
 */
function fixtureSpec(): ApiSpec
{
    $index = Endpoint::make(HttpMethod::Get, 'api/users', 'users.index')
        ->with(
            summary: 'List users',
            description: 'A paginated list of every user.',
            group: 'Users',
            parameters: [
                new Parameter(
                    name: 'per_page',
                    in: ParameterLocation::Query,
                    schema: Schema::integer()->withExample(25),
                    description: 'Results per page.',
                ),
            ],
            responses: [
                new Response(
                    status: 200,
                    description: 'A page of users.',
                    schema: Schema::object(['data' => Schema::arrayOf(Schema::object(['id' => Schema::integer()]))]),
                    examples: [new Example('Default', ['data' => [['id' => 1]]])],
                ),
            ],
        );

    $store = Endpoint::make(HttpMethod::Post, 'api/users', 'users.store')
        ->with(
            summary: 'Create a user',
            group: 'Users',
            parameters: [
                new Parameter(
                    name: 'email',
                    in: ParameterLocation::Body,
                    schema: Schema::string('email'),
                    required: true,
                ),
                new Parameter(
                    name: 'role',
                    in: ParameterLocation::Body,
                    schema: Schema::enum(['admin', 'member']),
                ),
            ],
            responses: [new Response(201, 'Created.')],
            authenticated: true,
        );

    $show = Endpoint::make(HttpMethod::Get, 'api/users/{user}', 'users.show')
        ->with(
            summary: 'Show a user',
            group: 'Users',
            parameters: [
                new Parameter(
                    name: 'user',
                    in: ParameterLocation::Path,
                    schema: Schema::integer(),
                    // Deliberately false: OpenAPI has no optional path
                    // parameter, so the emitter must force this true.
                    required: false,
                    description: 'The user id.',
                ),
            ],
        );

    return new ApiSpec(
        title: 'Test API',
        version: '2.1.0',
        groups: [new Group('Users', [$index, $store, $show], 'Create and read user accounts.')],
        description: 'Fixture API used by the test suite.',
        baseUrl: 'https://api.test',
    );
}

/**
 * The same API serving two versions at once.
 *
 * Built by hand like `fixtureSpec()`, and deliberately the awkward case: both
 * versions expose "List users" with the same summary, so anything that fails
 * to name the version produces two identical titles, two identical meta
 * descriptions and two search results nobody can tell apart.
 */
function versionedFixtureSpec(): ApiSpec
{
    $v2 = Endpoint::make(HttpMethod::Get, 'api/v2/users', 'v2.users.index')
        ->with(summary: 'List users', group: 'Users', version: 'v2');

    $v1 = Endpoint::make(HttpMethod::Get, 'api/v1/users', 'v1.users.index')
        ->with(
            summary: 'List users',
            group: 'Users',
            version: 'v1',
            deprecated: true,
            supersededBy: 'v2.users.index',
        );

    $gone = Endpoint::make(HttpMethod::Get, 'api/v1/exports', 'v1.exports.index')
        ->with(summary: 'List exports', group: 'Exports', version: 'v1', deprecated: true);

    return new ApiSpec(
        title: 'Test API',
        version: '2.1.0',
        groups: [
            new Group('Users', [$v2], version: 'v2'),
            new Group('Exports', [$gone], version: 'v1'),
            new Group('Users', [$v1], version: 'v1'),
        ],
        description: 'Fixture API used by the test suite.',
        baseUrl: 'https://api.test',
        versions: [
            new ApiVersion('v2', current: true),
            new ApiVersion('v1', deprecated: true, sunset: '2026-06-01'),
        ],
    );
}
