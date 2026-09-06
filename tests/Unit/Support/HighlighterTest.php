<?php

declare(strict_types=1);

use Lusen\Support\Highlighter;

it('marks json keys apart from string values', function (): void {
    $html = Highlighter::json('{"name": "Ada"}');

    expect($html)->toContain('<span class="tok-key">&quot;name&quot;</span>')
        ->toContain('<span class="tok-str">&quot;Ada&quot;</span>');
});

it('marks numbers, booleans and null', function (): void {
    $html = Highlighter::json('{"a": 1, "b": true, "c": null, "d": -2.5}');

    expect($html)->toContain('<span class="tok-num">1</span>')
        ->toContain('<span class="tok-lit">true</span>')
        ->toContain('<span class="tok-lit">null</span>')
        ->toContain('<span class="tok-num">-2.5</span>');
});

it('marks the command and its flags in a shell snippet', function (): void {
    $html = Highlighter::shell("curl -X POST 'https://api.test'");

    expect($html)->toContain('<span class="tok-cmd">curl</span>')
        ->toContain('tok-flag')
        ->toContain('tok-str');
});

it('escapes html so a payload cannot inject markup', function (): void {
    $html = Highlighter::json('{"x": "<script>alert(1)</script>"}');

    expect($html)->not->toContain('<script>')
        ->and($html)->toContain('&lt;script&gt;');
});

it('escapes html outside of any token too', function (): void {
    expect(Highlighter::shell('echo <b>hi</b>'))->not->toContain('<b>');
});

it('never double-escapes an entity', function (): void {
    $html = Highlighter::json('{"x": "a &amp; b"}');

    expect($html)->toContain('a &amp; b')
        ->and($html)->not->toContain('&amp;amp;');
});

it('leaves an unknown language escaped but unstyled', function (): void {
    $html = Highlighter::highlight('<x> plain', 'ruby');

    expect($html)->toBe('&lt;x&gt; plain');
});

it('is deterministic', function (): void {
    expect(Highlighter::json('{"a":1}'))->toBe(Highlighter::json('{"a":1}'));
});

it('colours the javascript snippet the way it colours the curl one', function (): void {
    // One coloured block above a plain one reads as a bug in the docs.
    $html = Highlighter::highlight("const response = await fetch('https://api.test', {\n  method: 'GET'\n});", 'javascript');

    expect($html)->toContain('<span class="tok-lit">const</span>')
        ->toContain('<span class="tok-lit">await</span>')
        ->toContain('<span class="tok-cmd">fetch</span>')
        ->toContain('<span class="tok-key">method</span>')
        ->toContain('<span class="tok-str">&#039;GET&#039;</span>');
});

it('reads a quoted key as a key and its value as a string', function (): void {
    $html = Highlighter::highlight("{'Authorization': 'Bearer token'}", 'javascript');

    expect($html)->toContain('<span class="tok-key">&#039;Authorization&#039;</span>')
        ->toContain('<span class="tok-str">&#039;Bearer token&#039;</span>');
});

it('escapes javascript it does not tokenise', function (): void {
    expect(Highlighter::highlight('const a = b < c && d > e;', 'javascript'))
        ->toContain('&lt;')
        ->toContain('&gt;')
        ->toContain('&amp;&amp;');
});

it('colours the php snippets, so no tab sits grey beside a coloured one', function (): void {
    $html = Highlighter::highlight(
        "use GuzzleHttp\\Client;\n\n\$client = new Client();\n\n['headers' => ['Accept' => 'application/json']]",
        'php',
    );

    expect($html)->toContain('<span class="tok-lit">use</span>')
        ->toContain('<span class="tok-lit">new</span>')
        ->toContain('<span class="tok-flag">$client</span>')
        ->toContain('<span class="tok-key">&#039;Accept&#039;</span>')
        ->toContain('<span class="tok-str">&#039;application/json&#039;</span>');
});

it('escapes php it does not tokenise', function (): void {
    expect(Highlighter::highlight('$a = $b < $c && $d > $e;', 'php'))
        ->toContain('&lt;')
        ->toContain('&gt;')
        ->toContain('&amp;&amp;');
});
