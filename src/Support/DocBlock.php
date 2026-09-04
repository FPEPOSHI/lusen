<?php

declare(strict_types=1);

namespace Lusen\Support;

/**
 * A PHPDoc comment, split into the parts documentation cares about.
 *
 * Docblocks are the description most codebases already have. Reading them
 * means a team that comments its controllers gets prose in its API reference
 * without writing anything twice.
 */
final readonly class DocBlock
{
    /**
     * @param  array<string, list<string>>  $tags  tag name => values, in order
     */
    private function __construct(
        public string $summary,
        public string $description,
        public array $tags,
    ) {}

    public static function empty(): self
    {
        return new self('', '', []);
    }

    public static function parse(string|false|null $comment): self
    {
        if (! is_string($comment) || $comment === '') {
            return self::empty();
        }

        $lines = self::stripMarkers($comment);

        $body = [];
        $tags = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^@([\w-]+)\s*(.*)$/', trim($line), $match) === 1) {
                $current = strtolower($match[1]);
                $tags[$current][] = trim($match[2]);

                continue;
            }

            // A wrapped tag value continues onto the next line.
            if ($current !== null) {
                $trimmed = trim($line);

                if ($trimmed !== '') {
                    $index = count($tags[$current]) - 1;
                    $tags[$current][$index] = trim($tags[$current][$index].' '.$trimmed);
                }

                continue;
            }

            $body[] = $line;
        }

        [$summary, $description] = self::split($body);

        return new self($summary, $description, $tags);
    }

    public function isEmpty(): bool
    {
        return $this->summary === '' && $this->description === '' && $this->tags === [];
    }

    public function hasTag(string $name): bool
    {
        return isset($this->tags[strtolower($name)]);
    }

    public function tag(string $name): ?string
    {
        $values = $this->tags[strtolower($name)] ?? [];

        return $values[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function tagValues(string $name): array
    {
        return $this->tags[strtolower($name)] ?? [];
    }

    /**
     * @return list<string>
     */
    private static function stripMarkers(string $comment): array
    {
        $comment = preg_replace('#^/\*\*+#', '', $comment) ?? $comment;
        $comment = preg_replace('#\*+/$#', '', $comment) ?? $comment;

        $lines = [];

        foreach (preg_split('/\r?\n/', $comment) ?: [] as $line) {
            $lines[] = rtrim(preg_replace('/^\s*\*\s?/', '', $line) ?? $line);
        }

        // Leading and trailing blank lines are an artefact of the comment
        // syntax, not part of the text.
        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
        }

        while ($lines !== [] && trim($lines[count($lines) - 1]) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * PHPDoc convention: the first paragraph is the summary, the rest is the
     * description.
     *
     * @param  list<string>  $body
     * @return array{0: string, 1: string}
     */
    private static function split(array $body): array
    {
        $paragraphs = [];
        $current = [];

        foreach ($body as $line) {
            if (trim($line) === '') {
                if ($current !== []) {
                    $paragraphs[] = implode(' ', $current);
                    $current = [];
                }

                continue;
            }

            $current[] = trim($line);
        }

        if ($current !== []) {
            $paragraphs[] = implode(' ', $current);
        }

        if ($paragraphs === []) {
            return ['', ''];
        }

        $summary = array_shift($paragraphs);

        // A heading does not carry a full stop, and the summary becomes one -
        // in the page title, the sidebar and the <title> tag.
        if (str_ends_with($summary, '.') && ! str_ends_with($summary, '..')) {
            $summary = substr($summary, 0, -1);
        }

        return [$summary, implode("\n\n", $paragraphs)];
    }
}
