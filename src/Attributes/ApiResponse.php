<?php

declare(strict_types=1);

namespace Lusen\Attributes;

use Attribute;

/**
 * Declares a response. Repeat it per status code.
 *
 * An example is worth more than a schema here: a complete, realistic body is
 * what makes a page usable by a reader skimming and by an agent constructing
 * a call, so prefer passing `example` even when the shape is inferable.
 *
 *     #[ApiResponse(200, example: ['data' => [['id' => 1, 'name' => 'Ada']]])]
 *     #[ApiResponse(404, 'No user with that id.')]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class ApiResponse
{
    /**
     * @param  string|null  $type  the body's shape, in the same grammar as
     *                             `@response`: `array{id: int}`, `list<Order>`,
     *                             `?ApiError`, or the name of a class to read
     */
    public function __construct(
        public int $status = 200,
        public ?string $description = null,
        public mixed $example = null,
        public string $contentType = 'application/json',
        public ?string $type = null,
    ) {}
}
