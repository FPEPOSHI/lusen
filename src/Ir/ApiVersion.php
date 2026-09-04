<?php

declare(strict_types=1);

namespace Lusen\Ir;

use Lusen\Support\Data;

/**
 * One version of the API, spelled the way its URLs spell it.
 *
 * Not to be confused with `ApiSpec::$version`, which is the documentation's
 * own release number. They answer different questions: an API can serve `v1`
 * and `v2` side by side out of a single `2.4.1` build, and a reader on a `v1`
 * page needs to know that `v2` exists far more urgently than they need to know
 * which release of the docs they are reading.
 *
 * `current` and `deprecated` are the two facts an integrator acts on, so they
 * are carried here rather than worked out again by every emitter.
 */
final readonly class ApiVersion
{
    public function __construct(
        public string $name,
        public bool $current = false,
        public bool $deprecated = false,
        public ?string $sunset = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: Data::string($data, 'name'),
            current: Data::bool($data, 'current'),
            deprecated: Data::bool($data, 'deprecated'),
            sunset: Data::nullableString($data, 'sunset'),
        );
    }

    /**
     * One word for where this version stands. "Previous" rather than
     * "supported": that a version still answers requests is something this
     * package can see, but a promise to keep answering them is not.
     */
    public function status(): string
    {
        return match (true) {
            $this->deprecated => 'deprecated',
            $this->current => 'current',
            default => 'previous',
        };
    }

    public function label(): string
    {
        return $this->current && ! $this->deprecated
            ? $this->name
            : "{$this->name} ({$this->status()})";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'current' => $this->current ?: null,
            'deprecated' => $this->deprecated ?: null,
            'sunset' => $this->sunset,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
