<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures;

use Lusen\Tests\Fixtures\Requests\DynamicRulesRequest;
use Lusen\Tests\Fixtures\Requests\IndexOrderRequest;
use Lusen\Tests\Fixtures\Requests\StoreOrderRequest;
use Lusen\Tests\Fixtures\Requests\UploadAvatarRequest;

final class OrderController
{
    public function index(IndexOrderRequest $request): void {}

    public function store(StoreOrderRequest $request): void {}

    public function update(int $order, StoreOrderRequest $request): void {}

    public function dynamic(DynamicRulesRequest $request): void {}

    public function bare(): void {}

    public function upload(UploadAvatarRequest $request): void {}
}
