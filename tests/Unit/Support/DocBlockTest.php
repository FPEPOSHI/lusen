<?php

declare(strict_types=1);

use Lusen\Support\DocBlock;

it('reads a one-line docblock as the summary', function (): void {
    $doc = DocBlock::parse("/**\n * List users.\n */");

    expect($doc->summary)->toBe('List users')
        ->and($doc->description)->toBe('');
});

it('strips the trailing full stop from the summary', function (): void {
    // The summary becomes a heading and a <title>; neither takes a full stop.
    expect(DocBlock::parse('/** Create a user. */')->summary)->toBe('Create a user');
});

it('keeps an ellipsis intact', function (): void {
    expect(DocBlock::parse('/** Wait for it... */')->summary)->toBe('Wait for it...');
});

it('splits the first paragraph from the rest', function (): void {
    $doc = DocBlock::parse("/**\n * List users.\n *\n * Paginated, newest first.\n * Filterable by status.\n */");

    expect($doc->summary)->toBe('List users')
        ->and($doc->description)->toBe("Paginated, newest first.\nFilterable by status.");
});

it('keeps a list in a description instead of running it into one line', function (): void {
    $doc = DocBlock::parse("/**\n * Rates\n *\n * Sources:\n *   - `BOA` first\n *   - `BKT` second\n */");

    expect($doc->description)->toBe("Sources:\n  - `BOA` first\n  - `BKT` second");
});

it('preserves paragraph breaks in the description', function (): void {
    $doc = DocBlock::parse("/**\n * Sum.\n *\n * First para.\n *\n * Second para.\n */");

    expect($doc->description)->toBe("First para.\n\nSecond para.");
});

it('reads tags', function (): void {
    $doc = DocBlock::parse("/**\n * Summary.\n *\n * @group Users\n * @authenticated\n */");

    expect($doc->tag('group'))->toBe('Users')
        ->and($doc->hasTag('authenticated'))->toBeTrue()
        ->and($doc->hasTag('deprecated'))->toBeFalse();
});

it('matches tags case-insensitively', function (): void {
    expect(DocBlock::parse('/** @Group Users */')->tag('group'))->toBe('Users');
});

it('keeps multiple values for a repeated tag', function (): void {
    $doc = DocBlock::parse("/**\n * @see one\n * @see two\n */");

    expect($doc->tagValues('see'))->toBe(['one', 'two']);
});

it('joins a tag value that wraps onto the next line', function (): void {
    $doc = DocBlock::parse("/**\n * @group Customer\n *   Accounts\n */");

    expect($doc->tag('group'))->toBe('Customer Accounts');
});

it('does not treat text after a tag as description', function (): void {
    $doc = DocBlock::parse("/**\n * Summary.\n *\n * @param int \$id The id.\n */");

    expect($doc->description)->toBe('');
});

it('handles an empty or missing comment', function (): void {
    expect(DocBlock::parse(false)->isEmpty())->toBeTrue()
        ->and(DocBlock::parse(null)->isEmpty())->toBeTrue()
        ->and(DocBlock::parse('/** */')->isEmpty())->toBeTrue();
});
