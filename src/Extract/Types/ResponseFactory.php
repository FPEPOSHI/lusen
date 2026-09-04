<?php

declare(strict_types=1);

namespace Lusen\Extract\Types;

use Lusen\Ir\Example;
use Lusen\Ir\Response;
use Lusen\Support\Examples;

/**
 * One way to turn a declared response into a documented one.
 *
 * Two attributes describe a response body - Lusen's own `#[ApiResponse]` and
 * whatever the codebase used before it - and they should produce identical
 * documentation from identical input. Reading them through one decoder is what
 * guarantees that, rather than two implementations that drift the first time
 * either is touched.
 */
final readonly class ResponseFactory
{
    public function __construct(private TypeReader $types = new TypeReader) {}

    /**
     * @param  string|null  $type  the body's shape, in the `@response` grammar
     * @param  mixed  $example  the author's own example, which beats a generated one
     */
    public function make(
        int $status,
        ?string $description,
        ?string $type,
        mixed $example,
        TypeNames $names,
        string $contentType = 'application/json',
    ): Response {
        $schema = $type === null || $type === '' ? null : $this->types->read($type, $names);

        // A documented body should always have something to copy, so a shape
        // with no written example still gets one.
        $value = match (true) {
            $example !== null => $example,
            $schema !== null => Examples::forSchema($schema),
            default => null,
        };

        return new Response(
            status: $status,
            description: $description,
            schema: $schema,
            examples: $value === null
                ? []
                : [new Example(label: 'Example', value: $value, contentType: $contentType)],
            contentType: $contentType,
        );
    }
}
