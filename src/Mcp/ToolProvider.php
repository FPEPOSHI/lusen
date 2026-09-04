<?php

declare(strict_types=1);

namespace Lusen\Mcp;

/**
 * Supplies the tools a Server exposes.
 */
interface ToolProvider
{
    /**
     * @return list<array<string, mixed>>
     */
    public function definitions(): array;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function call(string $name, array $arguments): string;
}
