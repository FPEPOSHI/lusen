<?php

declare(strict_types=1);

namespace Lusen\Extract\Models;

use Lusen\Ir\Schema;
use Lusen\Support\Ast;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;

/**
 * Reads column types out of migrations.
 *
 * Migrations are the most complete description of an application's data that
 * exists in its repository, and the only one that states nullability. Casts
 * cover what a model transforms; this covers everything it does not.
 *
 * Chains are read in full, so `$table->string('name', 120)->nullable()`
 * contributes a type, a maximum length and nullability. Later migrations win,
 * because a column added and then changed should document as changed.
 */
final class MigrationReader
{
    /**
     * @var array<string, array<string, Schema>>|null
     */
    private static ?array $tables = null;

    /**
     * @param  list<string>  $paths  directories holding migration files
     */
    public function __construct(private readonly array $paths) {}

    public static function flushCache(): void
    {
        self::$tables = null;
    }

    /**
     * @return array<string, Schema> column name => schema
     */
    public function columns(string $table): array
    {
        return $this->all()[$table] ?? [];
    }

    /**
     * @return array<string, array<string, Schema>>
     */
    public function all(): array
    {
        if (self::$tables !== null) {
            return self::$tables;
        }

        $tables = [];

        foreach ($this->files() as $file) {
            $ast = Ast::parse($file);

            if ($ast === null) {
                continue;
            }

            /** @var list<StaticCall> $calls */
            $calls = (new NodeFinder)->findInstanceOf($ast, StaticCall::class);

            foreach ($calls as $call) {
                $this->readSchemaCall($call, $tables);
            }
        }

        return self::$tables = $tables;
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = [];

        foreach ($this->paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            foreach (glob(rtrim($path, '/').'/*.php') ?: [] as $file) {
                $files[] = $file;
            }
        }

        // Migration filenames are timestamp-prefixed, so sorting them puts
        // later changes last, where they should win.
        sort($files);

        return $files;
    }

    /**
     * @param  array<string, array<string, Schema>>  $tables
     */
    private function readSchemaCall(StaticCall $call, array &$tables): void
    {
        if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier) {
            return;
        }

        if (! str_ends_with($call->class->toLowerString(), 'schema')) {
            return;
        }

        if (! in_array($call->name->toLowerString(), ['create', 'table'], true)) {
            return;
        }

        $arguments = $call->getArgs();

        if (! isset($arguments[0]) || ! $arguments[0]->value instanceof String_) {
            return;
        }

        $table = $arguments[0]->value->value;

        /** @var list<MethodCall> $calls */
        $calls = (new NodeFinder)->findInstanceOf($call, MethodCall::class);

