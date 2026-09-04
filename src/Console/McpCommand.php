<?php

declare(strict_types=1);

namespace Lusen\Console;

use Illuminate\Console\Command;
use Lusen\Mcp\DocumentationTools;
use Lusen\Mcp\Server;
use Lusen\SpecBuilder;

final class McpCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'lusen:mcp';

    /**
     * @var string
     */
    protected $description = 'Serve this application\'s API documentation over MCP (stdio)';

    public function handle(SpecBuilder $builder): int
    {
        if (! (bool) config('lusen.agents.mcp', true)) {
            $this->components->error('MCP is disabled. Enable `lusen.agents.mcp` in config/lusen.php.');

            return self::FAILURE;
        }

        // stdout is the protocol channel: anything written there that is not a
        // JSON-RPC message corrupts the stream. Nothing else in this command
        // may print, which is why there is no progress output.
        $input = fopen('php://stdin', 'rb');
        $output = fopen('php://stdout', 'wb');

        if ($input === false || $output === false) {
            return self::FAILURE;
        }

        $spec = $builder->build();

        $server = new Server(
            tools: new DocumentationTools($spec),
            name: 'lusen',
            version: $spec->version,
        );

        $server->serve($input, $output);

        fclose($input);
        fclose($output);

        return self::SUCCESS;
    }
}
