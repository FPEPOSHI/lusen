<?php

declare(strict_types=1);

namespace Lusen\Diff;

use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\SchemaType;
use Lusen\Ir\Parameter;
use Lusen\Ir\Response;
use Lusen\Ir\Schema;
use Lusen\Support\SchemaFields;

/**
 * What changed between two builds of the same API.
 *
 * The documentation already knows the shape of every request and response, so
 * it can answer a question the test suite usually cannot: did this branch
 * break somebody's client? Nothing here reads the filesystem, the router or
 * the container - two `ApiSpec` values in, a list of changes out - so all of
 * it is provable in tests/Unit with no application booted.
 *
 * The severity rules are the whole product. A gate that fires on every
 * difference is a gate people disable, so anything that cannot break a
 * working client is reported and not counted:
 *
 * - A field the extractors could not type before and can type now is a better
 *   docs build, not an API change. Any transition to or from `any` is a
 *   Notice, never Breaking. Without that rule, adding a model cast would fail
 *   CI on endpoints nobody touched.
 * - Losing a documented error status is docs drift; losing a documented
 *   success status is a contract change.
 * - An endpoint that stops requiring authentication breaks no client, but it
 *   is the last thing that should slip out silently, so it is a Notice with a
 *   sentence that reads like one.
 */
final class SpecDiff
{
    /**
     * Constraints whose meaning tightens as the number falls.
     */
    private const UPPER_BOUNDS = ['max', 'maxLength', 'maxItems'];

    /**
     * Constraints whose meaning tightens as the number rises.
     */
    private const LOWER_BOUNDS = ['min', 'minLength', 'minItems'];

    /**
     * @return list<Change>
     */
    public static function between(ApiSpec $before, ApiSpec $after): array
    {
        $changes = self::identity($before, $after);

        $old = self::index($before);
        $new = self::index($after);
        $renamed = self::renames($old, $new);

        foreach ($old as $id => $endpoint) {
            $newId = isset($new[$id]) ? $id : ($renamed[$id] ?? null);

            if ($newId === null) {
                $changes[] = Change::breaking(
                    'endpoint.removed',
                    self::subject($endpoint),
                    'is no longer documented',
                );

                continue;
            }

            if ($newId !== $id) {
                $changes[] = Change::breaking(
                    'endpoint.renamed',
                    self::subject($endpoint),
                    "changed id from `{$id}` to `{$newId}` - every anchor, operationId and citation on the old one breaks",
                );
            }

            $changes = [...$changes, ...self::endpoint($endpoint, $new[$newId])];
        }

        $claimed = array_values($renamed);

        foreach ($new as $id => $endpoint) {
            if (! isset($old[$id]) && ! in_array($id, $claimed, true)) {
                $changes[] = Change::additive('endpoint.added', self::subject($endpoint), 'is newly documented');
            }
        }

        return $changes;
    }