        foreach ($this->chains($calls) as $chain) {
            $this->readColumn($chain, $table, $tables);
        }
    }

    /**
     * Groups a flat list of method calls into chains, outermost first.
     *
     * `$table->string('name', 120)->nullable()` parses with `nullable` on the
     * outside and `string` within it, so reading only the innermost call - the
     * one that names the column - would silently drop every modifier.
     *
     * @param  list<MethodCall>  $calls
     * @return list<list<MethodCall>> each chain from base call to outermost
     */
    private function chains(array $calls): array
    {
        $receivers = [];

        foreach ($calls as $candidate) {
            if ($candidate->var instanceof MethodCall) {
                $receivers[spl_object_id($candidate->var)] = true;
            }
        }

        $chains = [];

        foreach ($calls as $candidate) {
            // Anything that is somebody else's receiver is mid-chain.
            if (isset($receivers[spl_object_id($candidate)])) {
                continue;
            }

            $chain = [];
            $node = $candidate;

            while ($node instanceof MethodCall) {
                array_unshift($chain, $node);
                $node = $node->var;
            }

            $chains[] = $chain;
        }

        return $chains;
    }

    /**
     * @param  list<MethodCall>  $chain  base call first
     * @param  array<string, array<string, Schema>>  $tables
     */
    private function readColumn(array $chain, string $table, array &$tables): void
    {
        $base = $chain[0];

        if (! $base->name instanceof Node\Identifier) {
            return;
        }

        $type = $base->name->toLowerString();
        $arguments = $base->getArgs();

        if ($type === 'timestamps') {
            $tables[$table]['created_at'] = new Schema(format: 'date-time', nullable: true);
            $tables[$table]['updated_at'] = new Schema(format: 'date-time', nullable: true);

            return;
        }

        if ($type === 'softdeletes') {
            $tables[$table]['deleted_at'] = new Schema(format: 'date-time', nullable: true);

            return;
        }

        if ($type === 'id') {
            $name = isset($arguments[0]) && $arguments[0]->value instanceof String_
                ? $arguments[0]->value->value
                : 'id';

            $tables[$table][$name] = Schema::integer();

            return;
        }

        if (! isset($arguments[0]) || ! $arguments[0]->value instanceof String_) {
            return;
        }

        $schema = $this->schemaFor($type, $arguments);

        if ($schema === null) {
            return;
        }

        $tables[$table][$arguments[0]->value->value] = $this->applyModifiers($schema, $chain);
    }

    /**
     * @param  array<Node\Arg>  $arguments
     */
    private function schemaFor(string $type, array $arguments): ?Schema
    {
        $length = isset($arguments[1]) && $arguments[1]->value instanceof Int_
            ? $arguments[1]->value->value
            : null;

        return match ($type) {
            'string', 'char' => $length === null
                ? Schema::string()
                : new Schema(constraints: ['maxLength' => $length]),
            'text', 'mediumtext', 'longtext', 'tinytext' => Schema::string(),
            'integer', 'biginteger', 'smallinteger', 'tinyinteger', 'mediuminteger',
            'unsignedinteger', 'unsignedbiginteger', 'unsignedsmallinteger',
            'unsignedtinyinteger', 'unsignedmediuminteger', 'foreignid', 'increments',
            'bigincrements' => Schema::integer(),
            'decimal', 'float', 'double', 'unsigneddecimal' => Schema::number(),
            'boolean' => Schema::boolean(),
            'json', 'jsonb' => Schema::arrayOf(Schema::any()),
            'date' => Schema::string('date'),
            'datetime', 'timestamp', 'datetimetz', 'timestamptz' => Schema::string('date-time'),
            'time', 'timetz' => Schema::string('time'),
            'uuid', 'foreignuuid' => Schema::string('uuid'),
            'ulid', 'foreignulid' => Schema::string(),
            'ipaddress' => Schema::string('ipv4'),
            'macaddress' => Schema::string(),
            'enum', 'set' => $this->enumColumn($arguments),
            default => null,
        };
    }

    /**
     * `$table->enum('status', ['pending', 'paid'])`.
     *
     * @param  array<Node\Arg>  $arguments
     */
    private function enumColumn(array $arguments): Schema
    {
        if (! isset($arguments[1]) || ! $arguments[1]->value instanceof Node\Expr\Array_) {
            return Schema::string();
        }

        $values = [];

        foreach ($arguments[1]->value->items as $item) {
            if ($item->value instanceof String_) {
                $values[] = $item->value->value;
            }
        }

        return $values === [] ? Schema::string() : Schema::enum($values);
    }

    /**
     * @param  list<MethodCall>  $chain
     */
    private function applyModifiers(Schema $schema, array $chain): Schema
    {
        $nullable = false;

        foreach (array_slice($chain, 1) as $modifier) {
            if ($modifier->name instanceof Node\Identifier
                && $modifier->name->toLowerString() === 'nullable') {
                // nullable(false) is a real, if rare, spelling.
                $arguments = $modifier->getArgs();
                $nullable = ! isset($arguments[0])
                    || ! $arguments[0]->value instanceof Node\Expr\ConstFetch
                    || $arguments[0]->value->name->toLowerString() !== 'false';
            }
        }

        if (! $nullable) {
            return $schema;
        }

        return new Schema(
            type: $schema->type,
            format: $schema->format,
            nullable: true,
            enum: $schema->enum,
            items: $schema->items,
            properties: $schema->properties,
            required: $schema->required,
            constraints: $schema->constraints,
            example: $schema->example,
            description: $schema->description,
        );
    }
}
