<?php

declare(strict_types=1);

namespace Lusen\Ir;

use Lusen\Support\Data;
use Lusen\Support\Str;

/**
 * One authored prose page - an introduction, a use-case walkthrough, an
 * authentication guide.
 *
 * Endpoint reference answers "how do I call this". Pages answer "what is this
 * for, and why would I", which is the question a search engine or a model is
 * usually asked first. Documentation that only lists operations cannot be
 * found by anyone who does not already know the operation's name.
 *
 * Content stays as Markdown in the IR rather than rendered HTML: the IR must
 * serialise deterministically and stay renderer-agnostic, and the Markdown
 * mirror wants the source anyway.
 */
final readonly class Page
{
    public function __construct(
        public string $id,
        public string $title,
        public string $markdown = '',
        public ?string $section = null,
        public ?string $description = null,
        public int $order = 0,
        public ?string $sourceFile = null,
    ) {}

    public static function make(string $title, string $markdown, ?string $section = null): self
    {
        return new self(
            id: Str::slug($title),
            title: $title,
            markdown: $markdown,
            section: $section,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Data::string($data, 'id'),
            title: Data::string($data, 'title'),
            markdown: Data::string($data, 'markdown'),
            section: Data::nullableString($data, 'section'),
            description: Data::nullableString($data, 'description'),
            order: Data::int($data, 'order'),
            sourceFile: Data::nullableString($data, 'sourceFile'),
        );
    }

    public function slug(): string
    {
        return Str::slug($this->id);
    }

    /**
     * First paragraph of the body, for a meta description and the llms.txt
     * entry, when the author supplied no explicit one.
     */
    public function summary(): string
    {
        if ($this->description !== null && $this->description !== '') {
            return $this->description;
        }

        foreach (explode("\n\n", $this->markdown) as $block) {
            $block = trim($block);

            // Skip headings, code fences and anything that is not prose.
            if ($block === '' || str_starts_with($block, '#') || str_starts_with($block, '```')
                || str_starts_with($block, '|') || str_starts_with($block, '-')) {
                continue;
            }

            return Str::summarise($block);
        }

        return Str::summarise($this->title);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'title' => $this->title,
            'section' => $this->section,
            'description' => $this->description,
            'order' => $this->order ?: null,
            'sourceFile' => $this->sourceFile,
            'markdown' => $this->markdown,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
