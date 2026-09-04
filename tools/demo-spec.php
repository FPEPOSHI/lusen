<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Showcase fixture
|--------------------------------------------------------------------------
|
| A plausible commerce API, hand-built as an ApiSpec. This is what docs/index.html
| renders, so it exists to exercise the UI honestly: mixed verbs, authenticated
| and public endpoints, path/query/header/body parameters, enums, formats,
| constraints, several response codes, and a long group list that makes the
| sidebar work for its living.
|
| It serves two versions at once, which is the awkward case worth showing: v2
| current, v1 deprecated with a retirement date, most operations present in
| both, two that genuinely changed, one v2 dropped and several v2 added. The
| versions, the deprecations and the supersession links are derived by the
| package itself at the bottom of this file - the fixture states the endpoints
| and nothing else.
|
| It is a fixture, not a feature - `tools/` is export-ignored from the package.
|
*/

use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\HttpMethod;
use Lusen\Ir\Enums\ParameterLocation as In;
use Lusen\Ir\Example;
use Lusen\Ir\Group;
use Lusen\Ir\Parameter;
use Lusen\Ir\RateLimit;
use Lusen\Ir\Response;
use Lusen\Ir\Schema;
use Lusen\Support\Versions;

/**
 * @param  array<string, mixed>  $overrides
 */
$param = static fn (string $name, In $in, Schema $schema, bool $required = false, ?string $description = null): Parameter => new Parameter($name, $in, $schema, $required, $description);

$page = static fn (): array => [
    'per_page' => new Parameter('per_page', In::Query, Schema::integer()->withExample(25), false, 'Results per page, 1–100.'),
    'page' => new Parameter('page', In::Query, Schema::integer()->withExample(1), false, 'Page number, 1-indexed.'),
];

// ---------------------------------------------------------------- Authentication

$createToken = Endpoint::make(HttpMethod::Post, 'api/v2/auth/tokens', 'v2.auth.tokens.store')->with(
    summary: 'Issue an access token',
    description: 'Exchanges an API key pair for a short-lived bearer token. Tokens expire after one hour; request a new one rather than caching indefinitely.',
    group: 'Authentication',
    parameters: [
        $param('key_id', In::Body, Schema::string(), true, 'The public half of your API key pair.'),
        $param('key_secret', In::Body, Schema::string(), true, 'The secret half. Never send this from a browser.'),
        $param('scopes', In::Body, Schema::arrayOf(Schema::enum(['orders:read', 'orders:write', 'customers:read'])), false, 'Defaults to every scope the key is entitled to.'),
    ],
    responses: [
        new Response(201, 'A token you can use as a bearer credential.', examples: [
            new Example('Issued', [
                'access_token' => 'act_7f3a9c2e5b1d8406',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scopes' => ['orders:read', 'orders:write'],
            ]),
        ]),
        new Response(422, 'The key pair was rejected.', examples: [
            new Example('Rejected', ['message' => 'These credentials do not match our records.']),
        ]),
    ],
);

$revokeToken = Endpoint::make(HttpMethod::Delete, 'api/v2/auth/tokens/current', 'v2.auth.tokens.destroy')->with(
    summary: 'Revoke the current token',
    description: 'Invalidates the token used to make this call. Idempotent — revoking an already-revoked token still returns 204.',
    group: 'Authentication',
    responses: [new Response(204, 'Revoked. No body is returned.')],
    authenticated: true,
    rateLimit: new RateLimit(maxAttempts: 60),
);

// ---------------------------------------------------------------------- Customers

$customerShape = Schema::object([
    'id' => Schema::integer(),
    'email' => Schema::string('email'),
    'name' => Schema::string(),
    'status' => Schema::enum(['active', 'invited', 'archived']),
    'created_at' => Schema::string('date-time'),
]);

