<?php

declare(strict_types=1);

namespace Lusen\Extract;

use Lusen\Build\BuildCache;
use Lusen\Collect\RouteCandidate;
use Lusen\Extract\Contracts\Extractor;
use Lusen\Ir\Endpoint;
use Lusen\Support\Ast;

/**
 * Runs the configured extractors over each candidate, in order.
 */
final readonly class ExtractionPipeline
{
    /**
     * @param  list<Extractor>  $extractors
     */
    public function __construct(
        private array $extractors = [],
        private ?BuildCache $cache = null,
    ) {}

    /**
     * @param  list<RouteCandidate>  $candidates
     * @return list<Endpoint>
     */
    public function run(array $candidates): array
    {
        $endpoints = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            $endpoint = $this->resolve($candidate);

            if ($endpoint !== null) {
                $endpoints[] = $endpoint;
                $seen[] = $endpoint->id;
            }
        }

        $this->cache?->save($seen);

        return $endpoints;
    }

    public function runOne(RouteCandidate $candidate): ?Endpoint
    {
        return $this->extract($candidate);
    }

    private function resolve(RouteCandidate $candidate): ?Endpoint
    {
        $id = Endpoint::deriveId($candidate->method, $candidate->uri, $candidate->name);

        $cached = $this->cache?->reuse($candidate, $id);

        if ($cached !== null) {
            return $cached;
        }

        // Recording spans the whole pipeline rather than one extractor, so an
        // endpoint's dependencies include everything any of them read - a
        // nested resource, the model behind it, its migrations.
        Ast::beginRecording();

        $endpoint = $this->extract($candidate);

        $read = Ast::endRecording();

        if ($endpoint === null) {
            return null;
        }

        $sourceFiles = array_values(array_unique([...$endpoint->sourceFiles, ...$read]));

        $endpoint = $endpoint->with(sourceFiles: $sourceFiles);

        $this->cache?->remember($candidate, $endpoint, $sourceFiles);

        return $endpoint;
    }

    private function extract(RouteCandidate $candidate): ?Endpoint
    {
        $endpoint = Endpoint::make(
            method: $candidate->method,
            uri: $candidate->uri,
            routeName: $candidate->name,
        );

        foreach ($this->extractors as $extractor) {
            $endpoint = $extractor->extract($endpoint, $candidate);

            if ($endpoint === null) {
                return null;
            }
        }

        return $endpoint;
    }
}
