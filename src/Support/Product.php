<?php

declare(strict_types=1);

namespace Lusen\Support;

/**
 * The places the documentation points back at the product it documents.
 *
 * API documentation is often the most-read thing a company publishes and the
 * only page a technical evaluator will look at before deciding, and until now
 * it was a dead end: no way back to the site, and nothing to press when
 * somebody finished reading and wanted an account. Three things fix that, and
 * all three are off until configured - a docs site that grew a signup banner
 * because it was upgraded would be a nasty surprise.
 *
 * Deliberately **HTML only**. None of this reaches `llms.txt`, the Markdown
 * mirrors or the OpenAPI document. Those exist so a model can learn how the
 * API works, and a call to action retrieved as though it were part of the
 * reference is noise in someone's context window at best and a fabricated
 * requirement at worst. The machine surfaces stay documentation.
 *
 * Every reader is validated the same way: a link needs both a label and a
 * destination, and anything half-configured renders nothing rather than an
 * empty button.
 */
final class Product
{
    /**
     * A strip across the top of every page.
     *
     * @param  array<string, mixed>|mixed  $config  the `product` section
     * @return array{text: string, label: string|null, url: string|null, dismissible: bool}|null
     */
    public static function banner(mixed $config): ?array
    {
        $banner = self::section($config, 'banner');
        $text = self::text($banner, 'text');

        if ($text === null) {
            return null;
        }

        $link = self::link($banner, 'label', 'url');

        return [
            'text' => $text,
            'label' => $link['label'] ?? null,
            'url' => $link['url'] ?? null,
            // On by default: a banner nobody can put away is one every reader
            // scrolls past on every page for the rest of the release.
            'dismissible' => ($banner['dismissible'] ?? true) !== false,
        ];
    }

    /**
     * The button that turns a reader into a user, shown where a page ends.
     *
     * @param  array<string, mixed>|mixed  $config
     * @return array{label: string, url: string, note: string|null}|null
     */
    public static function action(mixed $config): ?array
    {
        $action = self::section($config, 'action');
        $link = self::link($action, 'label', 'url');

        if ($link === []) {
            return null;
        }

        return [
            'label' => $link['label'],
            'url' => $link['url'],
            'note' => self::text($action, 'note'),
        ];
    }

    /**
     * The site these docs belong to.
     *
     * The label falls back to the host, because a link back to the product
     * still has to say where it goes, and "Website" tells a reader nothing
     * they could not already guess.
     *
     * @param  array<string, mixed>|mixed  $config
     * @return array{name: string, url: string}|null
     */
    public static function site(mixed $config): ?array
    {
        $section = is_array($config) ? $config : [];
        $url = self::text($section, 'url');

        if ($url === null) {
            return null;
        }

        $name = self::text($section, 'name');

        return ['name' => $name ?? self::host($url), 'url' => $url];
    }

    /**
     * Whether anything here is configured at all, so a view can skip the lot.
     *
     * @param  array<string, mixed>|mixed  $config
     */
    public static function any(mixed $config): bool
    {
        return self::banner($config) !== null
            || self::action($config) !== null
            || self::site($config) !== null;
    }

    /**
     * Config is whatever the application put there, so it is read as
     * `array<mixed>` and every value is checked on the way out.
     *
     * @param  array<string, mixed>|mixed  $config
     * @return array<mixed>
     */
    private static function section(mixed $config, string $key): array
    {
        if (! is_array($config) || ! isset($config[$key]) || ! is_array($config[$key])) {
            return [];
        }

        return $config[$key];
    }

    /**
     * Both halves or neither: a button with no destination is a button that
     * does nothing, and one with no label is a button nobody can read.
     *
     * @param  array<mixed>  $section
     * @return array{label: string, url: string}|array{}
     */
    private static function link(array $section, string $labelKey, string $urlKey): array
    {
        $label = self::text($section, $labelKey);
        $url = self::text($section, $urlKey);

        return $label === null || $url === null ? [] : ['label' => $label, 'url' => $url];
    }

    /**
     * @param  array<mixed>  $section
     */
    private static function text(array $section, string $key): ?string
    {
        $value = $section[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? preg_replace('/^www\./', '', $host) ?? $host : $url;
    }
}
