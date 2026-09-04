<?php

declare(strict_types=1);

namespace Lusen\Support;

use Lusen\Emit\EmittedFile;
use RuntimeException;

/**
 * Writes emitted files to disk.
 *
 * Native PHP rather than Illuminate\Filesystem so the build path carries no
 * dependency the package does not already need, and so the MCP server and
 * tests can write without a container.
 *
 * Skips writes whose contents already match. Since the IR serialises
 * deterministically, a rebuild that changed nothing touches no mtimes - which
 * keeps `sitemap.xml` lastmod honest and CDN caches warm.
 */
final readonly class Writer
{
    public function __construct(private string $root) {}

    /**
     * @param  list<EmittedFile>  $files
     * @return array{written: int, skipped: int, bytes: int}
     */
    public function writeAll(array $files): array
    {
        $written = 0;
        $skipped = 0;
        $bytes = 0;

        foreach ($files as $file) {
            if ($this->write($file)) {
                $written++;
            } else {
                $skipped++;
            }

            $bytes += $file->bytes();
        }

        return ['written' => $written, 'skipped' => $skipped, 'bytes' => $bytes];
    }

    /**
     * @return bool true when the file was actually written
     */
    public function write(EmittedFile $file): bool
    {
        $path = $this->resolve($file->path);

        if (is_file($path) && file_get_contents($path) === $file->contents) {
            return false;
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create directory [{$directory}].");
        }

        if (file_put_contents($path, $file->contents) === false) {
            throw new RuntimeException("Unable to write [{$path}].");
        }

        return true;
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Refuses to escape the output root - emitters build paths from route
     * URIs, and a route can contain anything.
     */
    private function resolve(string $relative): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        if (str_contains($relative, '..')) {
            throw new RuntimeException("Refusing to write outside the output directory: [{$relative}].");
        }

        return rtrim($this->root, '/').'/'.$relative;
    }
}
