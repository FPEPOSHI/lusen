<?php

declare(strict_types=1);

namespace Lusen\Support;

use Lusen\Ir\Endpoint;
use Lusen\Ir\Group;
use Lusen\Ir\Page;
use Lusen\Ir\Section;

/**
 * Every URL the documentation refers to, in one place.
 *
 * Static output and the runtime renderer address pages differently - separate
 * files versus anchors on a single page - and the Blade views must not care
 * which. They ask this for an href and get the right one.
 *
 * The `.html`/`.md` symmetry is deliberate and load-bearing: an agent that has
 * an endpoint's HTML URL can reach its Markdown by swapping the extension,
 * with no discovery step and nothing to negotiate.
 */
final readonly class Links
{
    public function __construct(
        private string $docsUrl = '/docs',
        private bool $static = false,
        private ?string $canonicalOrigin = null,
    ) {}

    public function base(): string
    {
        return rtrim($this->docsUrl, '/');
    }

    public function index(): string
    {
        return $this->base().($this->static ? '/index.html' : '');
    }

    /**
     * A separate page in static output; an anchor on the index at runtime.
     */
    public function endpoint(Endpoint $endpoint): string
    {
        return $this->static
            ? $this->base().'/endpoints/'.$endpoint->slug().'.html'
            : '#'.$endpoint->slug();
    }

    public function page(Page $page): string
    {
        return $this->static
            ? $this->base().'/pages/'.$page->slug().'.html'
            : '#page-'.$page->slug();
    }

    public function pageMarkdown(Page $page): string
    {
        return $this->base().'/pages/'.$page->slug().'.md';
    }

    public function canonicalPage(Page $page): ?string
    {
        return $this->canonical(ltrim($this->base().'/pages/'.$page->slug().'.html', '/'));
    }

    public function section(Section $section): string
    {
        return $this->static
            ? $this->base().'/index.html#'.$section->slug()
            : '#'.$section->slug();
    }

    public function markdown(Endpoint $endpoint): string
    {
        return $this->base().'/endpoints/'.$endpoint->slug().'.md';
    }

    public function group(Group $group): string
    {
        return $this->static
            ? $this->base().'/index.html#'.$group->slug()
            : '#'.$group->slug();
    }

    /**
     * For callers that hold a slug rather than the Group itself, such as a
     * breadcrumb built from an endpoint's group name.
     */
    public function groupSlug(string $slug): string
    {
        return $this->static
            ? $this->base().'/index.html#'.$slug
            : '#'.$slug;
    }

    public function asset(string $file): string
    {
        return $this->base().'/assets/'.ltrim($file, '/');
    }

    public function searchIndex(): string
    {
        return $this->base().'/search-index.json';
    }

    public function openapi(): string
    {
        return $this->base().'/openapi.json';
    }

    public function llms(): string
    {
        return $this->base().'/llms.txt';
    }

    public function llmsFull(): string
    {
        return $this->base().'/llms-full.txt';
    }

    /**
     * Static output writes this relative to the docs root, since a package
     * cannot assume it owns the domain. A docs site served at the root gets
     * the conventional location for free.
     */
    public function discovery(): string
    {
        return $this->static
            ? $this->base().'/.well-known/api-docs'
            : '/.well-known/api-docs';
    }

    /**
     * Absolute URL for <link rel="canonical"> and the sitemap. Without a
     * configured origin there is no honest absolute form, so callers get null
     * and omit the tag rather than emitting a relative canonical, which search
     * engines treat as a mistake.
     */
    public function canonical(string $path): ?string
    {
        if ($this->canonicalOrigin === null || $this->canonicalOrigin === '') {
            return null;
        }

        $origin = rtrim($this->canonicalOrigin, '/');
        $path = '/'.ltrim($path, '/');

        // `canonical_origin` should be scheme and host, but it is natural to
        // paste a full base URL that already contains the docs path. Joining
        // blindly would emit https://host/docs/docs/... - a wrong canonical is
        // worse than none, and the mistake is invisible in a browser.
        $base = $this->base();

        if ($base !== '' && str_ends_with($origin, $base)) {
            $origin = substr($origin, 0, -strlen($base));
        }

        return rtrim($origin, '/').$path;
    }

    public function canonicalIndex(): ?string
    {
        return $this->canonical(ltrim($this->index(), '/'));
    }

    public function canonicalEndpoint(Endpoint $endpoint): ?string
    {
        return $this->canonical(ltrim($this->base().'/endpoints/'.$endpoint->slug().'.html', '/'));
    }

    public function isStatic(): bool
    {
        return $this->static;
    }
}
