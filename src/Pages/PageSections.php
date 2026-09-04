<?php

declare(strict_types=1);

namespace Lusen\Pages;

use Lusen\Ir\ApiSpec;
use Lusen\Ir\Page;
use Lusen\Ir\Section;

/**
 * Groups pages into ordered sidebar sections.
 *
 * Extracted from SpecBuilder so anything holding a spec and a list of pages -
 * the builder, a test, the showcase script - buckets them identically.
 */
final class PageSections
{
    /**
     * @param  list<Page>  $authored
     * @param  list<string>  $order  section names, in sidebar order
     * @return list<Section>
     */
    public static function build(
        ApiSpec $spec,
        array $authored,
        array $order = [DefaultPages::SECTION, 'Guides'],
        bool $generate = true,
    ): array {
        $pages = $generate
            ? [...$authored, ...DefaultPages::fill($spec, $authored)]
            : $authored;

        if ($pages === []) {
            return [];
        }

        /** @var array<string, list<Page>> $buckets */
        $buckets = [];

        foreach ($pages as $page) {
            $buckets[$page->section ?? DefaultPages::SECTION][] = $page;
        }

        foreach ($buckets as $name => $bucket) {
            usort($bucket, static fn (Page $a, Page $b): int => [$a->order, $a->title] <=> [$b->order, $b->title]);

            $buckets[$name] = $bucket;
        }

        // Configured order wins; anything unlisted follows alphabetically, so
        // adding a section never silently reshuffles the sidebar.
        uksort($buckets, static function (string $a, string $b) use ($order): int {
            $rankA = array_search($a, $order, true);
            $rankB = array_search($b, $order, true);

            if ($rankA !== false && $rankB !== false) {
                return $rankA <=> $rankB;
            }

            if ($rankA !== false) {
                return -1;
            }

            if ($rankB !== false) {
                return 1;
            }

            return $a <=> $b;
        });

        $sections = [];

        foreach ($buckets as $name => $bucket) {
            $sections[] = new Section(name: (string) $name, pages: $bucket);
        }

        return $sections;
    }
}
