<?php

declare(strict_types=1);

namespace Lusen\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Renders a prose page and reports its headings.
 *
 * Anchors are generated here rather than by CommonMark's permalink extension
 * because they are a stability contract, like endpoint ids: a heading's anchor
 * is what a search result deep-links to and what a model cites. It must depend
 * only on the heading text, and it must not change because a page was
 * reordered or an extension was upgraded.
 */
final class MarkdownDocument
{
    /**
     * @param  list<array{level: int, text: string, id: string}>  $headings
     */
    private function __construct(
        public readonly string $html,
        public readonly array $headings,
    ) {}

    public static function render(string $markdown): self
    {
        $environment = new Environment([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);

        $html = (new MarkdownConverter($environment))->convert($markdown)->getContent();

        [$html, $headings] = self::anchorHeadings($html);

        return new self(self::highlightCode($html), $headings);
    }

    /**
     * Headings worth putting in an on-page table of contents. h1 is the page
     * title and h4 or deeper is noise in a sidebar.
     *
     * @return list<array{level: int, text: string, id: string}>
     */
    public function contents(): array
    {
        return array_values(array_filter(
            $this->headings,
            static fn (array $heading): bool => $heading['level'] === 2 || $heading['level'] === 3,
        ));
    }

    /**
     * Runs fenced code through the same build-time highlighter the endpoint
     * pages use.
     *
     * Without this a curl block in a guide renders plain while the identical
     * block on an endpoint page is coloured, which reads as two different
     * products rather than one.
     */
    private static function highlightCode(string $html): string
    {
        $result = preg_replace_callback(
            '#<pre><code class="language-([\w-]+)">(.*?)</code></pre>#s',
            static function (array $match): string {
                // CommonMark has already escaped the body; the highlighter
                // escapes its own output, so decode before handing it over.
                $code = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return '<pre><code class="language-'.$match[1].'">'
                    .Highlighter::highlight($code, $match[1])
                    .'</code></pre>';
            },
            $html,
        );

        return $result ?? $html;
    }

    /**
     * Adds an id to every heading and collects them in document order.
     *
     * @return array{0: string, 1: list<array{level: int, text: string, id: string}>}
     */
    private static function anchorHeadings(string $html): array
    {
        $headings = [];
        $used = [];

        $result = preg_replace_callback(
            '/<h([1-6])>(.*?)<\/h\1>/s',
            static function (array $match) use (&$headings, &$used): string {
                $level = (int) $match[1];
                $inner = $match[2];
                $text = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                $id = Str::slug($text);

                if ($id === '') {
                    $id = 'section';
                }

                // Two headings with the same text must still get distinct,
                // deterministic anchors.
                $used[$id] = ($used[$id] ?? 0) + 1;

                if ($used[$id] > 1) {
                    $id .= '-'.$used[$id];
                }

                $headings[] = ['level' => $level, 'text' => $text, 'id' => $id];

                return sprintf(
                    '<h%d id="%s" class="group scroll-mt-8">%s'
                    .'<a href="#%s" class="ml-2 text-slate-300 opacity-0 group-hover:opacity-100 dark:text-slate-600" aria-label="Link to this section">#</a>'
                    .'</h%d>',
                    $level,
                    $id,
                    $inner,
                    $id,
                    $level,
                );
            },
            $html,
        );

        return [$result ?? $html, $headings];
    }
}
