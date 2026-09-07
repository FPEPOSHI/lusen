<?php

declare(strict_types=1);

namespace Lusen\Record;

/**
 * The file of captured responses, as something a build can read.
 *
 * Generated examples satisfy the schema and nothing else: they say
 * `"status": "string"` where the API says `"paid"`, and a reader copying one
 * into a client learns the shape without ever seeing the thing. A real
 * response is the single biggest improvement available to any page here, and
 * the application's own test suite is already producing them.
 *
 * It stays out of the build's way. Recording happens during the host's tests,
 * which have booted the app because that is what tests do; the build only ever
 * reads a committed JSON file, so invariant 3 holds - `lusen:build` still runs
 * against a checkout with no .env and no database.
 *
 * **First recording wins.** Fixtures are usually random, so re-recording every
 * run would rewrite the file with fresh names on every CI run and turn a
 * committed artefact into permanent diff noise. Adding an endpoint records the
 * one that was missing and leaves the rest alone; `--fresh` is how you ask for
 * all of them again.
 */
final class Recordings
{
    /**
     * @param  array<string, Recording>  $recordings  keyed by method, uri and status
     */
    private function __construct(private array $recordings = []) {}

    public static function empty(): self
    {
        return new self;
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return new self;
        }

        $recordings = [];

        foreach ($decoded as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            /** @var array<string, mixed> $entry */
            $recording = Recording::fromArray($entry);

            if ($recording->uri !== '') {
                $recordings[$recording->key()] = $recording;
            }
        }

        return new self($recordings);
    }

    public static function read(string $path): self
    {
        if (! is_file($path)) {
            return new self;
        }

        $contents = file_get_contents($path);

        return $contents === false ? new self : self::fromJson($contents);
    }

    /**
     * Keeps what is already there. See the note on the class.
     */
    public function with(Recording $recording): self
    {
        if (isset($this->recordings[$recording->key()])) {
            return $this;
        }

        return new self([...$this->recordings, $recording->key() => $recording]);
    }

    public function for(string $method, string $uri, int $status): ?Recording
    {
        return $this->recordings[Recording::keyFor($method, $uri, $status)] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->recordings === [];
    }

    public function count(): int
    {
        return count($this->recordings);
    }

    /**
     * How many distinct operations are covered, which is the number worth
     * reporting: forty recordings across three endpoints is not coverage.
     */
    public function operations(): int
    {
        $seen = [];

        foreach ($this->recordings as $recording) {
            $seen[strtoupper($recording->method).' '.$recording->uri] = true;
        }

        return count($seen);
    }

    /**
     * Sorted, pretty-printed and newline-terminated: this file is committed,
     * so it has to diff readably and it has to be byte-identical when nothing
     * was captured that was not already there.
     */
    public function toJson(): string
    {
        $recordings = $this->recordings;
        ksort($recordings);

        return (string) json_encode(
            array_values(array_map(static fn (Recording $r): array => $r->toArray(), $recordings)),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
    }
}
