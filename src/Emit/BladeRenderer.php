<?php

declare(strict_types=1);

namespace Lusen\Emit;

use Illuminate\Contracts\View\Factory;
use Lusen\Emit\Contracts\Renderer;

/**
 * The production Renderer: Laravel's own view factory.
 */
final readonly class BladeRenderer implements Renderer
{
    public function __construct(private Factory $views) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, array $data): string
    {
        return $this->views->make($view, $data)->render();
    }
}
