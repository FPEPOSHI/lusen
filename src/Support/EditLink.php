<?php

declare(strict_types=1);

namespace Lusen\Support;

use Lusen\Ir\Page;

/**
 * Where a reader can go to fix the page they are reading.
 *
 * The cheapest thing that keeps prose honest. A reader who spots a wrong
 * sentence will fix it if the fix is one link away, and will not if it means
 * finding the repository, then the directory, then the file.
 *
 * Only authored pages have one. A generated page - the introduction Lusen
 * derives when nobody wrote one - has no file behind it, and pointing at a
 * file that does not exist is worse than pointing at nothing.
 */
final class EditLink
{
    /**
     * @param  string|null  $template  a URL with `{path}` where the file's
     *                                 project-relative path belongs
     */
    public static function for(?Page $page, ?string $template): ?string
    {
        if ($page?->sourceFile === null || $template === null || $template === '') {
            return null;
        }

        return str_replace('{path}', ltrim($page->sourceFile, '/'), $template);
    }
}
