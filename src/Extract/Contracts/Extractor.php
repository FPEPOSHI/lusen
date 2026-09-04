<?php

declare(strict_types=1);

namespace Lusen\Extract\Contracts;

use Lusen\Collect\RouteCandidate;
use Lusen\Ir\Endpoint;

/**
 * One stage of the extraction pipeline.
 *
 * Stages receive the endpoint built so far plus the route it came from, and
 * return an enriched copy. Returning null drops the endpoint from the docs
 * entirely - that is how #[Hidden] and friends work.
 *
 * Implementations must be side-effect free and must not boot the host app,
 * dispatch a request, or touch a database: a docs build has to succeed in CI
 * against a checkout with no .env.
 */
interface Extractor
{
    public function extract(Endpoint $endpoint, RouteCandidate $candidate): ?Endpoint;
}
