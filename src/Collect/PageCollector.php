<?php

declare(strict_types=1);

namespace Lusen\Collect;

use Lusen\Ir\Page;
use Lusen\Support\Str;

/**
 * Reads authored prose pages from Markdown files in the host application.
 *
 * Markdown files on disk rather than a config array or a database, because
 * documentation prose is written, reviewed and versioned like code. Putting it
 * in the repository means it moves with the API it describes.
 */
final readonly class PageCollector
{
    public function __construct(private string $path) {}

    /**
     * @return list<Page>
     */
    public function collect(): array
    {
        if (! is_dir($this->path)) {
            return [];
        }

        $pages = [];

        foreach ($this->files() as $file) {
            $page = $this->read($file);

            if ($page !== null) {
                $pages[] = $page;
            }
        }

        // Explicit order first, then title, so a directory listing's arbitrary
        // order never leaks into the sidebar.
        usort($pages, static fn (Page $a, Page $b): int => [$a->order, $a->title] <=> [$b->order, $b->title]);

        return $pages;
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $found = glob(rtrim($this->path, '/').'/*.md') ?: [];

        // One level of nesting, where the directory name is the default
        // section - resources/docs/guides/webhooks.md lands under "Guides".
        foreach (glob(rtrim($this->path, '/').'/*/*.md') ?: [] as $nested) {
            $found[] = $nested;
        }

        sort($found);

        return $found;
    }

    private function read(string $file): ?Page
    {
        $contents = file_get_contents($file);

        if ($contents === false) {
            return null;
        }

        [$matter, $body] = $this->splitFrontMatter($contents);

        $name = basename($file, '.md');
        $parent = basename(dirname($file));
        $isNested = rtrim(dirname($file), '/') !== rtrim($this->path, '/');

        $title = $matter['title'] ?? $this->titleFromBody($body) ?? Str::title($name);

        return new Page(
            id: $matter['id'] ?? Str::slug($name),
            title: $title,
            markdown: $this->stripLeadingH1($body, $title),
            section: $matter['section'] ?? ($isNested ? Str::title($parent) : null),
            description: $matter['description'] ?? null,
            order: isset($matter['order']) ? (int) $matter['order'] : 0,
            sourceFile: $file,
        );
    }

    /**
     * A deliberately small front-matter reader: `key: value` pairs only.
     *
     * Pulling in a YAML parser to read four scalar keys would be a dependency
     * for nothing. Anything more structured belongs in the body.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private function splitFrontMatter(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        if (! str_starts_with($contents, "---\n") && ! str_starts_with($contents, "---\r\n")) {
            return [[], $contents];
        }

        $parts = preg_split('/^---\s*$/m', $contents, 3);

        if ($parts === false || count($parts) < 3) {
            return [[], $contents];
        }

        $matter = [];

        foreach (preg_split('/\r?\n/', trim($parts[1])) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);

            $matter[strtolower(trim($key))] = trim(trim($value), " \t\"'");
        }

        return [$matter, ltrim($parts[2], "\r\n")];
    }

    private function titleFromBody(string $body): ?string
    {
        if (preg_match('/^#\s+(.+)$/m', $body, $match) === 1) {
            return trim($match[1]);
        }

        return null;
    }

    /**
     * The layout renders the title as the page's <h1>, so a leading h1 in the
     * body would show it twice.
     */
    private function stripLeadingH1(string $body, string $title): string
    {
        $trimmed = ltrim($body);

        if (preg_match('/^#\s+(.+?)(\r?\n|$)/', $trimmed, $match) !== 1) {
            return $body;
        }

        if (trim($match[1]) !== $title) {
            return $body;
        }

        return ltrim(substr($trimmed, strlen($match[0])), "\r\n");
    }
}
