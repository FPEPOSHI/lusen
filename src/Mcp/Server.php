<?php

declare(strict_types=1);

namespace Lusen\Mcp;

use JsonException;
use Throwable;

/**
 * A minimal MCP server: newline-delimited JSON-RPC 2.0 over a pair of streams.
 *
 * Written directly rather than on top of an SDK. The protocol surface a
 * documentation server needs is four methods, and Lusen's whole proposition is
 * that it installs with almost nothing behind it - taking a dependency, and an
 * unstable one, so that a feature some users never enable can exist would
 * contradict that. The subset is small enough to own and test outright.
 *
 * Streams are injected rather than assumed to be STDIN and STDOUT, so the
 * conversation can be driven end to end in a test.
 */
final class Server
{
    /**
     * The protocol revisions this server knows how to speak.
     *
     * @var list<string>
     */
    private const SUPPORTED_PROTOCOLS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    private const DEFAULT_PROTOCOL = '2025-06-18';

    public function __construct(
        private readonly ToolProvider $tools,
        private readonly string $name = 'lusen',
        private readonly string $version = '0.1.0',
    ) {}

    /**
     * Reads messages until the input closes.
     *
     * @param  resource  $input
     * @param  resource  $output
     */
    public function serve($input, $output): void
    {
        while (($line = fgets($input)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $response = $this->handle($line);

            // Notifications get no reply at all; writing one is a protocol
            // violation, not merely noise.
            if ($response !== null) {
                fwrite($output, $response."\n");
                fflush($output);
            }
        }
    }

    /**
     * One message in, one response out. Null means the message was a
     * notification and requires no reply.
     */
    public function handle(string $message): ?string
    {
        try {
            // Valid JSON is not necessarily an object: "5" and true both decode
            // cleanly and are not requests.
            $decoded = json_decode($message, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->encode($this->error(null, -32700, 'Parse error'));
        }

        if (! is_array($decoded) || ! isset($decoded['method']) || ! is_string($decoded['method'])) {
            return $this->encode($this->error(null, -32600, 'Invalid request'));
        }

        // Held separately so the narrowing above survives the copy.
        $method = $decoded['method'];

        /** @var array<string, mixed> $request */
        $request = $decoded;

        $id = $request['id'] ?? null;
        $isNotification = ! array_key_exists('id', $request);

        try {
            $result = $this->dispatch($method, $this->params($request));
        } catch (Throwable $exception) {
            return $isNotification
                ? null
                : $this->encode($this->error($id, -32603, $exception->getMessage()));
        }

        if ($isNotification) {
            return null;
        }

        if ($result === null) {
            return $this->encode($this->error($id, -32601, "Unknown method [{$method}]"));
        }

        return $this->encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null
     */
    private function dispatch(string $method, array $params): ?array
    {
        return match ($method) {
            'initialize' => $this->initialize($params),
            'tools/list' => ['tools' => $this->tools->definitions()],
            'tools/call' => $this->callTool($params),
            'ping' => [],
            // Notifications carry no result; returning an empty one keeps the
            // notification branch above from mistaking them for unknown methods.
            'notifications/initialized', 'notifications/cancelled' => [],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;

        // Answer in the client's dialect when it is one we know, so an older
        // client is not forced to downgrade us.
        $protocol = is_string($requested) && in_array($requested, self::SUPPORTED_PROTOCOLS, true)
            ? $requested
            : self::DEFAULT_PROTOCOL;

        return [
            'protocolVersion' => $protocol,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => $this->name, 'version' => $this->version],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function callTool(array $params): array
    {
        $name = $params['name'] ?? null;

        if (! is_string($name)) {
            return $this->toolError('A tool name is required.');
        }

        /** @var array<string, mixed> $arguments */
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        try {
            $text = $this->tools->call($name, $arguments);
        } catch (Throwable $exception) {
            // A failing tool is reported inside the result, not as a transport
            // error: the model needs to read what went wrong and try again.
            return $this->toolError($exception->getMessage());
        }

        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolError(string $message): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function params(array $request): array
    {
        /** @var array<string, mixed> $params */
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];

        return $params;
    }

    /**
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
