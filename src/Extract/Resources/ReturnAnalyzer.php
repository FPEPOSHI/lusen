<?php

declare(strict_types=1);

namespace Lusen\Extract\Resources;

use Lusen\Ir\Schema;
use Lusen\Support\Ast;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Works out what a controller action returns.
 *
 * Two sources, and the statement wins. A return type of
 * `AnonymousResourceCollection` is true but useless - it names Laravel's
 * wrapper, not the resource inside it - so the return statement is read
 * first, and the declared type is only a fallback for the single-resource
 * case where it does name something concrete.
 */
final class ReturnAnalyzer
{
    public static function analyse(ReflectionMethod $action): ResourceReturn
    {
        $class = $action->getDeclaringClass()->getName();
        $method = Ast::method($class, $action->getName());

        $fromBody = $method === null ? new ResourceReturn : self::fromStatements($method, $class);

        if (! $fromBody->isEmpty()) {
            return $fromBody;
        }

        return self::fromReturnType($action);
    }

    /**
     * The action's main return, preferring one that sits directly in the
     * method body over one nested inside a conditional.
     *
     * A guard clause returns early - `if (! allowed) return $this->sendError(...)`
     * - and taking it would document the error envelope as the success
     * response. That is worse than documenting nothing, because a reader has
     * no way to tell it is wrong. Nested returns are still read when the
     * method has no plain one, since a body wrapped entirely in a `try` is
     * common and its return is the real one.
     */
    private static function fromStatements(ClassMethod $method, ?string $class = null, int $depth = 0): ResourceReturn
    {
        $locals = self::arrayAssignments($method);

        foreach ([self::plainReturns($method), Ast::returns($method)] as $candidates) {
            foreach ($candidates as $return) {
                if ($return->expr === null) {
                    continue;
                }

                $found = self::fromExpression($return->expr, $class, $locals, $depth);

                if (! $found->isEmpty()) {
                    return $found;
                }
            }
        }

        return new ResourceReturn;
    }

    /**
     * Returns that are statements of the method itself, not of a branch
     * inside it.
     *
     * @return list<Return_>
     */
    private static function plainReturns(ClassMethod $method): array
    {
        $returns = [];

        foreach ($method->stmts ?? [] as $statement) {
            if ($statement instanceof Return_) {
                $returns[] = $statement;
            }
        }

        return $returns;
    }

    /**
     * @param  array<string, Array_>  $locals  array literals assigned to a variable in this body
     */
    private static function fromExpression(Expr $expr, ?string $class, array $locals, int $depth): ResourceReturn
    {
        // UserResource::collection(...) / UserResource::make(...)
        if ($expr instanceof StaticCall) {
            return self::fromStaticCall($expr);
        }

        // new UserResource(...) / new UserCollection(...)
        if ($expr instanceof New_) {
            return self::fromNew($expr);
        }

        // response()->json([...], 201) / response()->noContent() /
        // $this->sendResponse([...])
        if ($expr instanceof MethodCall) {
            return self::fromMethodCall($expr, $class, $locals, $depth);
        }

        // A bare array literal returned straight from the action.
        if ($expr instanceof Array_) {
            return new ResourceReturn(literal: self::literal($expr));
        }

        return new ResourceReturn;
    }

    /**
     * `$response = ['status' => true, ...]; return response()->json($response);`
     * is common enough that not following it would miss the shape entirely.
     *
     * @return array<string, Array_>
     */
    private static function arrayAssignments(ClassMethod $method): array
    {
        $assignments = [];

        foreach ($method->stmts ?? [] as $statement) {
            if (! $statement instanceof Expression || ! $statement->expr instanceof Assign) {
                continue;
            }

            $assign = $statement->expr;

            if ($assign->var instanceof Variable
                && is_string($assign->var->name)
                && $assign->expr instanceof Array_) {
                $assignments[$assign->var->name] = $assign->expr;
            }
        }

        return $assignments;
    }

