<?php

declare(strict_types=1);

namespace Lusen\Emit;

/**
 * One file an emitter produced, not yet written anywhere.
 *
 * Emitters return these instead of touching the filesystem: it keeps them
 * pure, makes every one of them snapshot-testable without a temp directory,
 * and lets the build command write, diff or dry-run the whole set at once.
 */
final readonly class EmittedFile
{
    public function __construct(
        public string $path,
        public string $contents,
        public string $contentType = 'text/plain',
    ) {}

    public static function json(string $path, mixed $data): self
    {
        return new self(
            path: $path,
            contents: json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
            contentType: 'application/json',
        );
    }

    public static function html(string $path, string $contents): self
    {
        return new self($path, $contents, 'text/html; charset=utf-8');
    }

    public static function markdown(string $path, string $contents): self
    {
        return new self($path, $contents, 'text/markdown; charset=utf-8');
    }

    public static function xml(string $path, string $contents): self
    {
        return new self($path, $contents, 'application/xml');
    }

    public function bytes(): int
    {
        return strlen($this->contents);
    }
}
