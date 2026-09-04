<?php

declare(strict_types=1);

namespace Lusen\Extract\Types;

use Lusen\Support\Ast;
use PhpParser\Node;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;

/**
 * Resolves a class name written in a docblock to the class it means.
 *
 * php-parser's NameResolver, which `Ast::parse()` already runs, resolves names
 * in *code*. A docblock is a comment: `@response array{...}|ApiError` gives us
 * the string "ApiError" and nothing else, so the file's `use` statements have
 * to be read back to learn it meant `App\Domain\Api\Schema\ApiError`.
 *
 * Resolution failing is normal and harmless — a name this cannot place becomes
 * an untyped field rather than a wrong one.
 */
final readonly class TypeNames
{
    /**
     * @param  array<string, string>  $imports  lowercased alias => fully-qualified name
     */
    public function __construct(
        private array $imports = [],
        private ?string $namespace = null,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    /**
     * The naming context of the file a class is declared in.
     */
    public static function forClass(string $class): self
    {
        /** @var array<string, self> $cache */
        static $cache = [];

        if (isset($cache[$class])) {
            return $cache[$class];
        }

        if (! class_exists($class) && ! interface_exists($class)) {
            return $cache[$class] = self::empty();
        }

        $file = Ast::fileFor($class);
        $ast = $file === null ? null : Ast::parse($file);

        if ($ast === null) {
            return $cache[$class] = self::empty();
        }

        return $cache[$class] = self::fromStatements($ast);
    }

    /**
     * The fully-qualified name, or null when nothing here can place it.
     */
    public function resolve(string $name): ?string
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        // Already absolute.
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        // An import, matched on the first segment so `Schema\ApiError` works
        // when only `Schema` was imported.
        $segments = explode('\\', $name);
        $first = strtolower($segments[0]);

        if (isset($this->imports[$first])) {
            $segments[0] = $this->imports[$first];

            return implode('\\', $segments);
        }

        if ($this->namespace !== null) {
            $candidate = $this->namespace.'\\'.$name;

            if (class_exists($candidate) || interface_exists($candidate)) {
                return $candidate;
            }
        }

        return class_exists($name) || interface_exists($name) ? $name : null;
    }

    /**
     * @param  array<Node>  $statements
     */
    private static function fromStatements(array $statements): self
    {
        $imports = [];
        $namespace = null;

        foreach ($statements as $statement) {
            if ($statement instanceof Namespace_) {
                $namespace = $statement->name?->toString();

                foreach ($statement->stmts as $inner) {
                    $imports = [...$imports, ...self::imports($inner)];
                }

                continue;
            }

            $imports = [...$imports, ...self::imports($statement)];
        }

        return new self($imports, $namespace);
    }

    /**
     * @return array<string, string>
     */
    private static function imports(Node $statement): array
    {
        $imports = [];

        if ($statement instanceof Use_) {
            foreach ($statement->uses as $use) {
                $imports[strtolower($use->getAlias()->toString())] = $use->name->toString();
            }
        }

        if ($statement instanceof GroupUse) {
            foreach ($statement->uses as $use) {
                $imports[strtolower($use->getAlias()->toString())] =
                    $statement->prefix->toString().'\\'.$use->name->toString();
            }
        }

        return $imports;
    }
}
