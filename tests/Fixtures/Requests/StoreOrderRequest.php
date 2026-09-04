<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Exercises every rule shape the reader is expected to survive: both string
 * and array spellings, dot and wildcard nesting, Rule::in, Rule::enum, and a
 * closure it must skip without complaint.
 */
final class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /**
             * Where to deliver the order.
             *
             * @example DEPOT-7
             */
            'depot' => 'required|string|max:32',
            /**
             * How many crates, at most twelve.
             *
             * @example 3
             */
            'crates' => 'required|integer|max:12',
            'email' => 'required|email|max:255',
            'reference' => ['nullable', 'string', 'size:8'],
            'quantity' => 'required|integer|between:1,99',
            'price' => ['required', 'numeric', 'min:0.01'],
            'gift' => 'boolean',
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'channel' => ['required', Rule::in(['web', 'pos'])],
            'currency' => 'required|in:USD,EUR,GBP',
            'notes' => ['sometimes', 'string', 'max:500'],
            'coupon' => ['nullable', 'string', 'regex:/^[A-Z0-9]+$/'],
            'customer' => 'required|array',
            'customer.name' => 'required|string|max:120',
            'customer.vip' => 'nullable|boolean',
            'items' => 'required|array|min:1|max:20',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:30',
            'legacy_field' => 'prohibited',
            'callback' => [static fn (): bool => true],
            'website' => 'nullable|url',
            'ships_at' => 'nullable|date',
        ];
    }
}
