<?php

declare(strict_types=1);

namespace Lusen\Emit;

use Lusen\Ir\ApiSpec;
use Lusen\Ir\ApiVersion;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Ir\Response;
use Lusen\Ir\Schema;
use Lusen\Support\SchemaFields;
use Lusen\Support\Snippets;

/**
 * Shared Markdown building blocks.
 *
 * Both the per-endpoint mirror and the llms-full.txt corpus render the same
 * content; only the framing differs. Keeping the blocks here means the two
 * surfaces cannot drift, which matters because a model may retrieve either
 * one and must learn the same thing from both.
 */
final class Markdown
{
    /**
     * A complete endpoint, self-contained by construction.
     *
     * `$level` shifts the heading depth so the same block can sit under a
     * group in the corpus or stand alone on its own page.
     *
     * `$successor` is the same operation in a newer version of the API. It is
     * passed in rather than looked up because these blocks only ever see one
     * endpoint, and it earns its place at the top of the page: somebody
     * reading `v1` needs to know `v2` exists before they finish copying the
     * example, not after they have shipped against it.
     *
     * @param  Endpoint|null  $successor  the newer edition of this operation
     * @param  string|null  $successorUrl  where that edition is documented
     * @return list<string>
     */
    public static function endpoint(
        Endpoint $endpoint,
        ?string $baseUrl,
        int $level = 3,
        bool $includeSummary = true,
        ?Endpoint $successor = null,
        ?string $successorUrl = null,
    ): array {
        $h = str_repeat('#', $level);

        $lines = ["{$h} {$endpoint->method->value} {$endpoint->path()}", ''];

        if ($includeSummary && $endpoint->summary !== null) {
            $lines[] = $endpoint->summary;
            $lines[] = '';
        }

        if ($endpoint->description !== null) {
            $lines[] = $endpoint->description;
            $lines[] = '';
        }

        if ($endpoint->deprecated) {
            $lines[] = '**Deprecated.**';
            $lines[] = '';
        }

        $lines = [...$lines, ...self::supersession($successor, $successorUrl)];

        // Repeated on every endpoint on purpose: a retrieved fragment must
        // not send the reader looking for an "authentication" section.
        if ($baseUrl !== null) {
            $lines[] = "Full URL: `{$baseUrl}{$endpoint->path()}`";
            $lines[] = '';
        }

        if ($endpoint->version !== null) {
            $lines[] = "API version: `{$endpoint->version}`.";
            $lines[] = '';
        }

        $lines[] = $endpoint->authenticated
            ? 'Authentication: required (bearer token).'
            : 'Authentication: not required.';
        $lines[] = '';

        foreach (ParameterLocation::cases() as $location) {
            $lines = [...$lines, ...self::parameterTable($endpoint, $location, $level + 1)];
        }

        $lines = [...$lines, ...self::requestExample($endpoint, $baseUrl, $level + 1)];

        return [...$lines, ...self::responses($endpoint, $level + 1)];
    }

