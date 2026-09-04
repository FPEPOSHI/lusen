<?php

declare(strict_types=1);

namespace Lusen\Pages;

use Lusen\Ir\ApiSpec;
use Lusen\Ir\ApiVersion;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\HttpMethod;
use Lusen\Ir\Group;
use Lusen\Ir\Page;
use Lusen\Ir\Response;
use Lusen\Ir\SecurityScheme;
use Lusen\Support\Snippets;
use Lusen\Support\Versions;

/**
 * Prose pages derived from what the spec already knows.
 *
 * The zero-configuration promise has to reach the prose too: an application
 * that has written no documentation still gets an introduction that orients a
 * reader, an authentication page that matches its actual middleware, and an
 * error reference built from the statuses its endpoints really return.
 *
 * Everything here is derived, never invented. Pages that can only be written
 * by a human - use cases, tutorials - are shipped as publishable stubs
 * instead, because a plausible-sounding fabricated use case is worse than an
 * absent one.
 *
 * Any page the author has written wins; these only fill gaps.
 */
final class DefaultPages
{
    public const SECTION = 'Getting started';

    /**
     * @param  list<Page>  $authored
     * @return list<Page>
     */
    public static function fill(ApiSpec $spec, array $authored): array
    {
        $taken = array_map(static fn (Page $page): string => $page->id, $authored);

        $generated = [];

        $standard = [
            'introduction' => ['Introduction', 10],
            'versioning' => ['Versioning', 15],
            'authentication' => ['Authentication', 20],
            'errors' => ['Errors', 30],
            'rate-limiting' => ['Rate limiting', 40],
        ];

        foreach ($standard as $id => [$title, $order]) {
            if (in_array($id, $taken, true)) {
                continue;
            }

            $markdown = match ($id) {
                'introduction' => self::introduction($spec),
                'versioning' => self::versioning($spec),
                'authentication' => self::authentication($spec),
                'errors' => self::errors($spec),
                default => self::rateLimiting($spec),
            };

            if ($markdown === null) {
                continue;
            }

            $generated[] = new Page(
                id: $id,
                title: $title,
                markdown: $markdown,
                section: self::SECTION,
                order: $order,
            );
        }

        return $generated;
    }

