<?php

declare(strict_types=1);

namespace Lusen\Extract\Resources;

use Lusen\Ir\Schema;
use Lusen\Support\Ast;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\ClassMethod;
use ReflectionMethod;
use ReflectionNamedType;

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
        $method = Ast::method($action->getDeclaringClass()->getName(), $action->getName());

        $fromBody = $method === null ? new ResourceReturn : self::fromStatements($method);

        if (! $fromBody->isEmpty()) {
            return $fromBody;
        }

        return self::fromReturnType($action);
    }

    private static function fromStatements(ClassMethod $method): ResourceReturn
    {
        foreach (Ast::returns($method) as $return) {
            if ($return->expr === null) {
                continue;
            }

            $found = self::fromExpression($return->expr);

            if (! $found->isEmpty()) {
                return $found;
            }
        }

        return new ResourceReturn;
    }

    private static function fromExpression(Expr $expr): ResourceReturn
    {
        // UserResource::collection(...) / UserResource::make(...)
        if ($expr instanceof StaticCall) {
            return self::fromStaticCall($expr);
        }

        // new UserResource(...) / new UserCollection(...)
        if ($expr instanceof New_) {
            return self::fromNew($expr);
        }

        // response()->json([...], 201) / response()->noContent()
        if ($expr instanceof MethodCall) {
            return self::fromMethodCall($expr);
        }

        // A bare array literal returned straight from the action.
        if ($expr instanceof Array_) {
            return new ResourceReturn(literal: self::literal($expr));
        }

        return new ResourceReturn;
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

    private static function fromMethodCall(MethodCall $call): ResourceReturn
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
            return new ResourceReturn;
        }

        $literal = isset($arguments[0]) && $arguments[0]->value instanceof Array_
            ? self::literal($arguments[0]->value)
            : null;

        $status = isset($arguments[1]) && $arguments[1]->value instanceof Int_
            ? $arguments[1]->value->value
            : null;

        return new ResourceReturn(literal: $literal, status: $status);
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
