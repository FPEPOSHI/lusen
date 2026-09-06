<?php

declare(strict_types=1);

namespace Lusen\Diff;

/**
 * What a change costs the people already calling the API.
 *
 * Three levels rather than a boolean, because the useful CI gate is narrow. A
 * tool that fails the build on every difference gets switched off within a
 * week, and one that reports everything at the same volume gets skimmed. Only
 * `Breaking` is worth stopping a merge for; the rest is worth reading.
 */
enum Severity: string
{
    /** A client that worked against the baseline stops working. */
    case Breaking = 'breaking';

    /** New surface. Nothing that worked before stops working. */
    case Additive = 'additive';

    /**
     * Worth knowing, but not a contract change: a deprecation, a reworded
     * summary, or a field the extractors have only now managed to type.
     */
    case Notice = 'notice';

    public function label(): string
    {
        return match ($this) {
            self::Breaking => 'Breaking',
            self::Additive => 'Added',
            self::Notice => 'Notice',
        };
    }

    /**
     * Console colour. Breaking is the only one that should read as a problem.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Breaking => 'red',
            self::Additive => 'green',
            self::Notice => 'yellow',
        };
    }
}
