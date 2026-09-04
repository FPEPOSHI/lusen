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