    /**
     * @return list<string>
     */
    public static function parameterTable(Endpoint $endpoint, ParameterLocation $location, int $level = 4): array
    {
        $parameters = $endpoint->parametersIn($location);

        if ($parameters === []) {
            return [];
        }

        $lines = [
            str_repeat('#', $level).' '.ucfirst($location->value).' parameters',
            '',
            '| Name | Type | Required | Description |',
            '| --- | --- | --- | --- |',
        ];

        foreach ($parameters as $parameter) {
            $lines[] = sprintf(
                '| `%s` | %s | %s | %s |',
                $parameter->name,
                self::cell($parameter->schema->label()),
                $parameter->required ? 'yes' : 'no',
                self::cell($parameter->description ?? ''),
            );

            // Nested objects and arrays would otherwise be a bare "object" -
            // useless to someone building the request body.
            foreach (self::nested($parameter->schema, $parameter->name) as $row) {
                $lines[] = $row;
            }
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @return list<string>
     */
    public static function requestExample(Endpoint $endpoint, ?string $baseUrl, int $level = 4): array
    {
        return [
            str_repeat('#', $level).' Example request',
            '',
            '```bash',
            Snippets::curl($endpoint, $baseUrl),
            '```',
            '',
        ];
    }

    /**
     * @return list<string>
     */
    public static function responses(Endpoint $endpoint, int $level = 4): array
    {
        if ($endpoint->responses === []) {
            return [];
        }

        $lines = [str_repeat('#', $level).' Responses', ''];

        foreach ($endpoint->responses as $response) {
            $lines[] = "**{$response->status}** — {$response->label()}";
            $lines[] = '';

            // The schema is the point of extracting responses at all; without
            // a field list it would only ever be visible in an example.
            $lines = [...$lines, ...self::schemaTable($response->schema)];
            $lines = [...$lines, ...self::examples($response)];
        }

        return $lines;
    }

    public static function heading(string $text, int $level): string
    {
        return str_repeat('#', $level).' '.$text;
    }

    /**
     * The versions this API serves, for the top of an index or a corpus.
     *
     * "Which version am I looking at" is the first question a versioned API
     * raises and the one a search engine or an assistant is asked before any
     * question about an operation, so it is answered before the reference
     * starts rather than inside it.
     *
     * @return list<string>
     */
    public static function versions(ApiSpec $spec, int $level = 2): array
    {
        if (! $spec->isVersioned()) {
            return [];
        }

        $lines = [self::heading('API versions', $level), '', '| Version | Status | Endpoints |', '| --- | --- | --- |'];

        foreach ($spec->versions as $version) {
            $lines[] = sprintf(
                '| `%s` | %s | %d |',
                $version->name,
                self::cell(self::versionStatus($version)),
                count($spec->endpointsIn($version->name)),
            );
        }

        $current = $spec->currentVersion();

        $lines[] = '';

        if ($current !== null) {
            $lines[] = "Write new integrations against `{$current->name}`. "
                .'Every endpoint below states the version it belongs to, and any endpoint with a newer edition links to it.';
            $lines[] = '';
        }

        return $lines;
    }

    private static function versionStatus(ApiVersion $version): string
    {
        if ($version->sunset !== null) {
            return "deprecated — retires {$version->sunset}";
        }

        return $version->status();
    }

    /**
     * Named rather than merely linked: a model that quotes this line should be
     * able to say what to call instead without following anything.
     *
     * @return list<string>
     */
    private static function supersession(?Endpoint $successor, ?string $url): array
    {
        if ($successor === null) {
            return [];
        }

        $call = "`{$successor->method->value} {$successor->path()}`";

        return [
            $url === null
                ? "**A newer version of this operation exists**: {$call}."
                : "**A newer version of this operation exists**: [{$call}]({$url}).",
            '',
        ];
    }

    /**
     * @return list<string>
     */
    private static function schemaTable(?Schema $schema): array
    {
        if ($schema === null || ! SchemaFields::hasFields($schema)) {
            return [];
        }

        $lines = ['| Field | Type | Description |', '| --- | --- | --- |'];

        foreach (SchemaFields::flatten($schema) as $row) {
            $lines[] = sprintf(
                '| `%s` | %s | %s |',
                $row['name'],
                self::cell($row['type']),
                self::cell($row['description']),
            );
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * Flattens a nested schema into extra `parent.child` rows, so a table row
     * for an object is followed by its fields.
     *
     * @return list<string>
     */
    private static function nested(Schema $schema, string $prefix, int $depth = 0): array
    {
        if ($depth > 3) {
            return [];
        }

        $rows = [];

        if ($schema->items !== null) {
            $rows = [...$rows, ...self::nested($schema->items, $prefix.'[]', $depth + 1)];
        }

        foreach ($schema->properties as $name => $property) {
            $path = $prefix.'.'.$name;

            $rows[] = sprintf(
                '| `%s` | %s | %s | %s |',
                $path,
                self::cell($property->label()),
                in_array($name, $schema->required, true) ? 'yes' : 'no',
                self::cell($property->description ?? ''),
            );

            $rows = [...$rows, ...self::nested($property, $path, $depth + 1)];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private static function examples(Response $response): array
    {
        $lines = [];

        foreach ($response->examples as $example) {
            $lines[] = '```json';
            $lines[] = $example->render();
            $lines[] = '```';
            $lines[] = '';
        }

        return $lines;
    }

    private static function cell(string $text): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $text);
    }
}
