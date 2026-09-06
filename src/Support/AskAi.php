<?php

declare(strict_types=1);

namespace Lusen\Support;

/**
 * Links that hand a page to an assistant with the question already asked.
 *
 * People paste documentation into a model all day; this removes the paste. The
 * link carries a prompt naming the page's Markdown twin - the same file the
 * copy button hands over - so the model reads the source rather than a
 * screenshot of a rendered page.
 *
 * Both halves are configuration. Providers change their deep-link shape
 * without warning and new ones appear, and a docs site should not have to wait
 * for a release of this package to keep a button working or to point at an
 * assistant nobody here has heard of.
 *
 * Nothing renders without an absolute URL. The prompt asks a model to read an
 * address, and `/docs/endpoints/users-index.md` is not one it can reach.
 */
final class AskAi
{
    public const DEFAULT_PROMPT = 'Read {url} — the documentation for {subject} in the {title}. '
        .'Answer my questions using only what that page says, and tell me when it does not say.';

    /**
     * @param  array<string, mixed>|mixed  $config  the `ui.ask_ai` section
     * @return array<string, string> label => href
     */
    public static function links(mixed $config, ?string $url, string $subject, string $title): array
    {
        if ($url === null || $url === '' || ! is_array($config)) {
            return [];
        }

        $providers = $config['providers'] ?? [];

        if (! is_array($providers) || $providers === []) {
            return [];
        }

        $prompt = self::prompt($config, $url, $subject, $title);
        $links = [];

        foreach ($providers as $label => $template) {
            if (! is_string($label) || ! is_string($template) || ! str_contains($template, '{prompt}')) {
                continue;
            }

            $links[$label] = str_replace('{prompt}', rawurlencode($prompt), $template);
        }

        return $links;
    }

    /**
     * @param  array<mixed>  $config
     */
    private static function prompt(array $config, string $url, string $subject, string $title): string
    {
        $template = $config['prompt'] ?? '';

        if (! is_string($template) || $template === '') {
            $template = self::DEFAULT_PROMPT;
        }

        return strtr($template, [
            '{url}' => $url,
            '{subject}' => $subject,
            '{title}' => $title,
        ]);
    }
}
