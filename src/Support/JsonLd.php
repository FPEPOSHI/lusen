<?php

declare(strict_types=1);

namespace Lusen\Support;

use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Page;

/**
 * Structured data for the docs pages.
 *
 * Worth the effort for both goals at once: search engines use it to build
 * rich results, and retrieval models use it to work out what a page is
 * about without inferring from markup. `TechArticle` is the closest
 * schema.org type to an endpoint reference page.
 */
final class JsonLd
{
    public static function forSpec(ApiSpec $spec, string $docsUrl): string
    {
        return self::encode([
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $spec->title,
            'description' => $spec->description,
            'url' => $docsUrl,
            'version' => $spec->version,
        ]);
    }

    /**
     * Prose pages are Articles rather than TechArticles: an introduction or a
     * use case is explanatory writing, and the distinction is what a search
     * engine uses to decide which query a page answers.
     */
    public static function forPage(Page $page, ApiSpec $spec, string $docsUrl): string
    {
        $url = rtrim($docsUrl, '/').'/pages/'.$page->slug();

        return self::encode([
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $page->title,
            'description' => $page->summary(),
            'url' => $url,
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => $spec->title,
                'url' => $docsUrl,
            ],
            'breadcrumb' => [
                '@type' => 'BreadcrumbList',
                'itemListElement' => array_values(array_filter([
                    self::crumb(1, $spec->title, $docsUrl),
                    $page->section === null ? null : self::crumb(2, $page->section, $docsUrl),
                    self::crumb(3, $page->title, $url),
                ])),
            ],
        ]);
    }

    public static function forEndpoint(Endpoint $endpoint, ApiSpec $spec, string $docsUrl): string
    {
        $url = rtrim($docsUrl, '/').'/endpoints/'.$endpoint->slug();

        return self::encode([
            '@context' => 'https://schema.org',
            '@type' => 'TechArticle',
            'headline' => $endpoint->title(),
            'description' => Str::summarise($endpoint->description ?? $endpoint->title()),
            'url' => $url,
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => $spec->title,
                'url' => $docsUrl,
            ],
            'breadcrumb' => [
                '@type' => 'BreadcrumbList',
                'itemListElement' => array_values(array_filter([
                    self::crumb(1, $spec->title, $docsUrl),
                    $endpoint->group === null ? null : self::crumb(
                        2,
                        $endpoint->group,
                        rtrim($docsUrl, '/').'/#'.Str::slug($endpoint->group),
                    ),
                    self::crumb(3, $endpoint->title(), $url),
                ])),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function crumb(int $position, string $name, string $item): array
    {
        return [
            '@type' => 'ListItem',
            'position' => $position,
            'name' => $name,
            'item' => $item,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function encode(array $data): string
    {
        $filtered = array_filter($data, static fn (mixed $v): bool => $v !== null);

        return json_encode(
            $filtered,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_THROW_ON_ERROR,
        );
    }
}