    private static function introduction(ApiSpec $spec): string
    {
        $lines = [];

        $lines[] = $spec->description ?? "Reference documentation for the {$spec->title}.";
        $lines[] = '';
        $lines[] = '## At a glance';
        $lines[] = '';
        $lines[] = '| | |';
        $lines[] = '| --- | --- |';
        $lines[] = "| Version | `{$spec->version}` |";

        // Which versions answer today is a different question from which
        // release of the documentation this is, and a more urgent one.
        if ($spec->isVersioned()) {
            $lines[] = '| API versions | '.implode(', ', array_map(
                static fn (ApiVersion $version): string => "`{$version->name}` ({$version->status()})",
                $spec->versions,
            )).' |';
        }

        if ($spec->baseUrl !== null) {
            $lines[] = "| Base URL | `{$spec->baseUrl}` |";
        }

        foreach ($spec->servers as $label => $url) {
            $lines[] = "| {$label} | `{$url}` |";
        }

        $endpoints = count($spec->endpoints());
        $groups = count($spec->groups);

        $lines[] = "| Endpoints | {$endpoints} across {$groups} ".($groups === 1 ? 'group' : 'groups').' |';
        $lines[] = '| Format | JSON request and response bodies |';
        $lines[] = '';

        if ($spec->groups !== []) {
            $lines[] = '## What you can do';
            $lines[] = '';

            foreach ($spec->groups as $group) {
                $count = count($group->endpoints);
                $summary = $group->description ?? self::describeGroup($group);

                // The display name, not the plain one: a versioned API has a
                // Customers group per version, and a list naming both of them
                // "Customers" is a list that answers nothing.
                $lines[] = "- **{$group->displayName()}** — {$summary} ({$count} ".($count === 1 ? 'endpoint' : 'endpoints').')';
            }

            $lines[] = '';
        }

        $lines[] = '## Making a request';
        $lines[] = '';

        $first = self::exampleEndpoint($spec);

        if ($first !== null) {
            $lines[] = $first->authenticated
                ? 'Every endpoint speaks JSON. A request looks like this:'
                : 'Every endpoint speaks JSON. This one needs no credentials, so you can run it right now:';
            $lines[] = '';
            $lines[] = '```bash';
            $lines[] = Snippets::curl($first, $spec->baseUrl);
            $lines[] = '```';
            $lines[] = '';
        }

        $lines[] = '## Reading this documentation';
        $lines[] = '';
        $lines[] = 'Each endpoint has its own page listing every parameter, every response status and a request you can copy and run. ';
        $lines[] = 'Pages are also published as Markdown — swap `.html` for `.md` on any endpoint URL — and the whole API is available as an OpenAPI 3.1 document.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * What versions exist, which one to use, and what actually differs
     * between the two newest.
     *
     * "Which version should I be calling" is the first question a versioned
     * API raises, and it is asked of a search engine or an assistant far more
     * often than it is asked of the docs directly — so it deserves a page with
     * its own URL rather than a sentence on the index.
     *
     * Absent below two versions: an API with one version has no versioning to
     * explain, and a page saying so would be a page about nothing.
     */
    private static function versioning(ApiSpec $spec): ?string
    {
        if (! $spec->isVersioned()) {
            return null;
        }

        $current = $spec->currentVersion();
        $inPath = self::versionInPath($spec);

        $lines = [
            'This API serves '.count($spec->versions).' versions at once.'
                .($inPath ? ' The version is part of the path, so a request names the version it wants.' : ''),
            '',
            '## Versions',
            '',
            '| Version | Status | Endpoints |',
            '| --- | --- | --- |',
        ];

        foreach ($spec->versions as $version) {
            $note = $version->sunset === null
                ? $version->status()
                : "{$version->status()} — retires {$version->sunset}";

            $lines[] = sprintf(
                '| `%s` | %s | %d |',
                $version->name,
                $note,
                count($spec->endpointsIn($version->name)),
            );
        }

        $lines[] = '';

        if ($current !== null) {
            $lines[] = "New integrations should use `{$current->name}`.";
            $lines[] = '';
        }

        return implode("\n", [...$lines, ...self::versionDifferences($spec, $current)]);
    }

    /**
     * The current version against the one before it, from the operations each
     * actually exposes.
     *
     * Only ever two versions compared, and it says which two: a changelog
     * spanning five versions is a document a person writes, and guessing at
     * one would be exactly the invention this class exists to avoid.
     *
     * @return list<string>
     */
    private static function versionDifferences(ApiSpec $spec, ?ApiVersion $current): array
    {
        if ($current === null) {
            return [];
        }

        $previous = self::versionAfter($spec, $current);

        if ($previous === null) {
            return [];
        }

        $now = self::operations($spec, $current->name);
        $before = self::operations($spec, $previous->name);

        $added = array_diff_key($now, $before);
        $removed = array_diff_key($before, $now);
        $kept = count(array_intersect_key($now, $before));

        if ($added === [] && $removed === []) {
            return [
                "## `{$current->name}` compared with `{$previous->name}`",
                '',
                "Both versions expose the same {$kept} operations at the same paths.",
                '',
            ];
        }

        $lines = ["## `{$current->name}` compared with `{$previous->name}`", ''];

        if ($added !== []) {
            $lines[] = "New in `{$current->name}`:";
            $lines[] = '';

            foreach ($added as $endpoint) {
                $lines[] = "- `{$endpoint->method->value} {$endpoint->path()}`"
                    .($endpoint->summary === null ? '' : " — {$endpoint->summary}");
            }

            $lines[] = '';
        }

        if ($removed !== []) {
            $lines[] = "In `{$previous->name}` but not in `{$current->name}`:";
            $lines[] = '';

            foreach ($removed as $endpoint) {
                $lines[] = "- `{$endpoint->method->value} {$endpoint->path()}`"
                    .($endpoint->summary === null ? '' : " — {$endpoint->summary}");
            }

            $lines[] = '';
        }

        if ($kept > 0) {
            $lines[] = ($kept === 1
                ? 'The remaining operation exists in both versions at the same path.'
                : "The other {$kept} operations exist in both versions at the same path.")
                ." Each one's page links to its newer edition.";
            $lines[] = '';
        }

        return $lines;
    }

    /**
     * Operations in one version, keyed by what identifies them across
     * versions: the method and the path with the version taken out.
     *
     * @return array<string, Endpoint>
     */
    private static function operations(ApiSpec $spec, string $version): array
    {
        $operations = [];

        foreach ($spec->endpointsIn($version) as $endpoint) {
            $operations[$endpoint->method->value.' '.Versions::strip($endpoint->uri)] = $endpoint;
        }

        return $operations;
    }

    private static function versionAfter(ApiSpec $spec, ApiVersion $version): ?ApiVersion
    {
        foreach ($spec->versions as $index => $candidate) {
            if ($candidate->name === $version->name) {
                return $spec->versions[$index + 1] ?? null;
            }
        }

        return null;
    }

    /**
     * Whether the versions are actually in the URLs. They usually are, but an
     * API that negotiates its version in a header has none in its paths, and
     * telling that reader to change a path segment would be wrong.
     */
    private static function versionInPath(ApiSpec $spec): bool
    {
        foreach ($spec->endpoints() as $endpoint) {
            if ($endpoint->version !== null && Versions::fromUri($endpoint->uri) === $endpoint->version) {
                return true;
            }
        }

        return false;
    }

    private static function authentication(ApiSpec $spec): ?string
    {
        $secured = array_values(array_filter(
            $spec->endpoints(),
            static fn (Endpoint $endpoint): bool => $endpoint->authenticated,
        ));

        // Nothing detected means nothing honest to say. Better an absent page
        // than one describing an auth scheme this API may not use.
        if ($secured === []) {
            return null;
        }

        $public = array_values(array_filter(
            $spec->endpoints(),
            static fn (Endpoint $endpoint): bool => ! $endpoint->authenticated,
        ));

        // The most common scheme, not whichever endpoint happens to be first:
        // an API mixing schemes would otherwise describe the wrong one.
        $scheme = self::commonScheme($secured);

        $headerLines = [];

        foreach ($scheme->exampleHeaders() as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $lines = [
            'Authenticated endpoints expect '.lcfirst($scheme->label()).'.',
            '',
            '```bash',
            ...$headerLines,
            '```',
            '',
            '## Which endpoints need it',
            '',
            sprintf(
                '%d of %d endpoints require authentication.',
                count($secured),
                count($spec->endpoints()),
            ),
            '',
        ];

        if ($public !== []) {
            $lines[] = 'These endpoints are public and need no credentials:';
            $lines[] = '';

            foreach (array_slice($public, 0, 12) as $endpoint) {
                $lines[] = "- `{$endpoint->method->value} {$endpoint->path()}`";
            }

            if (count($public) > 12) {
                $lines[] = '- …and '.(count($public) - 12).' more.';
            }

            $lines[] = '';
        }

        $lines[] = '## A complete request';
        $lines[] = '';
        $lines[] = '```bash';
        $lines[] = Snippets::curl($secured[0], $spec->baseUrl);
        $lines[] = '```';
        $lines[] = '';
        $others = self::otherSchemes($secured, $scheme);

        if ($others !== []) {
            $lines[] = 'Some endpoints authenticate differently: '.implode('; ', $others)
                .'. Each endpoint page states what it expects.';
            $lines[] = '';
        }

        $lines[] = 'A request with a missing, malformed or expired token is rejected with `401`. ';
        $lines[] = 'A valid token that lacks permission for the operation is rejected with `403`.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private static function errors(ApiSpec $spec): ?string
    {
        /** @var array<int, Response> $statuses */
        $statuses = [];

        foreach ($spec->endpoints() as $endpoint) {
            foreach ($endpoint->responses as $response) {
                if (! $response->isSuccess() && ! isset($statuses[$response->status])) {
                    $statuses[$response->status] = $response;
                }
            }
        }

        if ($statuses === []) {
            return null;
        }

        ksort($statuses);

        $lines = [
            'Errors are returned with a conventional HTTP status and a JSON body.',
            '',
            '## Status codes used by this API',
            '',
            '| Status | Meaning |',
            '| --- | --- |',
        ];

        foreach ($statuses as $status => $response) {
            // The status code's general meaning, not one endpoint's wording -
            // each endpoint page already gives its own.
            $lines[] = "| `{$status}` | ".str_replace('|', '\\|', $response->reasonPhrase()).' |';
        }

        $lines[] = '';

        $example = self::firstErrorExample($statuses);

        if ($example !== null) {
            $lines[] = '## Error body';
            $lines[] = '';
            $lines[] = '```json';
            $lines[] = $example;
            $lines[] = '```';
            $lines[] = '';
        }

        if (isset($statuses[429])) {
            $lines[] = 'A `429` means you have exceeded a rate limit. See [Rate limiting](rate-limiting) for the specific limits.';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Built from `throttle` middleware, which states the limits precisely and
     * which almost no application documents. Every integrator hits this
     * eventually, usually in production and usually by surprise.
     */
    private static function rateLimiting(ApiSpec $spec): ?string
    {
        /** @var array<string, list<Endpoint>> $byLimit */
        $byLimit = [];

        foreach ($spec->endpoints() as $endpoint) {
            if ($endpoint->rateLimit !== null) {
                $byLimit[$endpoint->rateLimit->label()][] = $endpoint;
            }
        }

        $throttled = $byLimit !== [];
        $returns429 = self::returns429($spec);

        // Nothing throttled and nothing returning 429 means there is no limit
        // to describe. Saying "this API is rate limited" anyway would be a
        // guess about someone's infrastructure.
        if (! $throttled && ! $returns429) {
            return null;
        }

        $lines = [];

        if (! $throttled) {
            $lines[] = 'This API rate limits requests. Exceeding a limit returns `429 Too Many Requests`.';
            $lines[] = '';
        } else {
            $lines[] = 'Requests are rate limited per client. Exceeding a limit returns `429 Too Many Requests`.';
            $lines[] = '';
            $lines[] = '## Limits';
            $lines[] = '';

            // One limit across the whole API is the common case and reads
            // better as a sentence than as a one-row table.
            if (count($byLimit) === 1 && count(reset($byLimit)) === count($spec->endpoints())) {
                // Phrased to fit both shapes a limit takes: "60 requests per
                // minute" and "Rate limited by the `api-global` limiter" do
                // not both follow "Every endpoint allows".
                $lines[] = 'The same limit applies to every endpoint: **'.array_key_first($byLimit).'**.';
                $lines[] = '';
            } else {
                $lines[] = '| Limit | Endpoints |';
                $lines[] = '| --- | --- |';

                foreach ($byLimit as $label => $endpoints) {
                    $lines[] = '| '.$label.' | '.self::describeEndpoints($endpoints).' |';
                }

                $lines[] = '';

                $unlimited = count($spec->endpoints()) - array_sum(array_map('count', $byLimit));

                if ($unlimited > 0) {
                    $lines[] = "The remaining {$unlimited} ".($unlimited === 1 ? 'endpoint declares' : 'endpoints declare')
                        .' no limit of their own.';
                    $lines[] = '';
                }
            }
        }

        $lines[] = '## Staying within the limit';
        $lines[] = '';
        $lines[] = 'A `429` response carries a `Retry-After` header giving the number of seconds to wait. Honour it rather than retrying immediately — a tight retry loop will keep you locked out.';
        $lines[] = '';
        $lines[] = 'Two habits keep you well clear of the limit:';
        $lines[] = '';
        $lines[] = '- Request larger pages instead of more pages. One call for 100 records costs a quarter of four calls for 25.';
        $lines[] = '- Cache anything that does not change often, and prefer webhooks over polling where they exist.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  list<Endpoint>  $endpoints
     */
    private static function describeEndpoints(array $endpoints): string
    {
        $groups = [];

        foreach ($endpoints as $endpoint) {
            $groups[$endpoint->group ?? 'Other'] = true;
        }

        $names = array_keys($groups);

        if (count($names) <= 3) {
            return implode(', ', $names);
        }

        return implode(', ', array_slice($names, 0, 3)).' and '.(count($names) - 3).' more';
    }

    /**
     * @param  list<Endpoint>  $secured
     */
    private static function commonScheme(array $secured): SecurityScheme
    {
        /** @var array<string, array{scheme: SecurityScheme, count: int}> $tally */
        $tally = [];

        foreach ($secured as $endpoint) {
            $scheme = $endpoint->securityScheme();

            if ($scheme === null) {
                continue;
            }

            $key = $scheme->type.':'.implode(',', $scheme->headerNames());

            $tally[$key] ??= ['scheme' => $scheme, 'count' => 0];
            $tally[$key]['count']++;
        }

        if ($tally === []) {
            return new SecurityScheme;
        }

        uasort($tally, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return reset($tally)['scheme'];
    }

    /**
     * @param  list<Endpoint>  $secured
     * @return list<string>
     */
    private static function otherSchemes(array $secured, SecurityScheme $common): array
    {
        $seen = [];

        foreach ($secured as $endpoint) {
            $scheme = $endpoint->securityScheme();

            if ($scheme === null || $scheme->type === $common->type) {
                continue;
            }

            $seen[$scheme->label()] = true;
        }

        return array_keys($seen);
    }

    private static function returns429(ApiSpec $spec): bool
    {
        foreach ($spec->endpoints() as $endpoint) {
            foreach ($endpoint->responses as $response) {
                if ($response->status === 429) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, Response>  $statuses
     */
    private static function firstErrorExample(array $statuses): ?string
    {
        foreach ($statuses as $response) {
            foreach ($response->examples as $example) {
                return $example->render();
            }
        }

        return null;
    }

    /**
     * The endpoint to show as the reader's first request.
     *
     * A public GET is ideal: it can be pasted and run with no credentials and
     * destroys nothing. Falling back to any GET keeps the example safe; a
     * DELETE would be a hostile thing to put at the top of an introduction.
     */
    private static function exampleEndpoint(ApiSpec $spec): ?Endpoint
    {
        $gets = array_values(array_filter(
            $spec->endpoints(),
            static fn (Endpoint $endpoint): bool => $endpoint->method === HttpMethod::Get,
        ));

        foreach ($gets as $endpoint) {
            if (! $endpoint->authenticated) {
                return $endpoint;
            }
        }

        return $gets[0] ?? $spec->endpoints()[0] ?? null;
    }

    /**
     * A one-line description of a group, from the verbs it actually exposes.
     */
    private static function describeGroup(Group $group): string
    {
        $verbs = [];

        foreach ($group->endpoints as $endpoint) {
            $verbs[$endpoint->method->value] = true;
        }

        $can = [];

        if (isset($verbs['GET'])) {
            $can[] = 'read';
        }

        if (isset($verbs['POST'])) {
            $can[] = 'create';
        }

        if (isset($verbs['PUT']) || isset($verbs['PATCH'])) {
            $can[] = 'update';
        }

        if (isset($verbs['DELETE'])) {
            $can[] = 'delete';
        }

        if ($can === []) {
            return 'operations on '.strtolower($group->name);
        }

        return ucfirst(implode(', ', $can)).' '.strtolower($group->name).'.';
    }
}
