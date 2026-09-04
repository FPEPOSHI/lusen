<?php

declare(strict_types=1);

namespace Lusen\Extract\Types;

use Lusen\Ir\Enums\SchemaType;
use Lusen\Ir\Schema;
use Lusen\Support\Ast;
use Lusen\Support\DocBlock;
use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\UnionType;

/**
 * A written type — `array{status: bool, data: Invoice}`, `list<string>`,
 * `?ApiError` — turned into a Schema.
 *
 * Reading `JsonResource::toArray()` is not the only way an application says
 * what it returns, and in plenty of codebases it is not available at all: an
 * API that answers with plain arrays through a base-controller envelope has no
 * resource to parse, but very often has the shape written down anyway, in a
 * `@response` docblock or in a documentation-only DTO. Both are more explicit
 * than anything inference could recover, so both beat it.
 *
 * Expression parsing and class reading live in one class because they are
 * mutually recursive — `array{data: Invoice}` needs the class, and the class's
 * `@var list<InvoiceLine>` needs the expression parser again. Splitting them
 * would only buy a circular constructor dependency.
 *
 * Anything it cannot read becomes `SchemaType::Any`, never a guess.
 */
final class TypeReader
{
    /**
     * Deep enough for the response shapes people actually write, shallow
     * enough that a self-referencing DTO cannot run away.
     */
    private const MAX_DEPTH = 8;

    /**
     * @var array<string, Schema|null>
     */
    private array $classes = [];

    /**
     * Classes currently being read, so a cycle stops rather than recurses.
     *
     * @var array<string, true>
     */
    private array $reading = [];

    /**
     * @param  TypeNames  $names  how to resolve class names written in the expression
     */
    public function read(string $expression, TypeNames $names, int $depth = 0): ?Schema
    {
        $expression = trim($expression);

        if ($expression === '' || $depth > self::MAX_DEPTH) {
            return null;
        }

        $members = self::split($expression, '|');

        // `null` in a union is nullability, not a member.
        $nullable = false;
        $rest = [];

        foreach ($members as $member) {
            if (strtolower(trim($member)) === 'null') {
                $nullable = true;

                continue;
            }

            $rest[] = trim($member);
        }

        if ($rest === []) {
            return new Schema(type: SchemaType::Any, nullable: true);
        }

        $enum = self::literalUnion($rest);

        if ($enum !== null) {
            return $nullable ? $enum->asNullable() : $enum;
        }

        // A union of real types has no JSON Schema equivalent Lusen models, and
        // the first member is the one the author led with — for the common
        // `array{...}|ApiError` that is the success shape, which is what a
        // reader of this endpoint's 200 wants.
        $schema = $this->atom($rest[0], $names, $depth);

        if ($schema === null) {
            return null;
        }

        return $nullable ? $schema->asNullable() : $schema;
    }

    /**
     * A class's public properties as a Schema.
     */
    public function readClass(string $class, int $depth = 0): ?Schema
    {
        if ($depth > self::MAX_DEPTH || isset($this->reading[$class])) {
            return new Schema(type: SchemaType::Object);
        }

        if ($depth === 0 && array_key_exists($class, $this->classes)) {
            return $this->classes[$class];
        }

        $node = class_exists($class) ? Ast::class_($class) : null;

        if ($node === null) {
            return $depth === 0 ? $this->classes[$class] = null : null;
        }

        $this->reading[$class] = true;
        $names = TypeNames::forClass($class);

        $properties = [];

        foreach ($node->stmts as $statement) {
            if (! $statement instanceof Property || ! $statement->isPublic() || $statement->isStatic()) {
                continue;
            }

            $doc = DocBlock::parse($statement->getDocComment()?->getText());

            // The docblock wins: `@var string[]` says what a bare `array`
            // cannot, and an author writing one is being deliberate.
            $written = $doc->tag('var');
            $expression = $written === null || $written === ''
                ? self::typeToString($statement->type)
                : self::leadingType($written);

            $schema = $expression === null
                ? new Schema(type: SchemaType::Any)
                : $this->read($expression, $names, $depth + 1) ?? new Schema(type: SchemaType::Any);

            $description = $doc->summary === '' ? null : $doc->summary;

            foreach ($statement->props as $property) {
                $properties[$property->name->toString()] = $description === null
                    ? $schema
                    : $schema->describedAs($description);
            }
        }

        unset($this->reading[$class]);

        // Deliberately no `required`: a DTO declares every field it can carry,
        // and PHP has no way to say which of them are always present. Marking
        // them all required would be a claim the class never made.
        $schema = new Schema(type: SchemaType::Object, properties: $properties);

        return $depth === 0 ? $this->classes[$class] = $schema : $schema;
    }