$listCustomers = Endpoint::make(HttpMethod::Get, 'api/v2/customers', 'v2.customers.index')->with(
    summary: 'List customers',
    description: 'Returns a paginated list of customers, newest first. Use `status` to narrow the list, and `q` to search across name and email.',
    group: 'Customers',
    parameters: [
        ...array_values($page()),
        $param('status', In::Query, Schema::enum(['active', 'invited', 'archived']), false, 'Only customers in this state.'),
        $param('q', In::Query, new Schema(constraints: ['maxLength' => 120]), false, 'Free-text search over name and email.'),
    ],
    responses: [
        new Response(200, 'A page of customers.', schema: Schema::object([
            'data' => Schema::arrayOf($customerShape),
        ]), examples: [
            new Example('First page', [
                'data' => [
                    ['id' => 1, 'email' => 'jane@example.com', 'name' => 'Jane Doe', 'status' => 'active', 'created_at' => '2026-01-15T09:30:00Z'],
                    ['id' => 2, 'email' => 'sam@example.com', 'name' => 'Sam Reyes', 'status' => 'invited', 'created_at' => '2026-01-14T16:02:11Z'],
                ],
                'meta' => ['page' => 1, 'per_page' => 25, 'total' => 2],
            ]),
        ]),
        new Response(401, 'Missing or expired bearer token.'),
    ],
    authenticated: true,
    rateLimit: new RateLimit(maxAttempts: 60),
);

$createCustomer = Endpoint::make(HttpMethod::Post, 'api/v2/customers', 'v2.customers.store')->with(
    summary: 'Create a customer',
    description: 'Creates a customer and, unless `send_invite` is false, emails them an invitation.',
    group: 'Customers',
    parameters: [
        $param('email', In::Body, Schema::string('email'), true, 'Must be unique across your account.'),
        $param('name', In::Body, new Schema(constraints: ['maxLength' => 255]), true, 'Display name.'),
        $param('send_invite', In::Body, Schema::boolean(), false, 'Defaults to true.'),
        $param('metadata', In::Body, Schema::object(['plan' => Schema::string()]), false, 'Arbitrary key/value pairs echoed back on reads.'),
    ],
    responses: [
        new Response(201, 'The created customer.', schema: $customerShape, examples: [
            new Example('Created', ['id' => 42, 'email' => 'jane@example.com', 'name' => 'Jane Doe', 'status' => 'invited', 'created_at' => '2026-02-01T11:00:00Z']),
        ]),
        new Response(422, 'Validation failed.', examples: [
            new Example('Duplicate email', [
                'message' => 'The email has already been taken.',
                'errors' => ['email' => ['The email has already been taken.']],
            ]),
        ]),
    ],
    authenticated: true,
);

$showCustomer = Endpoint::make(HttpMethod::Get, 'api/v2/customers/{customer}', 'v2.customers.show')->with(
    summary: 'Retrieve a customer',
    group: 'Customers',
    parameters: [
        $param('customer', In::Path, Schema::integer(), true, 'The customer id.'),
        $param('include', In::Query, Schema::enum(['orders', 'addresses']), false, 'Embed a related collection in the response.'),
    ],
    responses: [
        new Response(200, 'The customer.', schema: $customerShape),
        new Response(404, 'No customer with that id.'),
    ],
    authenticated: true,
);

$updateCustomer = Endpoint::make(HttpMethod::Patch, 'api/v2/customers/{customer}', 'v2.customers.update')->with(
    summary: 'Update a customer',
    description: 'Partial update — omitted fields are left untouched.',
    group: 'Customers',
    parameters: [
        $param('customer', In::Path, Schema::integer(), true, 'The customer id.'),
        $param('name', In::Body, new Schema(constraints: ['maxLength' => 255]), false),
        $param('status', In::Body, Schema::enum(['active', 'archived']), false, 'Archiving hides the customer from list endpoints.'),
    ],
    responses: [
        new Response(200, 'The updated customer.', schema: $customerShape),
        new Response(422, 'Validation failed.'),
    ],
    authenticated: true,
);

$deleteCustomer = Endpoint::make(HttpMethod::Delete, 'api/v2/customers/{customer}', 'v2.customers.destroy')->with(
    summary: 'Delete a customer',
    description: 'Permanently removes the customer and anonymises their orders. Prefer archiving via the update endpoint.',
    group: 'Customers',
    parameters: [$param('customer', In::Path, Schema::integer(), true, 'The customer id.')],
    responses: [
        new Response(204, 'Deleted.'),
        new Response(409, 'The customer has an open order and cannot be deleted.'),
    ],
    authenticated: true,
);

// ------------------------------------------------------------------------ Orders

