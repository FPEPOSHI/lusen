<?php

declare(strict_types=1);

use Lusen\Emit\SchemaComponents;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\ApiVersion;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\HttpMethod;
use Lusen\Ir\Group;
use Lusen\Ir\Response;
use Lusen\Ir\Schema;

function customer(string $title = 'Customer', bool $withPhone = false): Schema
{
    $properties = ['id' => Schema::integer(), 'email' => Schema::string('email')];

    if ($withPhone) {
        $properties['phone'] = Schema::string();
    }

    return Schema::object($properties)->titled($title);
}

/**
 * @param  list<Endpoint>  $endpoints
 */
function componentSpec(array $endpoints, bool $versioned = false): ApiSpec
{
    return new ApiSpec(
        title: 'Test API',
        groups: [new Group('Customers', $endpoints)],
        versions: $versioned ? [new ApiVersion('v2', current: true), new ApiVersion('v1')] : [],
    );
}

function returning(string $id, Schema $schema, ?string $version = null): Endpoint
{
    return Endpoint::make(HttpMethod::Get, 'api/customers', $id)
        ->with(responses: [new Response(200, schema: $schema)], version: $version);
}

it('names a shape once, however many endpoints return it', function (): void {
    $spec = componentSpec([
        returning('a', Schema::object(['data' => customer()])),
        returning('b', Schema::object(['data' => Schema::arrayOf(customer())])),
    ]);

    $components = SchemaComponents::of($spec);

    expect(array_keys($components->all()))->toBe(['Customer'])
        ->and($components->nameFor(customer()))->toBe('Customer');
});

it('finds a shape nested inside another', function (): void {
    $order = Schema::object(['id' => Schema::integer(), 'customer' => customer()])->titled('Order');

    $components = SchemaComponents::of(componentSpec([returning('a', $order)]));

    expect(array_keys($components->all()))->toBe(['Customer', 'Order']);
});

it('says nothing about a shape with no name', function (): void {
    $components = SchemaComponents::of(componentSpec([
        returning('a', Schema::object(['id' => Schema::integer()])),
    ]));

    expect($components->all())->toBe([]);
});

it('qualifies by version when two versions disagree about a shape', function (): void {
    // The normal case for a versioned API: both versions have a
    // CustomerResource and they are not the same shape any more.
    $spec = componentSpec([
        returning('v2.show', customer(withPhone: true), version: 'v2'),
        returning('v1.show', customer(), version: 'v1'),
    ], versioned: true);

    $components = SchemaComponents::of($spec);

    expect(array_keys($components->all()))->toBe(['V1Customer', 'V2Customer'])
        ->and($components->nameFor(customer()))->toBe('V1Customer')
        ->and($components->nameFor(customer(withPhone: true)))->toBe('V2Customer');
});

it('hoists nothing it cannot name honestly', function (): void {
    // Two shapes claiming one title, both used by the same version: there is
    // no name that is true of both, and merging them would hand a generated
    // client a type that does not match half the responses it is used for.
    $spec = componentSpec([
        returning('a', customer(), version: 'v1'),
        returning('b', customer(withPhone: true), version: 'v1'),
    ], versioned: true);

    expect(SchemaComponents::of($spec)->all())->toBe([]);
});

it('treats one shape used by two versions as one component', function (): void {
    $spec = componentSpec([
        returning('v2.show', customer(), version: 'v2'),
        returning('v1.show', customer(), version: 'v1'),
    ], versioned: true);

    expect(array_keys(SchemaComponents::of($spec)->all()))->toBe(['Customer']);
});

it('treats two readings of one self-referencing shape as one type', function (): void {
    // A user whose posts have an author who is a user: the reading stops at a
    // fixed depth, and where it stops depends on where it started. Same
    // fields, different amount of detail underneath - one type, read twice.
    $shallow = Schema::object([
        'id' => Schema::integer(),
        'posts' => Schema::arrayOf(Schema::object(['id' => Schema::integer()])->titled('Post')),
    ])->titled('User');

    $deep = Schema::object([
        'id' => Schema::integer(),
        'posts' => Schema::arrayOf(Schema::object([
            'id' => Schema::integer(),
            // What the reader leaves behind when it stops: an untyped stub,
            // which carries no name and so claims nothing.
            'author' => Schema::any(),
        ])->titled('Post')),
    ])->titled('User');

    $components = SchemaComponents::of(componentSpec([
        returning('a', $deep),
        returning('b', $shallow),
    ]));

    // The fuller reading wins, and both point at it: the nested author really
    // does have those fields, so referencing them says something truer than
    // writing out the shorter copy.
    expect($components->nameFor($deep))->toBe('User')
        ->and($components->nameFor($shallow))->toBe('User')
        ->and($components->all()['User']->properties['posts']->items?->properties)->toHaveKey('author');
});
