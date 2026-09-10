<?php

declare(strict_types=1);

namespace Lusen\Extract\Rules;

use BackedEnum;
use Lusen\Support\Ast;
use Lusen\Support\DocBlock;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use ReflectionMethod;
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
 * a failed build. What that costs is worth keeping small, though, because a
 * FormRequest the reader cannot follow is a request body that vanishes off the
 * page - so three shapes a real application writes constantly are followed
 * rather than given up on:
 *
 * - `return array_merge($this->sharedRules(), [...])`, and the spread that
 *   spells the same thing. A rules() that composes is still a rules().
 * - The method on the other side of that call, whether it arrives from a trait
 *   or a parent class. `parent::rules()` is how a base request shares a date
 *   range across nine reports.
 * - A rule string built by concatenation - `'in:'.implode(',', self::TYPES)`,
 *   `'max:'.self::LIMIT`. Constants are read without constructing anything,
 *   and a piece that cannot be read truncates that one rule instead of
 *   discarding the field.
 */
final class FormRequestReader
{
    /**
     * How far a rules() may delegate before this stops following. Nothing
     * legitimate nests this deep; a cycle the seen-list somehow misses does.
     */
    private const MAX_DEPTH = 8;

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

        $seen = [strtolower($class.'::rules') => true];

        $items = self::itemsIn($method, $class, $class, $seen, 0);

        $rules = [];

        foreach ($items as $item) {
            if (! $item->key instanceof String_) {
                // A computed key cannot be read statically; skip it rather
                // than inventing a field name.
                continue;
            }

            $value = self::ruleStrings($item->value, $class);

            if ($value !== []) {
                // A docblock above the rule is the only place in a
                // FormRequest where a field gets described in words. The
                // alternatives - attributes() and messages() - are labels and
                // error text, and in practice both are `__()` calls a static
                // reader cannot resolve anyway.
                $doc = DocBlock::parse($item->getDocComment()?->getText());

                $rules[$item->key->value] = new RuleSet(
                    $value,
                    documentation: self::sentence($doc),
                    example: self::example($doc),
                );
            }
        }