    /**
     * One member of a union.
     */
    private function atom(string $type, TypeNames $names, int $depth): ?Schema
    {
        $type = trim($type);

        if ($type === '') {
            return null;
        }

        if (str_starts_with($type, '?')) {
            return $this->read(substr($type, 1), $names, $depth + 1)?->asNullable();
        }

        if (str_starts_with($type, '(') && str_ends_with($type, ')')) {
            return $this->read(substr($type, 1, -1), $names, $depth + 1);
        }

        if (str_ends_with($type, '[]')) {
            $items = $this->read(substr($type, 0, -2), $names, $depth + 1);

            return $items === null ? null : Schema::arrayOf($items);
        }

        if (preg_match('/^array\s*\{(.*)\}$/is', $type, $match) === 1) {
            return $this->shape($match[1], $names, $depth);
        }

        if (preg_match('/^([\\\\\w]+)\s*<(.*)>$/s', $type, $match) === 1) {
            return $this->generic($match[1], $match[2], $names, $depth);
        }

        return $this->named($type, $names, $depth);
    }

    /**
     * `array{id: int, lines?: list<Line>}`.
     */
    private function shape(string $body, TypeNames $names, int $depth): ?Schema
    {
        $properties = [];
        $required = [];

        foreach (self::split($body, ',') as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            $parts = self::split($entry, ':', 2);

            // A shape with no keys is a tuple. Documented as an array of its
            // first element, which is the closest honest thing.
            if (count($parts) < 2) {
                $items = $this->read($entry, $names, $depth + 1);

                return $items === null ? null : Schema::arrayOf($items);
            }

            $key = trim($parts[0]);
            $optional = str_ends_with($key, '?');
            $key = trim(trim($optional ? substr($key, 0, -1) : $key), "'\"");

            if ($key === '') {
                continue;
            }

            $properties[$key] = $this->read($parts[1], $names, $depth + 1)
                ?? new Schema(type: SchemaType::Any);

            if (! $optional) {
                $required[] = $key;
            }
        }

        return Schema::object($properties, $required);
    }

    /**
     * `list<T>`, `array<T>`, `array<K, V>`, `Collection<int, T>`.
     */
    private function generic(string $name, string $arguments, TypeNames $names, int $depth): ?Schema
    {
        $arguments = self::split($arguments, ',');
        $last = trim(end($arguments) ?: '');

        return match (strtolower(ltrim($name, '\\'))) {
            // A keyed map: JSON object, but with no property this can name.
            'array' => count($arguments) > 1
                ? new Schema(type: SchemaType::Object)
                : $this->arrayOf($last, $names, $depth),
            'list', 'non-empty-list', 'non-empty-array', 'iterable', 'collection',
            'illuminate\support\collection', 'illuminate\database\eloquent\collection' => $this->arrayOf($last, $names, $depth),
            default => $this->named($name, $names, $depth),
        };
    }

    private function arrayOf(string $type, TypeNames $names, int $depth): ?Schema
    {
        $items = $this->read($type, $names, $depth + 1);

        return $items === null ? null : Schema::arrayOf($items);
    }