$listOrders = Endpoint::make(HttpMethod::Get, 'api/v2/orders', 'v2.orders.index')->with(
    summary: 'List orders',
    group: 'Orders',
    parameters: [
        ...array_values($page()),
        $param('status', In::Query, Schema::enum(['pending', 'paid', 'shipped', 'refunded']), false, 'Only orders in this state.'),
        $param('customer_id', In::Query, Schema::integer(), false, 'Only orders belonging to this customer.'),
    ],
    responses: [new Response(200, 'A page of orders.', examples: [
        new Example('First page', [
            'data' => [[
                'id' => 8801,
                'customer_id' => 1,
                'status' => 'paid',
                'total' => 4200,
                'currency' => 'USD',
                'placed_at' => '2026-02-03T14:20:00Z',
            ]],
            'meta' => ['page' => 1, 'per_page' => 25, 'total' => 1],
        ]),
    ])],
    authenticated: true,
);

$createOrder = Endpoint::make(HttpMethod::Post, 'api/v2/orders', 'v2.orders.store')->with(
    summary: 'Create an order',
    description: 'Creates an order in `pending` state. Send the `Idempotency-Key` header so a retried request cannot double-charge.',
    group: 'Orders',
    parameters: [
        $param('Idempotency-Key', In::Header, Schema::string('uuid'), true, 'A unique key per logical order. Replays return the original order.'),
        $param('customer_id', In::Body, Schema::integer(), true, 'Who the order is for.'),
        $param('currency', In::Body, Schema::enum(['USD', 'EUR', 'GBP']), true),
        $param('items', In::Body, Schema::arrayOf(Schema::object([
            'product_id' => Schema::integer(),
            'quantity' => Schema::integer(),
        ])), true, 'At least one line item.'),
    ],
    responses: [
        new Response(201, 'The created order.', examples: [
            new Example('Created', [
                'id' => 8802,
                'status' => 'pending',
                'total' => 3998,
                'currency' => 'USD',
                'items' => [['product_id' => 12, 'quantity' => 2, 'unit_price' => 1999]],
            ]),
        ]),
        new Response(422, 'Validation failed.'),
        new Response(429, 'Too many orders created. Back off and retry after the interval in the Retry-After header.'),
    ],
    authenticated: true,
    rateLimit: new RateLimit(maxAttempts: 10, perMinutes: 1),
);

$orderShape = Schema::object([
    'id' => Schema::integer(),
    'customer_id' => Schema::integer(),
    'status' => Schema::enum(['pending', 'paid', 'shipped', 'refunded']),
    'total' => Schema::integer(),
    'currency' => Schema::string(),
    'items' => Schema::arrayOf(Schema::object([
        'product_id' => Schema::integer(),
        'quantity' => Schema::integer(),
        'unit_price' => Schema::integer(),
    ])),
    'placed_at' => Schema::string('date-time'),
    'refunded_at' => new Schema(format: 'date-time', nullable: true),
]);

$showOrder = Endpoint::make(HttpMethod::Get, 'api/v2/orders/{order}', 'v2.orders.show')->with(
    summary: 'Retrieve an order',
    group: 'Orders',
    parameters: [$param('order', In::Path, Schema::integer(), true, 'The order id.')],
    responses: [
        new Response(200, 'The order.', schema: Schema::object(['data' => $orderShape])),
        new Response(404, 'No order with that id.'),
    ],
    authenticated: true,
);

$refundOrder = Endpoint::make(HttpMethod::Post, 'api/v2/orders/{order}/refunds', 'v2.orders.refunds.store')->with(
    summary: 'Refund an order',
    description: 'Refunds all or part of a paid order. Partial refunds may be issued repeatedly up to the order total.',
    group: 'Orders',
    parameters: [
        $param('order', In::Path, Schema::integer(), true, 'The order id.'),
        $param('amount', In::Body, Schema::integer(), false, 'Minor units. Omit to refund the full remaining balance.'),
        $param('reason', In::Body, Schema::enum(['requested_by_customer', 'duplicate', 'fraudulent']), false),
    ],
    responses: [
        new Response(201, 'The refund.', examples: [
            new Example('Full refund', ['id' => 'rfnd_91a', 'order_id' => 8801, 'amount' => 4200, 'status' => 'succeeded']),
        ]),
        new Response(409, 'The order is not in a refundable state.'),
    ],
    authenticated: true,
);

