<?php

declare(strict_types=1);

use Lusen\Mcp\Server;
use Lusen\Mcp\ToolProvider;

function fakeTools(?Closure $onCall = null): ToolProvider
{
    return new class($onCall) implements ToolProvider
    {
        public function __construct(private ?Closure $onCall) {}

        public function definitions(): array
        {
            return [['name' => 'echo', 'description' => 'Echoes.', 'inputSchema' => ['type' => 'object']]];
        }

        public function call(string $name, array $arguments): string
        {
            if ($this->onCall !== null) {
                return ($this->onCall)($name, $arguments);
            }

            return 'called '.$name;
        }
    };
}

function server(?Closure $onCall = null): Server
{
    return new Server(fakeTools($onCall), 'lusen', '9.9.9');
}

function ask(Server $server, array $message): ?array
{
    $response = $server->handle(json_encode($message, JSON_THROW_ON_ERROR));

    return $response === null ? null : json_decode($response, true, 32, JSON_THROW_ON_ERROR);
}

it('answers initialize with server info and capabilities', function (): void {
    $result = ask(server(), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])['result'];

    expect($result['serverInfo'])->toBe(['name' => 'lusen', 'version' => '9.9.9'])
        ->and($result['capabilities'])->toHaveKey('tools')
        ->and($result['protocolVersion'])->toBe('2025-06-18');
});

it('answers in the client protocol version when it knows that dialect', function (): void {
    $result = ask(server(), [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2024-11-05'],
    ])['result'];

    expect($result['protocolVersion'])->toBe('2024-11-05');
});

it('falls back to its own version for an unknown dialect', function (): void {
    $result = ask(server(), [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params' => ['protocolVersion' => '1999-01-01'],
    ])['result'];

    expect($result['protocolVersion'])->toBe('2025-06-18');
});

it('lists tools', function (): void {
    $result = ask(server(), ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])['result'];

    expect($result['tools'][0]['name'])->toBe('echo');
});

it('returns tool output as text content', function (): void {
    $result = ask(server(), [
        'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
        'params' => ['name' => 'echo', 'arguments' => ['a' => 1]],
    ])['result'];

    expect($result['isError'])->toBeFalse()
        ->and($result['content'][0])->toBe(['type' => 'text', 'text' => 'called echo']);
});

it('reports a failing tool inside the result, not as a transport error', function (): void {
    // The model needs to read what went wrong and try again; a JSON-RPC error
    // would abort the call instead.
    $server = server(function (): string {
        throw new RuntimeException('No endpoint with that id.');
    });

    $response = ask($server, [
        'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
        'params' => ['name' => 'echo'],
    ]);

    expect($response)->not->toHaveKey('error')
        ->and($response['result']['isError'])->toBeTrue()
        ->and($response['result']['content'][0]['text'])->toBe('No endpoint with that id.');
});

it('rejects a tool call with no name', function (): void {
    $result = ask(server(), [
        'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => [],
    ])['result'];

    expect($result['isError'])->toBeTrue();
});

it('answers ping', function (): void {
    expect(ask(server(), ['jsonrpc' => '2.0', 'id' => 6, 'method' => 'ping']))->toHaveKey('result');
});

it('never replies to a notification', function (): void {
    // A reply to a notification is a protocol violation, not merely noise.
    expect(ask(server(), ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']))->toBeNull()
        ->and(ask(server(), ['jsonrpc' => '2.0', 'method' => 'tools/list']))->toBeNull();
});

it('reports an unknown method', function (): void {
    $response = ask(server(), ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'nope']);

    expect($response['error']['code'])->toBe(-32601);
});

it('reports malformed json as a parse error', function (): void {
    $response = json_decode((string) server()->handle('{not json'), true);

    expect($response['error']['code'])->toBe(-32700);
});

it('reports a message with no method as an invalid request', function (): void {
    $response = json_decode((string) server()->handle('{"jsonrpc":"2.0","id":1}'), true);

    expect($response['error']['code'])->toBe(-32600);
});

it('reads a whole conversation off a stream', function (): void {
    $in = fopen('php://memory', 'r+');
    fwrite($in, json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])."\n");
    fwrite($in, "\n"); // blank lines are skipped
    fwrite($in, json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])."\n");
    fwrite($in, json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])."\n");
    rewind($in);

    $out = fopen('php://memory', 'r+');
    server()->serve($in, $out);
    rewind($out);

    $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($out))));

    // Two requests, one notification: exactly two responses.
    expect($lines)->toHaveCount(2)
        ->and(json_decode($lines[0], true)['id'])->toBe(1)
        ->and(json_decode($lines[1], true)['id'])->toBe(2);
});

it('rejects valid json that is not a request object', function (): void {
    // "5" and true decode cleanly and are not requests.
    foreach (['5', 'true', '"hello"', '[]'] as $message) {
        expect(json_decode((string) server()->handle($message), true)['error']['code'])->toBe(-32600);
    }
});