        return $rules;
    }

    /**
     * The whole docblock as one description.
     *
     * A field's docblock is not an endpoint's: there is no heading here, only
     * a description that happens to have been written in paragraphs, and the
     * first one is a sentence like the rest. DocBlock strips the full stop off
     * a summary because a page title carries none, so it goes back on before
     * the join, or two sentences meet as "never returns an error `price` is
     * still the full unit price".
     */
    private static function sentence(DocBlock $doc): ?string
    {
        $summary = $doc->summary;

        if ($summary !== '' && $doc->description !== '' && preg_match('/[.!?:]$/', $summary) !== 1) {
            $summary .= '.';
        }

        $written = trim($summary.' '.$doc->description);

        return $written === '' ? null : $written;
    }

    /**
     * `@example 2026-01-01`, typed the way it was written: an author who wrote
     * `25` meant a number, and quoting it in the example request would be
     * wrong.
     */
    private static function example(DocBlock $doc): mixed
    {
        $written = $doc->tag('example');

        if ($written === null || $written === '') {
            return null;
        }

        return match (true) {
            strtolower($written) === 'true' => true,
            strtolower($written) === 'false' => false,
            preg_match('/^-?\d+$/', $written) === 1 => (int) $written,
            preg_match('/^-?\d*\.\d+$/', $written) === 1 => (float) $written,
            default => trim($written, "'\""),
        };
    }

    /**
     * The class's own `rules()` where it writes one, and the one it inherits
     * where it does not.
     */
    private static function findRulesMethod(string $file, string $class): ?ClassMethod
    {
        $ast = Ast::parse($file);

        if ($ast !== null) {
            /** @var list<Class_> $classes */
            $classes = (new NodeFinder)->findInstanceOf($ast, Class_::class);

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
        }

        return Ast::declaredMethod($class, 'rules');
    }

    /**
     * Every entry the method returns, following the calls it composes its
     * answer from.
     *
     * @param  string  $object  the class `$this` refers to, which stays put no
     *                          matter whose file the rules are written in
     * @param  string  $declaring  the class the method being read belongs to,
     *                             which is what `parent::` is relative to
     * @param  array<string, true>  $seen
     * @return list<ArrayItem>
     */
    private static function itemsIn(ClassMethod $method, string $object, string $declaring, array &$seen, int $depth): array
    {
        foreach (Ast::returns($method) as $return) {
            $items = self::itemsFrom($return->expr, $object, $declaring, $seen, $depth);

            if ($items !== []) {
                return $items;
            }
        }

        return [];
    }

    /**
     * @param  array<string, true>  $seen
     * @return list<ArrayItem>
     */
    private static function itemsFrom(?Node $expr, string $object, string $declaring, array &$seen, int $depth): array
    {
        if ($expr instanceof Array_) {
            $items = [];

            foreach ($expr->items as $item) {
                if ($item->unpack) {
                    // `[...$this->sharedRules(), 'field' => ...]`.
                    $items = [...$items, ...self::itemsFrom($item->value, $object, $declaring, $seen, $depth)];

                    continue;
                }

                $items[] = $item;
            }

            return $items;
        }

        if ($expr instanceof FuncCall && self::isFunction($expr, ['array_merge', 'array_merge_recursive', 'array_replace'])) {
            $items = [];

            foreach ($expr->getArgs() as $argument) {
                $items = [...$items, ...self::itemsFrom($argument->value, $object, $declaring, $seen, $depth)];
            }

            return $items;
        }

        if ($expr instanceof Plus) {
            // `+` keeps the left-hand entry on a collision where array_merge
            // keeps the right-hand one, and the caller takes the last of a
            // repeated key - so the left goes last to win.
            return [
                ...self::itemsFrom($expr->right, $object, $declaring, $seen, $depth),
                ...self::itemsFrom($expr->left, $object, $declaring, $seen, $depth),
            ];
        }

        if ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall || $expr instanceof StaticCall) {
            return self::itemsFromCall($expr, $object, $declaring, $seen, $depth);
        }

        return [];
    }

    /**
     * @param  MethodCall|NullsafeMethodCall|StaticCall  $call
     * @param  array<string, true>  $seen
     * @return list<ArrayItem>
     */
    private static function itemsFromCall(Node $call, string $object, string $declaring, array &$seen, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }

        if (! $call->name instanceof Node\Identifier) {
            return [];
        }

        $name = $call->name->toString();
        $target = self::callTarget($call, $object, $declaring);

        if ($target === null) {
            return [];
        }

        $key = strtolower($target.'::'.$name);

        if (isset($seen[$key])) {
            return [];
        }

        $seen[$key] = true;

        $method = Ast::declaredMethod($target, $name);

        if ($method === null) {
            return [];
        }

        return self::itemsIn($method, $object, self::declaringClass($target, $name) ?? $target, $seen, $depth + 1);
    }

    /**
     * Which class the called method should be looked for on. Only calls whose
     * receiver is this request are followed - a call on some other object is a
     * collaborator whose return value cannot be read from here.
     *
     * @param  MethodCall|NullsafeMethodCall|StaticCall  $call
     */
    private static function callTarget(Node $call, string $object, string $declaring): ?string
    {
        if ($call instanceof StaticCall) {
            if (! $call->class instanceof Node\Name) {
                return null;
            }

            return match ($call->class->toLowerString()) {
                'parent' => get_parent_class($declaring) ?: null,
                'self', 'static' => $object,
                default => $call->class->toString(),
            };
        }

        $variable = $call->var ?? null;

        return $variable instanceof Variable && $variable->name === 'this' ? $object : null;
    }

    /**
     * A trait's method reports the using class, which is what `parent::` inside
     * it resolves against - so this is the right answer for both.
     */
    private static function declaringClass(string $class, string $method): ?string
    {
        try {
            return (new ReflectionMethod($class, $method))->getDeclaringClass()->getName();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $names
     */
    private static function isFunction(FuncCall $call, array $names): bool
    {
        return $call->name instanceof Node\Name
            && in_array(strtolower($call->name->toString()), $names, true);
    }

    /**
     * @return list<string>
     */
    private static function ruleStrings(Node $value, string $object): array
    {
        if (! $value instanceof Array_) {
            return self::ruleFromElement($value, $object);
        }

        $rules = [];

        foreach ($value->items as $item) {
            $rules = [...$rules, ...self::ruleFromElement($item->value, $object)];
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    private static function ruleFromElement(Node $node, string $object): array
    {
        if ($node instanceof StaticCall) {
            return self::ruleFromStaticCall($node, $object);
        }

        if ($node instanceof New_) {
            return self::ruleFromNew($node);
        }

        if ($node instanceof String_ || $node instanceof Concat) {
            return self::ruleFromText($node, $object);
        }

        // Rule objects and closures carry no statically readable constraint.
        return [];
    }

    /**
     * A pipe-separated rule string, which may have been built by concatenation.
     *
     * When part of it cannot be read, the rule that part belonged to goes with
     * it: `'required|string|max:'.self::LIMIT` with an unreadable LIMIT is
     * `required|string`, not a `max:` with nothing after the colon, which
     * `RuleSet` would read as a length of zero.
     *
     * @return list<string>
     */
    private static function ruleFromText(Node $node, string $object): array
    {
        [$text, $whole] = self::text($node, $object);

        if ($text === null) {
            return [];
        }

        $rules = array_map(trim(...), explode('|', $text));

        if (! $whole) {
            array_pop($rules);
        }

        return array_values(array_filter($rules, static fn (string $rule): bool => $rule !== ''));
    }

    /**
     * The literal text of an expression, and whether all of it could be read.
     *
     * @return array{0: string|null, 1: bool}
     */
    private static function text(Node $node, string $object): array
    {
        if ($node instanceof String_) {
            return [$node->value, true];
        }

        if ($node instanceof Concat) {
            [$left, $leftWhole] = self::text($node->left, $object);

            if ($left === null || ! $leftWhole) {
                return [$left, false];
            }

            [$right, $rightWhole] = self::text($node->right, $object);

            return $right === null ? [$left, false] : [$left.$right, $rightWhole];
        }

        $scalar = self::scalar($node, $object);

        return $scalar === null ? [null, false] : [$scalar, true];
    }

    /**
     * The scalar an expression evaluates to, where that can be known without
     * running anything: a literal, a class constant, or an `implode()` over
     * either. Those three cover how a rule string is built in practice.
     */
    private static function scalar(Node $node, string $object): ?string
    {
        if ($node instanceof Int_ || $node instanceof Float_) {
            return (string) $node->value;
        }

        if ($node instanceof ClassConstFetch) {
            return self::stringify(self::constantValue($node, $object));
        }

        if ($node instanceof FuncCall && self::isFunction($node, ['implode', 'join'])) {
            return self::implode($node, $object);
        }

        return null;
    }

    private static function implode(FuncCall $call, string $object): ?string
    {
        $arguments = $call->getArgs();

        if (count($arguments) !== 2) {
            return null;
        }

        [$glue, $whole] = self::text($arguments[0]->value, $object);

        if ($glue === null || ! $whole) {
            return null;
        }

        $values = self::valueList($arguments[1]->value, $object);

        return $values === null ? null : implode($glue, $values);
    }

    /**
     * @return list<string>|null
     */
    private static function valueList(Node $node, string $object): ?array
    {
        if ($node instanceof ClassConstFetch) {
            $constant = self::constantValue($node, $object);

            if (! is_array($constant)) {
                return null;
            }

            $values = [];

            foreach ($constant as $entry) {
                $value = self::stringify($entry);

                if ($value === null) {
                    return null;
                }

                $values[] = $value;
            }

            return $values;
        }

        if (! $node instanceof Array_) {
            return null;
        }

        $values = [];

        foreach ($node->items as $item) {
            $value = self::scalar($item->value, $object);

            if ($value === null && $item->value instanceof String_) {
                $value = $item->value->value;
            }

            if ($value === null) {
                return null;
            }

            $values[] = $value;
        }

        return $values;
    }

    /**
     * Reading a constant loads its class, which defines it without
     * constructing it - the same thing `enum_exists()` below already does.
     */
    private static function constantValue(ClassConstFetch $node, string $object): mixed
    {
        if (! $node->class instanceof Node\Name || ! $node->name instanceof Node\Identifier) {
            return null;
        }

        // `self` and `static` are left alone by the name resolver, because
        // only the object they are evaluated against can say what they mean -
        // and a rule reaching for `self::MAX` is the commonest way a limit
        // gets into a rule string at all.
        $class = match ($node->class->toLowerString()) {
            'self', 'static' => $object,
            'parent' => get_parent_class($object) ?: '',
            default => $node->class->toString(),
        };

        $constant = $node->name->toString();

        if (strtolower($constant) === 'class') {
            return $class;
        }

        if (! class_exists($class) && ! interface_exists($class) && ! enum_exists($class)) {
            return null;
        }

        return defined($class.'::'.$constant) ? constant($class.'::'.$constant) : null;
    }

    private static function stringify(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : null;
    }

    /**
     * Handles `Rule::in([...])` and `Rule::enum(Status::class)`, which are how
     * modern Laravel expresses the two rules that matter most to a schema.
     *
     * @return list<string>
     */
    private static function ruleFromStaticCall(StaticCall $call, string $object): array
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
            $values = self::literalList($arguments[0]->value, $object);

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
    private static function literalList(Node $node, string $object): array
    {
        if ($node instanceof String_) {
            return [$node->value];
        }

        if ($node instanceof ClassConstFetch) {
            return self::valueList($node, $object) ?? [];
        }

        if (! $node instanceof Array_) {
            return [];
        }

        $values = [];

        foreach ($node->items as $item) {
            if ($item->value instanceof String_) {
                $values[] = $item->value->value;
            } elseif ($item->value instanceof Int_) {
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