// ---------------------------------------------------------------------- Products

$listProducts = Endpoint::make(HttpMethod::Get, 'api/v2/products', 'v2.products.index')->with(
    summary: 'List products',
    description: 'Public catalogue. No credentials required, so this endpoint is safe to call from a browser.',
    group: 'Products',
    parameters: [
        ...array_values($page()),
        $param('currency', In::Query, Schema::enum(['USD', 'EUR', 'GBP']), false, 'Prices are converted to this currency.'),
        $param('q', In::Query, new Schema(constraints: ['maxLength' => 120]), false, 'Free-text search over name and description. Replaces the v1 search endpoint.'),
    ],
    responses: [new Response(200, 'A page of products.', examples: [
        new Example('First page', [
            'data' => [['id' => 12, 'name' => 'Field Notebook', 'price' => 1999, 'currency' => 'USD', 'in_stock' => true]],
            'meta' => ['page' => 1, 'per_page' => 25, 'total' => 1],
        ]),
    ])],
    rateLimit: new RateLimit(maxAttempts: 300),
);

$showProduct = Endpoint::make(HttpMethod::Get, 'api/v2/products/{product}', 'v2.products.show')->with(
    summary: 'Retrieve a product',
    group: 'Products',
    parameters: [$param('product', In::Path, Schema::integer(), true, 'The product id.')],
    responses: [
        new Response(200, 'The product.'),
        new Response(404, 'No product with that id.'),
    ],
);

// ---------------------------------------------------------------------- Webhooks

$listWebhooks = Endpoint::make(HttpMethod::Get, 'api/v2/webhooks', 'v2.webhooks.index')->with(
    summary: 'List webhook endpoints',
    group: 'Webhooks',
    responses: [new Response(200, 'Your configured webhook endpoints.')],
    authenticated: true,
);

$createWebhook = Endpoint::make(HttpMethod::Post, 'api/v2/webhooks', 'v2.webhooks.store')->with(
    summary: 'Register a webhook endpoint',
    description: 'We POST a signed JSON payload to your URL for each subscribed event. Verify the `X-Acme-Signature` header before trusting a delivery.',
    group: 'Webhooks',
    parameters: [
        $param('url', In::Body, Schema::string('uri'), true, 'Must be HTTPS.'),
        $param('events', In::Body, Schema::arrayOf(Schema::enum(['order.paid', 'order.refunded', 'customer.created'])), true, 'At least one event.'),
    ],
    responses: [
        new Response(201, 'The registered endpoint, including the signing secret.', examples: [
            new Example('Registered', [
                'id' => 'whk_3f9',
                'url' => 'https://example.com/hooks/acme',
                'events' => ['order.paid'],
                'signing_secret' => 'whsec_5d4c3b2a1908',
            ]),
        ]),
        new Response(422, 'Validation failed.'),
    ],
    authenticated: true,
);

// -------------------------------------------------------------------- Version 1

/**
 * The v1 edition of an operation: the same documentation at the older path.
 *
 * Most endpoints do not change between versions - that is exactly the case
 * supersession exists to handle, and writing them out twice would only prove
 * that a fixture can copy and paste. The two that genuinely changed pass
 * overrides.
 */
$asV1 = static fn (Endpoint $endpoint, ?array $parameters = null, ?string $description = null): Endpoint => new Endpoint(
    id: 'v1.'.substr($endpoint->routeName ?? '', 3),
    method: $endpoint->method,
    uri: str_replace('api/v2/', 'api/v1/', $endpoint->uri),
    routeName: 'v1.'.substr($endpoint->routeName ?? '', 3),
    summary: $endpoint->summary,
    description: $description ?? $endpoint->description,
    group: $endpoint->group,
    parameters: $parameters ?? $endpoint->parameters,
    responses: $endpoint->responses,
    authenticated: $endpoint->authenticated,
    tags: $endpoint->tags,
    rateLimit: $endpoint->rateLimit,
);

// v1 had no free-text search on its list endpoints, which is why it had a
// search route of its own. v2 folded both into `q` and dropped the route.
$v1ListCustomers = $asV1($listCustomers, array_values(array_filter(
    $listCustomers->parameters,
    static fn (Parameter $parameter): bool => $parameter->name !== 'q',
)));

