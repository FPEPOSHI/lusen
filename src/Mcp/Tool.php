<?php

declare(strict_types=1);

namespace Lusen\Mcp;

use Closure;

/**
 * One callable tool exposed over MCP.
 */
final readonly class Tool
{
    /**
     * @param  array<string, mixed>  $inputSchema  JSON Schema for the arguments
     * @param  Closure(array<string, mixed>): string  $handler  returns Markdown
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public Closure $handler,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function call(array $arguments): string
    {
        return ($this->handler)($arguments);
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
        ];
    }
}
