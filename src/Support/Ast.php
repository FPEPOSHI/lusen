<?php

declare(strict_types=1);

namespace Lusen\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Parsing utilities shared by the extractors that read source rather than run
 * it.
 *
 * Every failure path here returns null rather than throwing. A file the parser
 * cannot read produces thinner documentation; it must never fail a build.
 *
 * Files are parsed once per process: a resource controller shares one
 * FormRequest across five actions, and a resource can be referenced from
 * dozens of endpoints.
 */
final class Ast
{
    /**
     * @var array<string, array<Node>|null>
     */
    private static array $files = [];

    /**
     * Files touched since recording began, or null when not recording.
     *
     * @var array<string, true>|null
     */
    private static ?array $recorded = null;

    public static function flushCache(): void
    {
        self::$files = [];
        self::$recorded = null;
    }

    /**
     * Starts noting which files get read.
     *
     * The incremental cache needs to know every file an endpoint's
     * documentation was derived from - controller, form request, resource,
     * any nested resource, the model, its migrations. Collecting that at the
     * one place files are actually opened is exact, where asking each
     * extractor to declare its own dependencies would drift the moment one
     * forgot.
     *
     * Cache hits are recorded too: a file read once for an earlier endpoint is
     * still a dependency of this one.
     */
    public static function beginRecording(): void
    {
        self::$recorded = [];
    }

    /**
     * @return list<string>
     */
    public static function endRecording(): array
    {
        $files = array_keys(self::$recorded ?? []);
        self::$recorded = null;

        sort($files);

        return $files;
    }

    /**
     * A named method on a class, located by parsing the file that declares it.
     *
     * @param  class-string  $class
     */
    public static function method(string $class, string $method): ?ClassMethod
    {
        $declaration = self::class_($class);

        if ($declaration === null) {
            return null;
        }

        foreach ($declaration->getMethods() as $candidate) {
            if ($candidate->name->toLowerString() === strtolower($method)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * A method as the class actually resolves it - inherited from a parent or
     * pulled in from a trait, not only the ones written in the class's own
     * file.
     *
     * `method()` looks in one file for one class node, which is the whole
     * answer for a class that writes what it declares. A FormRequest that
     * shares its rules through a trait or a base class declares nothing in its
     * own file, and reading only that file reports it as having no rules at
     * all. Reflection names the file the method really lives in without
     * constructing anything, which is the same bargain the extractors already
     * make to find a FormRequest from a method signature.
     */
    public static function declaredMethod(string $class, string $method): ?ClassMethod
    {
        if (! class_exists($class) && ! trait_exists($class)) {
            return null;
        }

        try {
            $reflection = new ReflectionMethod($class, $method);
        } catch (Throwable) {
            return null;
        }

        $file = $reflection->getFileName();

        if ($file === false || ! is_file($file)) {
            return null;
        }

        $ast = self::parse($file);

        if ($ast === null) {
            return null;
        }

        /** @var list<ClassMethod> $candidates */
        $candidates = (new NodeFinder)->findInstanceOf($ast, ClassMethod::class);

        $named = array_values(array_filter(
            $candidates,
            static fn (ClassMethod $candidate): bool => $candidate->name->toLowerString() === strtolower($method),
        ));

        if ($named === []) {
            return null;
        }

        // One file usually declares one class-like, so the name is enough.
        // Where it does not, the declaration line reflection reports picks the
        // right one out of the several that share a method name.
        $line = $reflection->getStartLine();

        foreach ($named as $candidate) {
            if ($line !== false && abs($candidate->getStartLine() - $line) <= 1) {
                return $candidate;
            }
        }

        return count($named) === 1 ? $named[0] : null;
    }

    /**
     * @param  class-string  $class
     */
    public static function class_(string $class): ?Class_
    {
        $file = self::fileFor($class);

        if ($file === null) {
            return null;
        }

        $ast = self::parse($file);

        if ($ast === null) {
            return null;
        }

        /** @var list<Class_> $classes */
        $classes = (new NodeFinder)->findInstanceOf($ast, Class_::class);

        foreach ($classes as $candidate) {
            if ($candidate->namespacedName?->toString() === ltrim($class, '\\')) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The first `return [...]` in a method, which is the shape these
     * extractors are always after.
     */
    public static function returnedArray(ClassMethod $method): ?Array_
    {
        foreach (self::returns($method) as $return) {
            if ($return->expr instanceof Array_) {
                return $return->expr;
            }
        }

        return null;
    }

    /**
     * @return list<Return_>
     */
    public static function returns(ClassMethod $method): array
    {
        /** @var list<Return_> $returns */
        $returns = (new NodeFinder)->findInstanceOf($method->stmts ?? [], Return_::class);

        return $returns;
    }

    /**
     * @param  class-string  $class
     */
    public static function fileFor(string $class): ?string
    {
        if (! class_exists($class)) {
            return null;
        }

        try {
            $file = (new ReflectionClass($class))->getFileName();
        } catch (Throwable) {
            return null;
        }

        return $file === false || ! is_file($file) ? null : $file;
    }

    /**
     * Names are resolved, so `Rule` becomes `Illuminate\Validation\Rule` and
     * `Status::class` its fully qualified form.
     *
     * @return array<Node>|null
     */
    public static function parse(string $file): ?array
    {
        if (self::$recorded !== null) {
            self::$recorded[$file] = true;
        }

        if (array_key_exists($file, self::$files)) {
            return self::$files[$file];
        }

        $code = file_get_contents($file);

        if ($code === false) {
            return self::$files[$file] = null;
        }

        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code);
        } catch (Throwable) {
            // An unparseable file is not a reason to fail the build.
            return self::$files[$file] = null;
        }

        if ($ast === null) {
            return self::$files[$file] = null;
        }

        return self::$files[$file] = (new NodeTraverser(new NameResolver))->traverse($ast);
    }
}
