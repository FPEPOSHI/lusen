<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures;

/**
 * Manage the people who place orders.
 *
 * @group Customers
 */
final class DocumentedController
{
    /**
     * List every customer.
     *
     * Returns a paginated list, newest first.
     * Use the status filter to narrow it.
     *
     * @param  int  $page
     */
    public function index(): void {}

    /**
     * Display a listing of the resource.
     */
    public function boilerplate(): void {}

    /**
     * Display the specified resource.
     *
     * Includes the customer's most recent orders.
     */
    public function boilerplateWithBody(): void {}

    /**
     * Archive a customer.
     *
     * @group Archival
     *
     * @deprecated
     */
    public function archive(): void {}

    /**
     * Look up a customer's public profile.
     *
     * @unauthenticated
     */
    public function publicProfile(): void {}

    /**
     * An internal endpoint.
     *
     * @ignore
     */
    public function internal(): void {}

    public function undocumented(): void {}
}
