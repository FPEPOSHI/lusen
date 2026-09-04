<?php

declare(strict_types=1);

namespace Lusen\Extract\Rules;

use BackedEnum;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Throwable;
use UnitEnum;

/**
 * Reads the rules array out of a FormRequest by parsing it.
 *
 * Deliberately not `(new $request)->rules()`. A real FormRequest's rules()
 * reaches for the route, the authenticated user and `Rule::unique()`, so
 * calling it would mean booting the application and opening a database
 * connection - which a docs build in CI cannot do and must not need.
 *
 * The cost is that only statically-expressible rules are seen. That is the
 * right trade: a rule this cannot read produces slightly thinner docs, never
 * a failed build.
 */
final class FormRequestReader
{
    /**
     * Parsing the same request class once per route would be wasteful on a
     * resource controller, where five actions share one FormRequest.
     *
     * @var array<string, array<string, RuleSet>>
     */
    private static array $cache = [];

    /**
     * @return array<string, RuleSet> dot-notated field path => rules
     */
    public static function read(string $file, string $class): array
    {
        $key = $class.'@'.$file;

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        return self::$cache[$key] = self::extract($file, $class);
    }

    public static function flushCache(): void
    {
        self::$cache = [];
    }

    /**
     * @return array<string, RuleSet>
     */
    private static function extract(string $file, string $class): array
    {
        $method = self::findRulesMethod($file, $class);

        if ($method === null) {
            return [];
        }

        $array = self::findReturnedArray($method);

        if ($array === null) {
            return [];
        }

        $rules = [];

        foreach ($array->items as $item) {
            if (! $item->key instanceof String_) {
                // A computed key cannot be read statically; skip it rather
                // than inventing a field name.
                continue;
            }

            $value = self::ruleStrings($item->value);

            if ($value !== []) {
                $rules[$item->key->value] = new RuleSet($value);
            }
        }

        return $rules;
    }

    private static function findRulesMethod(string $file, string $class): ?ClassMethod
    {
        if (! is_file($file)) {
            return null;
        }

        $code = file_get_contents($file);

        if ($code === false) {
            return null;
        }

        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code);
        } catch (Throwable) {
            // A file we cannot parse is not a reason to fail the build.
            return null;
        }

        if ($ast === null) {
            return null;
        }

        // Resolves `Rule` to `Illuminate\Validation\Rule` and `Status::class`
        // to its fully qualified name.
        $traverser = new NodeTraverser(new NameResolver);
        $ast = $traverser->traverse($ast);

        $finder = new NodeFinder;

        /** @var list<Class_> $classes */
        $classes = $finder->findInstanceOf($ast, Class_::class);

        foreach ($classes as $candidate) {
            if ($candidate->namespacedName?->toString() !== ltrim($class, '\\')) {
                continue;
            }

            foreach ($candidate->getMethods() as $method) {
                if ($method->name->toLowerString() === 'rules') {
                    return $method;
                }
            }
        }

        return null;
    }

    private static function findReturnedArray(ClassMethod $method): ?Array_
    {
        /** @var list<Return_> $returns */
        $returns = (new NodeFinder)->findInstanceOf($method->stmts ?? [], Return_::class);

        foreach ($returns as $return) {
            if ($return->expr instanceof Array_) {
                return $return->expr;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function ruleStrings(Node $value): array
    {
        if ($value instanceof String_) {
            return array_values(array_filter(
                array_map(trim(...), explode('|', $value->value)),
                static fn (string $rule): bool => $rule !== '',
            ));
        }

        if (! $value instanceof Array_) {
            return [];
        }

        $rules = [];

        foreach ($value->items as $item) {
            $rules = [...$rules, ...self::ruleFromElement($item->value)];
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    private static function ruleFromElement(Node $node): array
    {
        if ($node instanceof String_) {
            return array_values(array_filter(
                array_map(trim(...), explode('|', $node->value)),
                static fn (string $rule): bool => $rule !== '',
            ));
        }

        if ($node instanceof StaticCall) {
            return self::ruleFromStaticCall($node);
        }

        if ($node instanceof New_) {
            return self::ruleFromNew($node);
        }

        // Rule objects and closures carry no statically readable constraint.
        return [];
    }

    /**
     * Handles `Rule::in([...])` and `Rule::enum(Status::class)`, which are how
     * modern Laravel expresses the two rules that matter most to a schema.
     *
     * @return list<string>
     */
    private static function ruleFromStaticCall(StaticCall $call): array
    {
        if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier) {
            return [];
        }

        $method = $call->name->toLowerString();
        $arguments = $call->getArgs();

        if ($arguments === []) {
            return [];
        }

        if ($method === 'in') {
            $values = self::literalList($arguments[0]->value);

            return $values === [] ? [] : ['in:'.implode(',', $values)];
        }

        if ($method === 'enum') {
            return self::enumRule($arguments[0]->value);
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private static function ruleFromNew(New_ $new): array
    {
        if (! $new->class instanceof Node\Name) {
            return [];
        }

        if (! str_ends_with($new->class->toLowerString(), 'rules\\enum')) {
            return [];
        }

        $arguments = $new->getArgs();

        return $arguments === [] ? [] : self::enumRule($arguments[0]->value);
    }

    /**
     * A backed enum's cases are the value set. Reflection is safe here: it
     * reads the class without constructing anything.
     *
     * @return list<string>
     */
    private static function enumRule(Node $node): array
    {
        if (! $node instanceof ClassConstFetch || ! $node->class instanceof Node\Name) {
            return [];
        }

        $enum = $node->class->toString();

        if (! enum_exists($enum)) {
            return [];
        }

        $values = [];

        foreach ($enum::cases() as $case) {
            $values[] = $case instanceof BackedEnum ? (string) $case->value : $case->name;
        }

        return $values === [] ? [] : ['in:'.implode(',', $values)];
    }

    /**
     * @return list<string>
     */
    private static function literalList(Node $node): array
    {
        if ($node instanceof String_) {
            return [$node->value];
        }

        if (! $node instanceof Array_) {
            return [];
        }

        $values = [];

        foreach ($node->items as $item) {
            if ($item->value instanceof String_) {
                $values[] = $item->value->value;
            } elseif ($item->value instanceof Node\Scalar\Int_) {
                $values[] = (string) $item->value->value;
            } elseif ($item->value instanceof ClassConstFetch
                && $item->value->name instanceof Node\Identifier
                && $item->value->name->toLowerString() !== 'class') {
                // An enum case used as a value, e.g. Status::Active.
                $values[] = self::enumCaseValue($item->value);
            }
        }

        return array_values(array_filter($values, static fn (string $v): bool => $v !== ''));
    }

    private static function enumCaseValue(ClassConstFetch $fetch): string
    {
        if (! $fetch->class instanceof Node\Name || ! $fetch->name instanceof Node\Identifier) {
            return '';
        }

        $enum = $fetch->class->toString();
        $case = $fetch->name->toString();

        if (! enum_exists($enum) || ! defined("{$enum}::{$case}")) {
            return '';
        }

        $instance = constant("{$enum}::{$case}");

        if ($instance instanceof BackedEnum) {
            return (string) $instance->value;
        }

        return $instance instanceof UnitEnum ? $instance->name : '';
    }
}