    private static function fromStaticCall(StaticCall $call): ResourceReturn
    {
        if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier) {
            return new ResourceReturn;
        }

        $class = $call->class->toString();

        if (! class_exists($class) || ! method_exists($class, 'toArray')) {
            return new ResourceReturn;
        }

        $method = $call->name->toLowerString();

        if (! in_array($method, ['collection', 'make'], true)) {
            return new ResourceReturn;
        }

        /** @var class-string $class */
        return new ResourceReturn(
            resource: $class,
            collection: $method === 'collection',
            paginated: $method === 'collection' && self::mentionsPagination($call->getArgs()),
        );
    }

    private static function fromNew(New_ $new): ResourceReturn
    {
        if (! $new->class instanceof Node\Name) {
            return new ResourceReturn;
        }

        $class = $new->class->toString();

        if (! class_exists($class) || ! method_exists($class, 'toArray')) {
            return new ResourceReturn;
        }

        // A ResourceCollection subclass wraps a list even when constructed
        // with `new`.
        $isCollection = is_subclass_of($class, 'Illuminate\Http\Resources\Json\ResourceCollection');

        /** @var class-string $class */
        return new ResourceReturn(
            resource: $class,
            collection: $isCollection,
            paginated: $isCollection && self::mentionsPagination($new->getArgs()),
        );
    }

    /**
     * @param  array<string, Array_>  $locals
     */
    private static function fromMethodCall(MethodCall $call, ?string $class, array $locals, int $depth): ResourceReturn
    {
        if (! $call->name instanceof Node\Identifier) {
            return new ResourceReturn;
        }

        $method = $call->name->toLowerString();
        $arguments = $call->getArgs();

        if ($method === 'nocontent') {
            return new ResourceReturn(status: 204);
        }

        if ($method !== 'json' || ! self::isResponseHelper($call->var)) {
            return $class === null ? new ResourceReturn : self::fromHelper($call, $class, $depth);
        }

        $body = $arguments[0]->value ?? null;

        $literal = match (true) {
            $body instanceof Array_ => self::literal($body),
            $body instanceof Variable && is_string($body->name) && isset($locals[$body->name]) => self::literal($locals[$body->name]),
            default => null,
        };

        $status = isset($arguments[1]) && $arguments[1]->value instanceof Int_
            ? $arguments[1]->value->value
            : null;

        return new ResourceReturn(literal: $literal, status: $status);
    }

    /**
     * `return $this->sendResponse($payload)` — a response helper on the
     * controller or one of its parents.
     *
     * A great many Laravel APIs answer through one of these rather than
     * through a resource, and the envelope it wraps every response in is real
     * documentation: a reader who does not know their payload arrives under
     * `data` will use the wrong field. It is read out of the helper rather
     * than declared in configuration, so it cannot contradict the code, and it
     * cannot double-wrap a shape somebody already wrote out in full - a
     * written `@response` wins long before this runs.
     */
    private static function fromHelper(MethodCall $call, string $class, int $depth): ResourceReturn
    {
        // One hop. A helper that calls a helper is a chain this has no
        // business unravelling.
        if ($depth > 0 || ! $call->var instanceof Variable || $call->var->name !== 'this') {
            return new ResourceReturn;
        }

        if (! $call->name instanceof Node\Identifier) {
            return new ResourceReturn;
        }

        $name = $call->name->toString();
        $owner = self::declaringClass($class, $name);

        if ($owner === null || ! class_exists($owner)) {
            return new ResourceReturn;
        }

        $helper = Ast::method($owner, $name);

        if ($helper === null) {
            return new ResourceReturn;
        }

        $envelope = self::fromStatements($helper, $owner, $depth + 1);

        if ($envelope->literal === null) {
            return $envelope;
        }

        $key = self::payloadKey($helper);
        $argument = $call->getArgs()[0]->value ?? null;

        // The envelope alone is worth documenting even when the payload it
        // carries is something this cannot see into.
        if ($key === null || ! $argument instanceof Array_) {
            return $envelope;
        }

        return new ResourceReturn(
            literal: self::replaceProperty($envelope->literal, $key, self::literal($argument)),
            status: $envelope->status,
        );
    }

    /**
     * Where the helper puts what it was handed: the key whose value is the
     * helper's own first parameter.
     */
    private static function payloadKey(ClassMethod $helper): ?string
    {
        $parameter = $helper->params[0]->var ?? null;

        if (! $parameter instanceof Variable || ! is_string($parameter->name)) {
            return null;
        }

        $locals = self::arrayAssignments($helper);

        foreach (Ast::returns($helper) as $return) {
            $expr = $return->expr;

            if ($expr instanceof MethodCall) {
                $expr = $expr->getArgs()[0]->value ?? null;
            }

            if ($expr instanceof Variable && is_string($expr->name)) {
                $expr = $locals[$expr->name] ?? null;
            }

            if (! $expr instanceof Array_) {
                continue;
            }

            foreach ($expr->items as $item) {
                if ($item->value instanceof Variable
                    && $item->value->name === $parameter->name
                    && $item->key instanceof Node\Scalar\String_) {
                    return $item->key->value;
                }
            }
        }

        return null;
    }

    private static function replaceProperty(Schema $schema, string $key, Schema $value): Schema
    {
        $properties = $schema->properties;

        if (! array_key_exists($key, $properties)) {
            return $schema;
        }

        $properties[$key] = $value;

        return Schema::object($properties, $schema->required);
    }

    /**
     * Which class in the hierarchy actually declares the helper, so the right
     * file is the one parsed - and the one the build cache watches.
     */
    private static function declaringClass(string $class, string $method): ?string
    {
        if (! method_exists($class, $method)) {
            return null;
        }

        try {
            return (new ReflectionMethod($class, $method))->getDeclaringClass()->getName();
        } catch (Throwable) {
            return null;
        }
    }

    private static function isResponseHelper(Expr $expr): bool
    {
        return $expr instanceof FuncCall
            && $expr->name instanceof Node\Name
            && $expr->name->toLowerString() === 'response';
    }

    /**
     * A resource's declared return type, used only when the body gave nothing
     * away. Laravel's collection wrappers are skipped because naming them
     * tells the reader nothing about the shape inside.
     */
    private static function fromReturnType(ReflectionMethod $action): ResourceReturn
    {
        $type = $action->getReturnType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return new ResourceReturn;
        }

        $class = $type->getName();

        if (! class_exists($class) || ! method_exists($class, 'toArray')) {
            return new ResourceReturn;
        }

        if (in_array($class, [
            'Illuminate\Http\Resources\Json\AnonymousResourceCollection',
            'Illuminate\Http\Resources\Json\ResourceCollection',
            'Illuminate\Http\Resources\Json\JsonResource',
        ], true)) {
            return new ResourceReturn;
        }

        /** @var class-string $class */
        return new ResourceReturn(
            resource: $class,
            collection: is_subclass_of($class, 'Illuminate\Http\Resources\Json\ResourceCollection'),
        );
    }

    /**
     * `->paginate()`, `->simplePaginate()` or `->cursorPaginate()` anywhere in
     * the arguments means the response carries pagination metadata.
     *
     * @param  array<Node\Arg>  $arguments
     */
    private static function mentionsPagination(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            $expr = $argument->value;

            while ($expr instanceof MethodCall) {
                if ($expr->name instanceof Node\Identifier
                    && str_contains($expr->name->toLowerString(), 'paginate')) {
                    return true;
                }

                $expr = $expr->var;
            }
        }

        return false;
    }

    private static function literal(Array_ $array): Schema
    {
        return ResourceReader::literalToSchema($array);
    }
}
