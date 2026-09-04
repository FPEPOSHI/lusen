<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures;

use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Lusen\Attributes\ApiDoc;
use Lusen\Attributes\ApiResponse;

/**
 * A controller documented with Scramble's attributes.
 *
 * Scramble is deliberately NOT a dependency of this package, so none of these
 * attribute classes exist. That is the point: reflection reads an attribute's
 * name and arguments without loading it, and the extractor has to work in a
 * codebase that has already removed the tool it is migrating from.
 */
#[Group(name: 'Klienti', description: 'Clients, in the team\'s own words.')]
final class ScrambledController
{
    /**
     * List clients
     */
    #[QueryParameter('limit', description: 'How many per page.', type: 'int', default: 20, example: 20)]
    #[QueryParameter('cursor', description: 'Where to resume from.', type: 'string')]
    // Absolute, because a formatter's unused-import rule cannot see a class
    // named only inside an attribute's string argument and will strip it.
    #[Response(status: 200, description: 'A page of clients.', type: 'array{status: true, data: \Lusen\Tests\Fixtures\Schema\MoneyShape}')]
    #[Response(status: 422, description: 'The dates were unusable.')]
    public function index(): void {}

    /**
     * Show a client
     */
    #[PathParameter('id', description: 'The client id.', type: 'int', example: 42)]
    public function show(): void {}

    /**
     * Ours wins
     */
    #[ApiDoc(group: 'Lusen has the last word')]
    #[ApiResponse(404, 'Gone.', type: '\Lusen\Tests\Fixtures\Schema\MoneyShape')]
    #[ApiResponse(200, 'Ours.', type: 'array{ok: true, items: list<string>}')]
    public function ours(): void {}
}
