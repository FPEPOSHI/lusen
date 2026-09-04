<?php

declare(strict_types=1);

namespace Lusen\Attributes;

use Attribute;

/**
 * Declares one parameter that inference cannot reach - a query string filter,
 * a custom header, or a body field validated somewhere other than a
 * FormRequest.
 *
 *     #[ApiParam('per_page', in: 'query', type: 'integer', example: 25)]
 *     #[ApiParam('X-Account', in: 'header', required: true)]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class ApiParam
{
    /**
     * @param  string  $in  one of path, query, header or body; anything else
     *                      falls back to query
     * @param  list<string|int|float|bool>  $enum
     */
    public function __construct(
        public string $name,
        public string $in = 'query',
        public string $type = 'string',
        public ?string $description = null,
        public bool $required = false,
        public mixed $example = null,
        public array $enum = [],
        public ?string $format = null,
        public bool $nullable = false,
    ) {}
}
