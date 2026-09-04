<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Lusen\Tests\Fixtures\Resources\TeamResource;
use Lusen\Tests\Fixtures\Resources\UnwrappedResource;
use Lusen\Tests\Fixtures\Resources\UserResource;

final class ProfileController
{
    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(User::query()->paginate());
    }

    public function all(): AnonymousResourceCollection
    {
        return UserResource::collection(User::query()->get());
    }

    public function show(): UserResource
    {
        return new UserResource(User::query()->first());
    }

    public function store(): UserResource
    {
        return UserResource::make(User::query()->first());
    }

    public function bare(): UnwrappedResource
    {
        return new UnwrappedResource(User::query()->first());
    }

    public function literal(): JsonResponse
    {
        return response()->json(['status' => 'ok', 'queued' => 3], 202);
    }

    public function destroy(): JsonResponse
    {
        return response()->noContent();
    }

    public function team(): TeamResource
    {
        return new TeamResource(User::query()->first());
    }

    public function unknown(): JsonResponse
    {
        return response()->json(compact('anything'));
    }
}

/**
 * A stand-in for an Eloquent model, so the fixture reads like real controller
 * code without the suite needing a database.
 */
final class User
{
    public static function query(): self
    {
        return new self;
    }

    public function paginate(): self
    {
        return $this;
    }

    public function get(): self
    {
        return $this;
    }

    public function first(): self
    {
        return $this;
    }
}
