<?php

declare(strict_types=1);

use Lusen\Ir\Page;
use Lusen\Ir\Section;
use Lusen\Mcp\DocumentationTools;

function tools(): DocumentationTools
{
    $spec = fixtureSpec()->withSections([
        new Section('Getting started', [
            new Page(
                id: 'authentication',
                title: 'Authentication',
                markdown: 'Send a bearer token in the Authorization header.',
                section: 'Getting started',
            ),
        ]),
    ]);

    return new DocumentationTools($spec);
}

it('exposes the five documentation tools', function (): void {
    expect(array_column(tools()->definitions(), 'name'))->toBe([
        'search_documentation',
        'get_endpoint',
        'list_endpoints',
        'read_guide',
        'build_request',
    ]);
});

it('gives every tool a description and an object input schema', function (): void {
    foreach (tools()->definitions() as $definition) {
        expect($definition['description'])->not->toBe('')
            ->and($definition['inputSchema']['type'])->toBe('object');
    }
});

it('rejects an unknown tool with the list of real ones', function (): void {
    expect(fn () => tools()->call('nope', []))
        ->toThrow(InvalidArgumentException::class, 'Unknown tool [nope]');
});

/* ------------------------------------------------------------- search */

it('searches endpoints and guides together', function (): void {
    $result = tools()->call('search_documentation', ['query' => 'users']);

    expect($result)->toContain('users.index')->toContain('get_endpoint');
});

it('finds a guide page by its content', function (): void {
    expect(tools()->call('search_documentation', ['query' => 'bearer token']))
        ->toContain('Authentication')
        ->toContain('read_guide');
});

it('requires every search term to match somewhere', function (): void {
    // Otherwise a two-word query matches on the commoner word alone.
    expect(tools()->call('search_documentation', ['query' => 'users nonexistentword']))
        ->toContain('No matches');
});

it('points at list_endpoints when a search finds nothing', function (): void {
    expect(tools()->call('search_documentation', ['query' => 'zzzz']))
        ->toContain('list_endpoints');
});

it('requires a query', function (): void {
    expect(fn () => tools()->call('search_documentation', []))
        ->toThrow(InvalidArgumentException::class, 'required');
});

/* -------------------------------------------------------- get_endpoint */

it('returns the same markdown a reader sees', function (): void {
    $result = tools()->call('get_endpoint', ['id' => 'users.store']);

    expect($result)->toContain('# Create a user')
        ->toContain('Authentication: required (bearer token).')
        ->toContain('### Body parameters')
        ->toContain('curl -X POST');
});

it('suggests real ids when an endpoint id is wrong', function (): void {
    expect(fn () => tools()->call('get_endpoint', ['id' => 'nope']))
        ->toThrow(InvalidArgumentException::class, 'users.index');
});

/* ------------------------------------------------------ list_endpoints */

it('lists the whole surface grouped', function (): void {
    $result = tools()->call('list_endpoints', []);

    expect($result)->toContain('## Users')
        ->toContain('`GET /api/users`')
        ->toContain('id `users.index`')
        ->toContain(', authenticated');
});

it('filters to one group', function (): void {
    expect(tools()->call('list_endpoints', ['group' => 'Users']))->toContain('## Users');
});

it('names the real groups when the filter matches none', function (): void {
    expect(tools()->call('list_endpoints', ['group' => 'Ghosts']))
        ->toContain('No group named "Ghosts"')
        ->toContain('Users');
});

/* ----------------------------------------------------------- read_guide */

it('reads a guide page', function (): void {
    expect(tools()->call('read_guide', ['id' => 'authentication']))
        ->toContain('# Authentication')
        ->toContain('bearer token');
});

it('lists the pages when no id is given', function (): void {
    expect(tools()->call('read_guide', []))->toContain('`authentication`');
});

it('lists the pages when the id is wrong', function (): void {
    expect(tools()->call('read_guide', ['id' => 'nope']))
        ->toContain('No guide page')
        ->toContain('`authentication`');
});

/* -------------------------------------------------------- build_request */

it('builds a runnable request', function (): void {
    $result = tools()->call('build_request', ['id' => 'users.store']);

    expect($result)->toContain('curl -X POST')
        ->toContain('bearer token');
});

it('uses the values it is given', function (): void {
    $result = tools()->call('build_request', [
        'id' => 'users.store',
        'values' => ['email' => 'someone@real.test'],
    ]);

    expect($result)->toContain('someone@real.test');
});

it('says which supplied values it ignored', function (): void {
    // Silently dropping a value would leave the model believing it was used.
    expect(tools()->call('build_request', ['id' => 'users.store', 'values' => ['nope' => 1]]))
        ->toContain('Ignored')
        ->toContain('`nope`');
});

it('flags required parameters that are still examples', function (): void {
    expect(tools()->call('build_request', ['id' => 'users.store']))
        ->toContain('examples, not real data')
        ->toContain('`email`');
});

it('does not flag a required parameter the caller supplied', function (): void {
    $result = tools()->call('build_request', [
        'id' => 'users.store',
        'values' => ['email' => 'someone@real.test'],
    ]);

    expect($result)->not->toContain('`email`');
});