// Idempotency keys arrived in v2, which is the single most consequential
// difference between the two versions and the reason to move.
$v1CreateOrder = $asV1(
    $createOrder,
    array_values(array_filter(
        $createOrder->parameters,
        static fn (Parameter $parameter): bool => $parameter->name !== 'Idempotency-Key',
    )),
    'Creates an order in `pending` state. A retried request creates a second order; v2 accepts an `Idempotency-Key` header that makes the retry safe.',
);

$v1Search = Endpoint::make(HttpMethod::Get, 'api/v1/products/search', 'v1.products.search')->with(
    summary: 'Search products',
    description: 'Removed in v2, where the list endpoint takes a `q` parameter instead. Kept here for integrations that have not moved yet.',
    group: 'Products',
    parameters: [$param('term', In::Query, Schema::string(), true, 'The search term.')],
    responses: [new Response(200, 'Matching products.')],
);

// --------------------------------------------------------------------- Assembly

/*
 | The version, the deprecations and the supersession links are all worked out
 | by the package rather than written down here, so the showcase demonstrates
 | what `lusen:build` actually derives instead of a hand-drawn imitation of it.
 */

$endpoints = array_map(
    static fn (Endpoint $endpoint): Endpoint => $endpoint->with(version: Versions::fromUri($endpoint->uri)),
    [
        $createToken, $revokeToken,
        $listCustomers, $createCustomer, $showCustomer, $updateCustomer, $deleteCustomer,
        $listOrders, $createOrder, $showOrder, $refundOrder,
        $listProducts, $showProduct,
        $listWebhooks, $createWebhook,
        $asV1($createToken), $asV1($revokeToken),
        $v1ListCustomers, $asV1($createCustomer), $asV1($showCustomer),
        $asV1($listOrders), $v1CreateOrder,
        $asV1($listProducts), $v1Search,
    ],
);

$versions = Versions::catalogue($endpoints, ['deprecated' => ['v1' => '2026-09-01']]);

/** @var array<string, Endpoint> $byId */
$byId = [];

foreach (Versions::relate($endpoints, $versions) as $endpoint) {
    $byId[$endpoint->id] = $endpoint;
}

$in = static fn (string ...$ids): array => array_map(static fn (string $id): Endpoint => $byId[$id], $ids);

return new ApiSpec(
    title: 'Acme Commerce API',
    version: '2.4.1',
    groups: [
        new Group('Authentication', $in('v2.auth.tokens.store', 'v2.auth.tokens.destroy'), 'Exchange an API key pair for a bearer token, and revoke it when you are done.', version: 'v2'),
        new Group('Customers', $in('v2.customers.index', 'v2.customers.store', 'v2.customers.show', 'v2.customers.update', 'v2.customers.destroy'), 'Create and manage the people who place orders.', version: 'v2'),
        new Group('Orders', $in('v2.orders.index', 'v2.orders.store', 'v2.orders.show', 'v2.orders.refunds.store'), 'Place, read and refund orders.', version: 'v2'),
        new Group('Products', $in('v2.products.index', 'v2.products.show'), 'The public product catalogue.', version: 'v2'),
        new Group('Webhooks', $in('v2.webhooks.index', 'v2.webhooks.store'), 'Receive signed callbacks when things happen in your account.', version: 'v2'),

        new Group('Authentication', $in('v1.auth.tokens.store', 'v1.auth.tokens.destroy'), 'Unchanged in v2 apart from the path.', version: 'v1'),
        new Group('Customers', $in('v1.customers.index', 'v1.customers.store', 'v1.customers.show'), 'Reading and creating customers. Updating and deleting them arrived in v2.', version: 'v1'),
        new Group('Orders', $in('v1.orders.index', 'v1.orders.store'), 'Placing and reading orders. Refunds arrived in v2.', version: 'v1'),
        new Group('Products', $in('v1.products.index', 'v1.products.search'), 'The public product catalogue, with the search route v2 replaced.', version: 'v1'),
    ],
    description: 'Everything you need to sell: customers, orders, refunds, the product catalogue and webhooks. REST over HTTPS, JSON in and out, bearer-token authenticated.',
    baseUrl: 'https://api.acme.example',
    servers: ['Sandbox' => 'https://sandbox.acme.example'],
    versions: $versions,
);
