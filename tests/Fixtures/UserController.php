<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures;

use Lusen\Attributes\ApiDoc;
use Lusen\Attributes\ApiGroup;
use Lusen\Attributes\ApiParam;
use Lusen\Attributes\ApiResponse;
use Lusen\Attributes\Authenticated;
use Lusen\Attributes\Hidden;

#[ApiGroup('Users', 'Create and read user accounts.')]
final class UserController
{
    #[ApiDoc(summary: 'List users', description: 'A paginated list of every user.')]
    #[ApiParam('per_page', in: 'query', type: 'integer', description: 'Results per page.', example: 25)]
    #[ApiParam('status', in: 'query', description: 'Filter by account state.', enum: ['active', 'invited'])]
    #[ApiResponse(200, 'A page of users.', example: ['data' => [['id' => 1, 'name' => 'Ada']]])]
    public function index(): void {}

    #[ApiDoc(summary: 'Show a user')]
    #[Authenticated]
    #[ApiResponse(200, 'The user.')]
    #[ApiResponse(404, 'No user with that id.')]
    public function show(): void {}

    #[ApiDoc(summary: 'Throttled endpoint')]
    #[ApiResponse(429, 'Too many requests.')]
    public function throttled(): void {}

    #[Hidden]
    public function internalMetrics(): void {}
}
