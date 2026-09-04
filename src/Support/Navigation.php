<?php

declare(strict_types=1);

namespace Lusen\Support;

use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Page;

/**
 * The documentation read as one ordered sequence.
 *
 * Prose sections come first, then the endpoint reference, which is the order a
 * newcomer needs: what this is, how to authenticate, then the operations.
 * Flattening it lets every page carry previous/next links, which is what turns
 * a pile of pages into something a person can read through once.
 */
final readonly class Navigation
{
    /**
     * @param  list<array{id: string, title: string, href: string, kind: string}>  $items
     */
    private function __construct(private array $items) {}

    public static function for(ApiSpec $spec, Links $links): self
    {
        $items = [];

        foreach ($spec->sections as $section) {
            foreach ($section->pages as $page) {
                $items[] = [
                    'id' => 'page:'.$page->id,
                    'title' => $page->title,
                    'href' => $links->page($page),
                    'kind' => 'page',
                ];
            }
        }

        foreach ($spec->groups as $group) {
            foreach ($group->endpoints as $endpoint) {
                $items[] = [
                    'id' => 'endpoint:'.$endpoint->id,
                    'title' => $endpoint->title(),
                    'href' => $links->endpoint($endpoint),
                    'kind' => 'endpoint',
                ];
            }
        }

        return new self($items);
    }

    /**
     * @return array{previous: array{title: string, href: string}|null, next: array{title: string, href: string}|null}
     */
    public function around(string $id): array
    {
        $index = null;

        foreach ($this->items as $position => $item) {
            if ($item['id'] === $id) {
                $index = $position;

                break;
            }
        }

        if ($index === null) {
            return ['previous' => null, 'next' => null];
        }

        return [
            'previous' => $this->at($index - 1),
            'next' => $this->at($index + 1),
        ];
    }

    /**
     * @return array{previous: array{title: string, href: string}|null, next: array{title: string, href: string}|null}
     */
    public function aroundPage(Page $page): array
    {
        return $this->around('page:'.$page->id);
    }

    /**
     * @return array{previous: array{title: string, href: string}|null, next: array{title: string, href: string}|null}
     */
    public function aroundEndpoint(Endpoint $endpoint): array
    {
        return $this->around('endpoint:'.$endpoint->id);
    }

    /**
     * @return array{title: string, href: string}|null
     */
    private function at(int $index): ?array
    {
        $item = $this->items[$index] ?? null;

        return $item === null ? null : ['title' => $item['title'], 'href' => $item['href']];
    }
}
