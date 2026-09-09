<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures;

use Lusen\Attributes\ApiResponse;
use Lusen\Tests\Fixtures\Schema\OrderShape;

/**
 * An API that answers with plain arrays and says so in its docblocks, which is
 * the shape of every codebase with no JsonResource to parse.
 */
final class ShapedController
{
    /**
     * List orders
     *
     * @response array{status: true, data: array{orders: list<OrderShape>, total: int}}
     */
    public function index(): void {}

    /**
     * Create an order
     *
     * @response array{status: true, data: OrderShape}
     * @response 422 array{status: false, message: string}
     */
    public function store(): void {}

    /**
     * Show an order
     *
     * The docblock loses to the attribute, which is the last word.
     *
     * @response array{never: string}
     */
    #[ApiResponse(200, 'The order.', example: ['id' => 1])]
    public function show(): void {}

    /**
     * Delete an order
     *
     * @response array{status: true}
     */
    public function destroy(): void {}

    /**
     * Refund an order
     *
     * Scribe's spelling, which a codebase arriving from that tool has on
     * every action: the body itself, as JSON, rather than a type.
     *
     * @response 200 {"status": true, "data": {"id": 7, "total": 12.5, "tags": ["refund"], "note": null}}
     * @response 404 {"message": "No query results for model [Order]."}
     */
    public function refund(): void {}
}