    /**
     * A bare name: a scalar keyword, a literal, or a class.
     */
    private function named(string $type, TypeNames $names, int $depth): Schema
    {
        $lower = strtolower(ltrim($type, '\\'));

        $scalar = match ($lower) {
            'int', 'integer', 'positive-int', 'negative-int', 'non-negative-int' => Schema::integer(),
            'float', 'double', 'number' => Schema::number(),
            'string', 'non-empty-string', 'numeric-string', 'class-string' => Schema::string(),
            'bool', 'boolean' => Schema::boolean(),
            'true' => Schema::boolean()->withExample(true),
            'false' => Schema::boolean()->withExample(false),
            'array' => new Schema(type: SchemaType::Array),
            'object', 'stdclass' => new Schema(type: SchemaType::Object),
            'mixed', 'void', 'never', 'null', 'self', 'static', '$this', 'callable', 'resource' => new Schema(type: SchemaType::Any),
            default => null,
        };

        if ($scalar !== null) {
            return $scalar;
        }

        $literal = self::literal($type);

        if ($literal !== null) {
            return $literal;
        }

        $class = $names->resolve($type);

        return $class === null
            ? new Schema(type: SchemaType::Any)
            : $this->readClass($class, $depth + 1) ?? new Schema(type: SchemaType::Any);
    }

    /**
     * `'active'|'invited'` is how a PHPStan shape spells an enum, and reading
     * it as one is the difference between a documented set of values and a
     * bare "string".
     *
     * @param  list<string>  $members
     */
    private static function literalUnion(array $members): ?Schema
    {
        $values = [];

        foreach ($members as $member) {
            $member = trim($member);

            if (preg_match('/^([\'"])(.*)\1$/s', $member, $match) !== 1) {
                return null;
            }

            $values[] = $match[2];
        }

        return count($values) > 1 ? Schema::enum($values) : null;
    }

    private static function literal(string $type): ?Schema
    {
        if (preg_match('/^([\'"])(.*)\1$/s', $type, $match) === 1) {
            return Schema::string()->withExample($match[2]);
        }

        if (preg_match('/^-?\d+$/', $type) === 1) {
            return Schema::integer()->withExample((int) $type);
        }

        if (preg_match('/^-?\d*\.\d+$/', $type) === 1) {
            return Schema::number()->withExample((float) $type);
        }

        return null;
    }

    /**
     * `@var string[] Some description` — only the type is ours.
     */
    private static function leadingType(string $written): string
    {
        $depth = 0;

        for ($i = 0, $length = strlen($written); $i < $length; $i++) {
            $character = $written[$i];

            if ($character === '{' || $character === '<' || $character === '(') {
                $depth++;
            } elseif ($character === '}' || $character === '>' || $character === ')') {
                $depth--;
            } elseif ($depth === 0 && ($character === ' ' || $character === "\t")) {
                return substr($written, 0, $i);
            }
        }

        return $written;
    }

    private static function typeToString(?Node $type): ?string
    {
        return match (true) {
            $type instanceof Identifier => $type->toString(),
            // Already fully qualified: Ast::parse runs NameResolver.
            $type instanceof Name => '\\'.$type->toString(),
            $type instanceof NullableType => '?'.self::typeToString($type->type),
            $type instanceof UnionType => implode('|', array_filter(array_map(
                static fn (Node $inner): ?string => self::typeToString($inner),
                $type->types,
            ))),
            // An intersection is a set of interfaces, which says nothing about
            // the JSON shape.
            $type instanceof IntersectionType, $type instanceof ComplexType => null,
            default => null,
        };
    }

    /**
     * Splits on a separator that is not nested inside braces, angle brackets,
     * parentheses or quotes — the whole reason a regex will not do here.
     *
     * @return list<string>
     */
    private static function split(string $subject, string $separator, int $limit = 0): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $quote = null;

        for ($i = 0, $length = strlen($subject); $i < $length; $i++) {
            $character = $subject[$i];

            if ($quote !== null) {
                $buffer .= $character;

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;
                $buffer .= $character;

                continue;
            }

            if ($character === '{' || $character === '<' || $character === '(') {
                $depth++;
            } elseif ($character === '}' || $character === '>' || $character === ')') {
                $depth--;
            }

            $splitting = $depth === 0
                && $character === $separator
                && ($limit === 0 || count($parts) < $limit - 1);

            if ($splitting) {
                $parts[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $character;
        }

        $parts[] = $buffer;

        return $parts;
    }
}
