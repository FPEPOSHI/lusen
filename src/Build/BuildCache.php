<?php

declare(strict_types=1);

namespace Lusen\Build;

use JsonException;
use Lusen\Collect\RouteCandidate;
use Lusen\Ir\Endpoint;

/**
 * Skips re-analysing endpoints whose inputs have not changed.
 *
 * Extraction is where a build spends its time: parsing controllers, form
 * requests, resources, models and migrations. Emission is cheap next to it.
 * On a large API, editing one controller should cost one controller's worth of
 * work, not the whole application's.
 *
 * An endpoint is reused when two things still hold: the route looks the same,
 * and every file the previous run read while documenting it has the same
 * contents. Content hashes rather than mtimes, because a fresh checkout, a
 * `git switch` and a CI runner all produce new mtimes for unchanged files -
 * which would make the cache useless exactly where builds are most repetitive.
 */
final class BuildCache
{
    private const FORMAT = 2;

    /**
     * @var array<string, array{fingerprint: string, endpoint: array<string, mixed>}>
     */
    private array $entries = [];

    /**
     * @var array<string, string>
     */
    private array $hashes = [];

    private int $hits = 0;

    private int $misses = 0;

    private bool $loaded = false;

    /**
     * @param  string  $key  invalidates everything when it changes: config,
     *                       the extractor list, the package's own version
     */
    public function __construct(
        private readonly string $path,
        private readonly string $key,
        private readonly bool $enabled = true,
    ) {}

    /**
     * The cached endpoint, if the route and every file behind it are unchanged.
     */
    public function reuse(RouteCandidate $candidate, string $id): ?Endpoint
    {
        if (! $this->enabled) {
            return null;
        }

        $this->load();

        $entry = $this->entries[$id] ?? null;

        if ($entry === null) {
            $this->misses++;

            return null;
        }

        $endpoint = Endpoint::fromArray($entry['endpoint']);

        if ($this->fingerprint($candidate, $endpoint->sourceFiles) !== $entry['fingerprint']) {
            $this->misses++;

            return null;
        }

        $this->hits++;

        return $endpoint;
    }

    /**
     * @param  list<string>  $sourceFiles  every file read while documenting it
     */
    public function remember(RouteCandidate $candidate, Endpoint $endpoint, array $sourceFiles): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->entries[$endpoint->id] = [
            'fingerprint' => $this->fingerprint($candidate, $sourceFiles),
            'endpoint' => $endpoint->toArray(),
        ];
    }

    /**
     * Entries for endpoints that no longer exist are dropped, so a cache never
     * grows without bound as routes come and go.
     *
     * @param  list<string>  $keep  ids present in this build
     */
    public function save(array $keep): void
    {
        if (! $this->enabled) {
            return;
        }

        $entries = array_intersect_key($this->entries, array_flip($keep));

        $payload = json_encode([
            'format' => self::FORMAT,
            'key' => $this->key,
            'entries' => $entries,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            return;
        }

        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            return;
        }

        // A half-written cache is worse than none, so the file appears whole.
        $temporary = $this->path.'.'.getmypid().'.tmp';

        if (file_put_contents($temporary, $payload) !== false) {
            rename($temporary, $this->path);
        }
    }

    /**
     * @return array{hits: int, misses: int}
     */
    public function stats(): array
    {
        return ['hits' => $this->hits, 'misses' => $this->misses];
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * The route as the documentation sees it. A renamed route or a changed
     * middleware stack alters the docs without touching a single file, so the
     * route has to be part of the fingerprint.
     *
     * @param  list<string>  $sourceFiles
     */
    private function fingerprint(RouteCandidate $candidate, array $sourceFiles): string
    {
        $parts = [
            $candidate->method->value,
            $candidate->uri,
            $candidate->name ?? '',
            $candidate->controller ?? '',
            $candidate->action ?? '',
            implode(',', $candidate->middleware()),
        ];

        $files = $sourceFiles;
        sort($files);

        foreach ($files as $file) {
            $parts[] = $file.':'.$this->hashFile($file);
        }

        return hash('xxh128', implode('|', $parts));
    }

    private function hashFile(string $file): string
    {
        if (isset($this->hashes[$file])) {
            return $this->hashes[$file];
        }

        $hash = is_file($file) ? hash_file('xxh128', $file) : 'missing';

        return $this->hashes[$file] = $hash === false ? 'unreadable' : $hash;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        if (! is_file($this->path)) {
            return;
        }

        $contents = file_get_contents($this->path);

        if ($contents === false) {
            return;
        }

        try {
            $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // A corrupt cache is a slow build, never a failed one.
            return;
        }

        if (! is_array($decoded)) {
            return;
        }

        // A different config, extractor list or cache format means the stored
        // endpoints were produced by rules that no longer apply.
        if (($decoded['format'] ?? null) !== self::FORMAT || ($decoded['key'] ?? null) !== $this->key) {
            return;
        }

        /** @var array<string, array{fingerprint: string, endpoint: array<string, mixed>}> $entries */
        $entries = is_array($decoded['entries'] ?? null) ? $decoded['entries'] : [];

        $this->entries = $entries;
    }
}