    /**
     * Whether anything in the list would break a client already calling the
     * baseline.
     *
     * @param  list<Change>  $changes
     */
    public static function breaks(array $changes): bool
    {
        foreach ($changes as $change) {
            if ($change->isBreaking()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Change>  $changes
     * @return array<string, int> severity => count, in severity order
     */
    public static function tally(array $changes): array
    {
        $tally = [];

        foreach (Severity::cases() as $severity) {
            $tally[$severity->value] = 0;
        }

        foreach ($changes as $change) {
            $tally[$change->severity->value]++;
        }

        return $tally;
    }

    /**
     * Spec-level facts. A version disappearing is the largest breaking change
     * an API can make, and no single endpoint comparison can see it.
     *
     * @return list<Change>
     */
    private static function identity(ApiSpec $before, ApiSpec $after): array
    {
        $changes = [];

        $old = array_map(static fn ($version): string => $version->name, $before->versions);
        $new = array_map(static fn ($version): string => $version->name, $after->versions);

        foreach (array_diff($old, $new) as $gone) {
            $changes[] = Change::breaking('version.removed', $gone, 'is no longer served');
        }

        foreach (array_diff($new, $old) as $added) {
            $changes[] = Change::additive('version.added', $added, 'is newly served');
        }

        if ($before->baseUrl !== $after->baseUrl && $after->baseUrl !== null) {
            $changes[] = Change::notice(
                'spec.baseUrl',
                $before->title,
                "base URL moved from `{$before->baseUrl}` to `{$after->baseUrl}`",
            );
        }

        return $changes;
    }

    /**
     * @return list<Change>
     */
    private static function endpoint(Endpoint $before, Endpoint $after): array
    {
        $subject = self::subject($after);
        $changes = [];

        if ($before->uri !== $after->uri || $before->method !== $after->method) {
            $changes[] = Change::breaking(
                'endpoint.moved',
                $subject,
                'moved from `'.$before->method->value.' '.$before->path().'`',
            );
        }

        if (! $before->authenticated && $after->authenticated) {
            $changes[] = Change::breaking('endpoint.authenticated', $subject, 'now requires authentication');
        }

        if ($before->authenticated && ! $after->authenticated) {
            $changes[] = Change::notice(
                'endpoint.public',
                $subject,
                'no longer requires authentication - it is now open to anyone who finds it',
            );
        }

        $changes = [...$changes, ...self::scopes($before, $after, $subject)];

        if (! $before->deprecated && $after->deprecated) {
            $changes[] = Change::notice('endpoint.deprecated', $subject, 'is now deprecated');
        }

        return [
            ...$changes,
            ...self::parameters($before, $after, $subject),
            ...self::responses($before, $after, $subject),
        ];
    }

    /**
     * @return list<Change>
     */
    private static function scopes(Endpoint $before, Endpoint $after, string $subject): array
    {
        $old = $before->security === null ? [] : $before->security->scopes;
        $new = $after->security === null ? [] : $after->security->scopes;
        $changes = [];

        foreach (array_diff($new, $old) as $added) {
            $changes[] = Change::breaking(
                'scope.required',
                $subject,
                "now requires the `{$added}` scope - existing tokens without it are refused",
            );
        }

        foreach (array_diff($old, $new) as $dropped) {
            $changes[] = Change::additive('scope.dropped', $subject, "no longer requires the `{$dropped}` scope");
        }

        return $changes;
    }

    /**
     * @return list<Change>
     */
    private static function parameters(Endpoint $before, Endpoint $after, string $subject): array
    {
        $old = self::byName($before->parameters);
        $new = self::byName($after->parameters);
        $changes = [];

        foreach ($old as $key => $parameter) {
            if (! isset($new[$key])) {
                $changes[] = Change::breaking(
                    'parameter.removed',
                    $subject,
                    "no longer accepts the {$parameter->in->value} parameter `{$parameter->name}`",
                );

                continue;
            }

            $changes = [...$changes, ...self::parameter($parameter, $new[$key], $subject)];
        }

        foreach ($new as $key => $parameter) {
            if (isset($old[$key])) {
                continue;
            }

            $where = "{$parameter->in->value} parameter `{$parameter->name}`";

            $changes[] = $parameter->required
                ? Change::breaking('parameter.added', $subject, "requires a new {$where}")
                : Change::additive('parameter.added', $subject, "accepts a new optional {$where}");
        }

        return $changes;
    }

    /**
     * @return list<Change>
     */
    private static function parameter(Parameter $before, Parameter $after, string $subject): array
    {
        $name = "`{$after->name}`";
        $changes = [];

        if (! $before->required && $after->required) {
            $changes[] = Change::breaking('parameter.required', $subject, "{$name} is now required");
        }

        if ($before->required && ! $after->required) {
            $changes[] = Change::additive('parameter.optional', $subject, "{$name} is now optional");
        }

        if (! $before->deprecated && $after->deprecated) {
            $changes[] = Change::notice('parameter.deprecated', $subject, "{$name} is now deprecated");
        }

        return [...$changes, ...self::schema($before->schema, $after->schema, $subject, $name, request: true)];
    }

    /**
     * @return list<Change>
     */
    private static function responses(Endpoint $before, Endpoint $after, string $subject): array
    {
        $old = self::byStatus($before->responses);
        $new = self::byStatus($after->responses);
        $changes = [];

        foreach ($old as $status => $response) {
            if (! isset($new[$status])) {
                // A documented error going missing is almost always the docs
                // drifting, not the API refusing to return it any more. A
                // success status is the response people wrote code against.
                $changes[] = $response->isSuccess()
                    ? Change::breaking('response.removed', $subject, "no longer returns {$status}")
                    : Change::notice('response.undocumented', $subject, "no longer documents {$status}");

                continue;
            }

            $changes = [...$changes, ...self::response($response, $new[$status], $subject)];
        }

        foreach ($new as $status => $response) {
            if (! isset($old[$status])) {
                $changes[] = Change::additive('response.added', $subject, "now documents {$status}");
            }
        }

        return $changes;
    }

    /**
     * @return list<Change>
     */
    private static function response(Response $before, Response $after, string $subject): array
    {
        $status = $after->status;
        $changes = [];

        if ($before->contentType !== $after->contentType) {
            $changes[] = Change::breaking(
                'response.contentType',
                $subject,
                "{$status} now returns `{$after->contentType}` rather than `{$before->contentType}`",
            );
        }

        if ($before->schema === null || $after->schema === null) {
            if ($before->schema !== null) {
                $changes[] = Change::notice('response.shape', $subject, "{$status} no longer describes a body");
            }

            return $changes;
        }

        $old = SchemaFields::paths($before->schema);
        $new = SchemaFields::paths($after->schema);

        foreach ($old as $path => $field) {
            if (! isset($new[$path])) {
                $changes[] = Change::breaking(
                    'response.field.removed',
                    $subject,
                    "{$status} no longer returns `{$path}`",
                );

                continue;
            }

            $changes = [
                ...$changes,
                ...self::schema($field['schema'], $new[$path]['schema'], $subject, "{$status} `{$path}`", request: false),
            ];
        }

        foreach ($new as $path => $field) {
            if (! isset($old[$path])) {
                $changes[] = Change::additive('response.field.added', $subject, "{$status} now returns `{$path}`");
            }
        }

        return $changes;
    }

    /**
     * One value's shape, compared.
     *
     * `$request` flips the two rules that are not symmetric. Accepting null
     * where you did not before is a widening for a caller and a narrowing for
     * a consumer, and an enum gains members in one direction for each.
     *
     * @return list<Change>
     */
    private static function schema(Schema $before, Schema $after, string $subject, string $name, bool $request): array
    {
        $changes = [];

        if ($before->type !== $after->type || $before->format !== $after->format) {
            $from = $before->label();
            $to = $after->label();

            // Either side unknown means the extractors learned something or
            // forgot something, not that the API changed. Failing a build on
            // that would punish adding a cast to a model.
            $changes[] = $before->type === SchemaType::Any || $after->type === SchemaType::Any
                ? Change::notice('schema.typed', $subject, "{$name} is now `{$to}` rather than `{$from}`")
                : Change::breaking('schema.type', $subject, "{$name} changed from `{$from}` to `{$to}`");
        }

        if ($before->nullable !== $after->nullable) {
            $widening = $request ? $after->nullable : ! $after->nullable;

            $changes[] = $widening
                ? Change::additive('schema.nullable', $subject, "{$name} is now ".($after->nullable ? 'nullable' : 'never null'))
                : Change::breaking('schema.nullable', $subject, "{$name} is now ".($after->nullable ? 'nullable' : 'never null'));
        }

        foreach (array_diff(self::enum($before), self::enum($after)) as $gone) {
            $changes[] = $request
                ? Change::breaking('schema.enum', $subject, "{$name} no longer accepts `{$gone}`")
                : Change::notice('schema.enum', $subject, "{$name} no longer returns `{$gone}`");
        }

        foreach (array_diff(self::enum($after), self::enum($before)) as $added) {
            $changes[] = $request
                ? Change::additive('schema.enum', $subject, "{$name} also accepts `{$added}`")
                : Change::notice('schema.enum', $subject, "{$name} can now return `{$added}` - a client matching on the old set will not know it");
        }

        return [...$changes, ...self::constraints($before, $after, $subject, $name)];
    }

    /**
     * @return list<Change>
     */
    private static function constraints(Schema $before, Schema $after, string $subject, string $name): array
    {
        $changes = [];

        foreach ([...self::UPPER_BOUNDS, ...self::LOWER_BOUNDS] as $key) {
            $old = $before->constraints[$key] ?? null;
            $new = $after->constraints[$key] ?? null;

            if ($old === $new || ! is_numeric($old) || ! is_numeric($new)) {
                // An absent bound on either side says nothing useful: rules
                // are read statically, so a bound can vanish because a rule
                // moved somewhere unreadable rather than because it was
                // lifted.
                continue;
            }

            $tighter = in_array($key, self::UPPER_BOUNDS, true) ? $new < $old : $new > $old;

            $changes[] = $tighter
                ? Change::breaking('schema.constraint', $subject, "{$name} tightened {$key} from {$old} to {$new}")
                : Change::additive('schema.constraint', $subject, "{$name} relaxed {$key} from {$old} to {$new}");
        }

        $old = $before->constraints['pattern'] ?? null;
        $new = $after->constraints['pattern'] ?? null;

        if ($old !== $new && is_string($new)) {
            $changes[] = Change::breaking(
                'schema.constraint',
                $subject,
                "{$name} now has to match `{$new}`",
            );
        }

        return $changes;
    }

    /**
     * An endpoint whose id changed but whose method and URI did not is a
     * rename, and reporting it as a removal beside an unrelated addition
     * would hide the one thing that actually broke: the operationId.
     *
     * @param  array<string, Endpoint>  $old
     * @param  array<string, Endpoint>  $new
     * @return array<string, string> old id => new id
     */
    private static function renames(array $old, array $new): array
    {
        $candidates = [];

        foreach ($new as $id => $endpoint) {
            if (! isset($old[$id])) {
                $candidates[self::route($endpoint)] = $id;
            }
        }

        $renames = [];

        foreach ($old as $id => $endpoint) {
            $route = self::route($endpoint);

            if (! isset($new[$id]) && isset($candidates[$route])) {
                $renames[$id] = $candidates[$route];
            }
        }

        return $renames;
    }

    /**
     * @return array<string, Endpoint>
     */
    private static function index(ApiSpec $spec): array
    {
        $endpoints = [];

        foreach ($spec->endpoints() as $endpoint) {
            $endpoints[$endpoint->id] = $endpoint;
        }

        return $endpoints;
    }

    /**
     * @param  list<Parameter>  $parameters
     * @return array<string, Parameter>
     */
    private static function byName(array $parameters): array
    {
        $keyed = [];

        foreach ($parameters as $parameter) {
            // Location included: a `id` in the path and a `id` in the query
            // are two parameters, and pairing them would report nonsense.
            $keyed[$parameter->in->value.':'.$parameter->name] = $parameter;
        }

        return $keyed;
    }

    /**
     * @param  list<Response>  $responses
     * @return array<int, Response>
     */
    private static function byStatus(array $responses): array
    {
        $keyed = [];

        foreach ($responses as $response) {
            $keyed[$response->status] = $response;
        }

        ksort($keyed);

        return $keyed;
    }

    /**
     * @return list<string>
     */
    private static function enum(Schema $schema): array
    {
        return array_map(
            static fn (string|int|float|bool $value): string => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
            $schema->enum,
        );
    }

    private static function route(Endpoint $endpoint): string
    {
        return $endpoint->method->value.' '.$endpoint->uri;
    }

    private static function subject(Endpoint $endpoint): string
    {
        return $endpoint->method->value.' '.$endpoint->path();
    }
}
