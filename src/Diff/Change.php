<?php

declare(strict_types=1);

namespace Lusen\Diff;

/**
 * One difference between two builds of the same API.
 *
 * `kind` is the stable, machine-readable handle - a script filtering for
 * `parameter.required` must keep working when the sentence in `detail` is
 * reworded. `detail` is the sentence a person reads, and it names the thing
 * that changed rather than describing the category it fell into.
 */
final readonly class Change
{
    public function __construct(
        public Severity $severity,
        public string $kind,
        public string $subject,
        public string $detail,
    ) {}

    public static function breaking(string $kind, string $subject, string $detail): self
    {
        return new self(Severity::Breaking, $kind, $subject, $detail);
    }

    public static function additive(string $kind, string $subject, string $detail): self
    {
        return new self(Severity::Additive, $kind, $subject, $detail);
    }

    public static function notice(string $kind, string $subject, string $detail): self
    {
        return new self(Severity::Notice, $kind, $subject, $detail);
    }

    public function isBreaking(): bool
    {
        return $this->severity === Severity::Breaking;
    }

    /**
     * @return array{severity: string, kind: string, subject: string, detail: string}
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity->value,
            'kind' => $this->kind,
            'subject' => $this->subject,
            'detail' => $this->detail,
        ];
    }
}
