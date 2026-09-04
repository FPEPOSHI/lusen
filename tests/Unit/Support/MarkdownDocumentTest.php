<?php

declare(strict_types=1);

use Lusen\Support\MarkdownDocument;

it('renders commonmark with github tables', function (): void {
    $html = MarkdownDocument::render("| a | b |\n| - | - |\n| 1 | 2 |")->html;

    expect($html)->toContain('<table>')->toContain('<th>a</th>');
});

it('gives every heading a slug anchor', function (): void {
    $html = MarkdownDocument::render('## Getting started')->html;

    expect($html)->toContain('id="getting-started"')
        ->toContain('href="#getting-started"');
});

it('keeps duplicate headings distinct and deterministic', function (): void {
    $document = MarkdownDocument::render("## Notes\n\n## Notes");

    expect(array_column($document->headings, 'id'))->toBe(['notes', 'notes-2'])
        ->and(array_column(MarkdownDocument::render("## Notes\n\n## Notes")->headings, 'id'))
        ->toBe(['notes', 'notes-2']);
});

it('lists only h2 and h3 in the table of contents', function (): void {
    $document = MarkdownDocument::render("# Title\n\n## Two\n\n### Three\n\n#### Four");

    expect(array_column($document->contents(), 'text'))->toBe(['Two', 'Three']);
});

it('strips markup from heading text but keeps it in the rendered heading', function (): void {
    $document = MarkdownDocument::render('## Using `curl`');

    expect($document->contents()[0]['text'])->toBe('Using curl')
        ->and($document->html)->toContain('<code>curl</code>');
});

it('does not emit javascript urls', function (): void {
    $html = MarkdownDocument::render('[click](javascript:alert(1))')->html;

    expect($html)->not->toContain('javascript:alert');
});

it('renders fenced code blocks', function (): void {
    expect(MarkdownDocument::render("```bash\ncurl example\n```")->html)
        ->toContain('<pre><code class="language-bash">');
});

it('highlights fenced code the same way endpoint pages do', function (): void {
    // A curl block in a guide rendering plain, while the identical block on an
    // endpoint page is coloured, reads as two different products.
    $html = MarkdownDocument::render("```bash\ncurl -X POST 'https://api.test'\n```")->html;

    expect($html)->toContain('<span class="tok-cmd">curl</span>')
        ->toContain('tok-str');
});

it('highlights json fences', function (): void {
    expect(MarkdownDocument::render("```json\n{\"id\": 1}\n```")->html)
        ->toContain('<span class="tok-key">&quot;id&quot;</span>');
});

it('leaves an unlabelled fence alone', function (): void {
    expect(MarkdownDocument::render("```\nplain text\n```")->html)
        ->toContain('<pre><code>plain text');
});

it('does not double-escape a highlighted fence', function (): void {
    $html = MarkdownDocument::render("```json\n{\"x\": \"a & b\"}\n```")->html;

    expect($html)->toContain('a &amp; b')
        ->and($html)->not->toContain('&amp;amp;');
});

it('escapes markup inside a fence', function (): void {
    expect(MarkdownDocument::render("```json\n{\"x\": \"<script>\"}\n```")->html)
        ->not->toContain('<script>');
});
