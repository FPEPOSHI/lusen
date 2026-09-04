<?php

declare(strict_types=1);

namespace Lusen\Emit;

use Lusen\Emit\Contracts\Emitter;
use Lusen\Ir\ApiSpec;
use Lusen\Support\Links;

/**
 * sitemap.xml over every page the static site publishes.
 *
 * Two deliberate omissions.
 *
 * `lastmod` is left out unless a date is configured. The obvious choice -
 * "now" - is a lie that gets told on every build, and a sitemap whose
 * timestamps always change trains a crawler to ignore them. Source-file mtimes
 * would be honest but differ between a laptop and CI, which would break the
 * IR's deterministic-output guarantee. Omitting an optional field beats
 * emitting a wrong one.
 *
 * `priority` and `changefreq` are omitted because Google ignores both. Emitting
 * them only implies a precision the file does not have.
 */
final readonly class SitemapEmitter implements Emitter
{
    /**
     * @param  string|null  $lastmod  an explicit W3C date, when the project has
     *                                a real publication date to report
     */
    public function __construct(
        private Links $links,
        private ?string $lastmod = null,
    ) {}

    public function name(): string
    {
        return 'sitemap';
    }

    /**
     * @return list<EmittedFile>
     */
    public function emit(ApiSpec $spec): array
    {
        $urls = $this->urls($spec);

        // A sitemap needs absolute URLs, which needs a configured origin.
        // Without one there is nothing valid to write.
        if ($urls === []) {
            return [];
        }

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        foreach ($urls as $url) {
            $lines[] = '    <url>';
            $lines[] = '        <loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc>';

            if ($this->lastmod !== null) {
                $lines[] = '        <lastmod>'.$this->lastmod.'</lastmod>';
            }

            $lines[] = '    </url>';
        }

        $lines[] = '</urlset>';
        $lines[] = '';

        return [EmittedFile::xml('sitemap.xml', implode("\n", $lines))];
    }

    /**
     * Every canonical URL, index first, then prose, then reference - the same
     * order the sidebar uses.
     *
     * @return list<string>
     */
    public function urls(ApiSpec $spec): array
    {
        $urls = [];

        $index = $this->links->canonicalIndex();

        if ($index === null) {
            return [];
        }

        $urls[] = $index;

        foreach ($spec->pages() as $page) {
            $url = $this->links->canonicalPage($page);

            if ($url !== null) {
                $urls[] = $url;
            }
        }

        foreach ($spec->endpoints() as $endpoint) {
            $url = $this->links->canonicalEndpoint($endpoint);

            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return $urls;
    }
}
