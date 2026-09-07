<?php

declare(strict_types=1);

namespace Lusen\Emit;

use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\SchemaType;
use Lusen\Ir\Schema;

/**
 * The named shapes an OpenAPI document should define once and reference.
 *
 * Without this every endpoint returning a customer inlines the whole customer
 * shape, and a generated client emits six near-identical `Customer` types
 * because nothing told it they were the same thing. Definitions are what
 * `components/schemas` is for, and 3.1 schemas are real JSON Schema, so a
 * `$ref` costs no fidelity.
 *
 * Naming is the delicate part, because a component name becomes a type name in
 * somebody's generated client and is therefore a stability contract, like an
 * endpoint id. Three rules, in order:
 *
 * - One structure claiming a title gets it. `Customer`.
 * - Several structures claiming one title - which is the normal case for a
 *   versioned API, where `v1` and `v2` both have a `CustomerResource` - are
 *   qualified by version, but only when each belongs to exactly one version
 *   and no two share it. `V1Customer`, `V2Customer`.
 * - Anything still ambiguous is not hoisted at all, and stays inline where it
 *   was. A wrong name is worse than a long document: inlining costs bytes,
 *   while merging two different shapes under one name gives a client a type
 *   that does not match half the responses it is used for.
 */
final class SchemaComponents
{
    /**
     * @param  array<string, Schema>  $byName  component name => shape
     * @param  array<string, string>  $names  structure fingerprint => component name
     */
    private function __construct(
        private array $byName,
        private array $names,
    ) {}

    public static function of(ApiSpec $spec): self
    {
        /** @var array<string, array{schema: Schema, title: string, versions: array<string, true>}> $found */
        $found = [];

        foreach ($spec->endpoints() as $endpoint) {
            foreach (self::schemasOf($endpoint) as $schema) {
                self::collect($schema, $endpoint->version, $found);
            }
        }

        return self::name($found);
    }

    /**
     * The component name for this shape, if it has one.
     */
    public function nameFor(Schema $schema): ?string
    {
        return $this->names[self::fingerprint($schema)] ?? null;
    }

    /**
     * @return array<string, Schema>
     */
    public function all(): array
    {
        return $this->byName;
    }

    /**
     * @param  array<string, array{schema: Schema, title: string, versions: array<string, true>}>  $found
     */
    private static function collect(Schema $schema, ?string $version, array &$found): void
    {
        if ($schema->title !== null && $schema->type === SchemaType::Object && $schema->properties !== []) {
            $key = self::fingerprint($schema);

            $found[$key] ??= ['schema' => $schema, 'title' => $schema->title, 'versions' => []];

            if ($version !== null) {
                $found[$key]['versions'][$version] = true;
            }
        }

        foreach ($schema->properties as $property) {
            self::collect($property, $version, $found);
        }

        if ($schema->items !== null) {
            self::collect($schema->items, $version, $found);
        }
    }

    /**
     * @param  array<string, array{schema: Schema, title: string, versions: array<string, true>}>  $found
     */
    private static function name(array $found): self
    {
        $byTitle = [];

        foreach ($found as $key => $entry) {
            $byTitle[$entry['title']][$key] = $entry;
        }

        $byName = [];
        $names = [];

        foreach ($byTitle as $title => $claimants) {
            if (count($claimants) === 1) {
                $key = array_key_first($claimants);
                $byName[$title] = $claimants[$key]['schema'];
                $names[$key] = $title;

                continue;
            }

            $qualified = self::qualify($title, $claimants);

            if ($qualified === []) {
                $qualified = self::merge($title, $claimants, $byName);
            }

            foreach ($qualified as $key => $name) {
                $byName[$name] ??= $claimants[$key]['schema'];
                $names[$key] = $name;
            }
        }

        ksort($byName);

        return new self($byName, $names);
    }

    /**
     * Version-qualified names, or nothing at all.
     *
     * Every claimant has to belong to exactly one version and no two may share
     * it. Anything less and there is no honest name to give them, so none of
     * them is hoisted.
     *
     * @param  array<string, array{schema: Schema, title: string, versions: array<string, true>}>  $claimants
     * @return array<string, string>
     */
    private static function qualify(string $title, array $claimants): array
    {
        $names = [];
        $taken = [];

        foreach ($claimants as $key => $entry) {
            if (count($entry['versions']) !== 1) {
                return [];
            }

            $version = (string) array_key_first($entry['versions']);

            if (isset($taken[$version])) {
                return [];
            }

            $taken[$version] = true;
            $names[$key] = ucfirst($version).$title;
        }

        return $names;
    }

    /**
     * One name for several readings of the same shape.
     *
     * A resource that reaches itself - a user with posts whose author is a
     * user - is read to a fixed depth and then stopped, and where the stop
     * falls depends on where the reading started. That produces two versions
     * of one shape which differ only in how far down they go. They are not
     * two types: the cap is Lusen's reading limit, not the API's, and the
     * nested author really does have the fields the truncated copy left out.
     * So referencing the fuller reading from both places says something truer
     * than writing out the shorter one.
     *
     * Only when the claimants name exactly the same top-level fields, which
     * is what a truncation variant looks like and what two genuinely
     * different resources that happen to share a short name usually do not.
     * Failing that, nothing is hoisted: a wrong name is worse than a long
     * document.
     *
     * @param  array<string, array{schema: Schema, title: string, versions: array<string, true>}>  $claimants
     * @param  array<string, Schema>  $byName
     * @return array<string, string>
     */
    private static function merge(string $title, array $claimants, array &$byName): array
    {
        $fields = null;
        $richest = null;
        $longest = -1;

        foreach ($claimants as $entry) {
            $names = array_keys($entry['schema']->properties);
            sort($names);

            if ($fields === null) {
                $fields = $names;
            } elseif ($fields !== $names) {
                return [];
            }

            $length = strlen((string) json_encode($entry['schema']->toArray()));

            if ($length > $longest) {
                $longest = $length;
                $richest = $entry['schema'];
            }
        }

        if ($richest === null) {
            return [];
        }

        $byName[$title] = $richest;

        return array_fill_keys(array_keys($claimants), $title);
    }

    /**
     * Every schema an endpoint states, request side and response side.
     *
     * @return list<Schema>
     */
    private static function schemasOf(Endpoint $endpoint): array
    {
        $schemas = [];

        foreach ($endpoint->parameters as $parameter) {
            $schemas[] = $parameter->schema;
        }

        foreach ($endpoint->responses as $response) {
            if ($response->schema !== null) {
                $schemas[] = $response->schema;
            }
        }

        return $schemas;
    }

    /**
     * Identity for a shape: its serialised form.
     *
     * The IR serialises deterministically, which is what makes this usable -
     * two readings of the same resource produce byte-identical JSON, and two
     * different shapes cannot collide.
     */
    private static function fingerprint(Schema $schema): string
    {
        return hash('xxh128', (string) json_encode($schema->toArray()));
    }
}
