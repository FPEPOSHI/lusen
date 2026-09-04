<?php

declare(strict_types=1);

namespace Lusen\Emit\Contracts;

/**
 * Renders a named template to a string.
 *
 * HtmlEmitter needs Blade so that static output and the runtime renderer go
 * through the same views and cannot drift. Depending on this interface rather
 * than the view factory keeps the emitter free of the container: it is handed
 * a collaborator, it does not go looking for one, and a test can pass a fake.
 */
interface Renderer
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, array $data): string;
}
