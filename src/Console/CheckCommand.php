<?php

declare(strict_types=1);

namespace Lusen\Console;

use Illuminate\Console\Command;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;
use Lusen\SpecBuilder;

/**
 * Reports what the documentation is still missing.
 *
 * Generated documentation rots quietly: a route lands with no summary and no
 * response, and nothing fails. Run this in CI with --strict and adding an
 * undocumented endpoint becomes a red build, which is the only thing that
 * reliably keeps docs honest.
 */
final class CheckCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'lusen:check
        {--strict : Exit non-zero when anything is missing}
        {--json : Report as JSON, for a script rather than a person}';

    /**
     * @var string
     */
    protected $description = 'Report endpoints missing documentation';

    public function handle(SpecBuilder $builder): int
    {
        $spec = $builder->build();
        $endpoints = $spec->endpoints();

        if ($this->option('json')) {
            return $this->reportJson($endpoints === [] ? [] : $this->findings($spec), count($endpoints));
        }

        if ($endpoints === []) {
            $this->components->warn('No routes matched. Check `routes.include` in config/lusen.php.');

            return self::SUCCESS;
        }

        $findings = $this->findings($spec);

        if ($findings === []) {
            $this->components->info(sprintf(
                'All %d endpoints are documented.',
                count($endpoints),
            ));

            return self::SUCCESS;
        }

        foreach ($findings as $endpoint => $problems) {
            $this->components->twoColumnDetail(
                "<fg=yellow>{$endpoint}</>",
                implode(', ', $problems),
            );
        }

        $this->newLine();
        $this->components->warn(sprintf(
            '%d of %d endpoints are missing documentation.',
            count($findings),
            count($endpoints),
        ));

        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The same findings, for something that is not a person.
     *
     * A team tracking documentation coverage wants it in a dashboard or a PR
     * comment, and parsing the two-column output to get there is the kind of
     * thing that breaks the first time a label is reworded.
     *
     * @param  array<string, list<string>>  $findings
     */
    private function reportJson(array $findings, int $total): int
    {
        $this->output->writeln((string) json_encode([
            'endpoints' => $total,
            'documented' => $total - count($findings),
            'findings' => array_map(
                static fn (string $endpoint, array $problems): array => [
                    'endpoint' => $endpoint,
                    'problems' => $problems,
                ],
                array_keys($findings),
                array_values($findings),
            ),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $findings !== [] && $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Only things a human can act on. A missing description is worth
     * reporting; an untyped field the codebase never states is not, and
     * flagging it would train people to ignore the output.
     *
     * @return array<string, list<string>>
     */
    private function findings(ApiSpec $spec): array
    {
        $findings = [];

        foreach ($spec->endpoints() as $endpoint) {
            $problems = [];

            if ($endpoint->description === null) {
                $problems[] = 'no description';
            }

            if ($endpoint->responses === []) {
                $problems[] = 'no documented response';
            } elseif (! $this->hasExample($endpoint)) {
                $problems[] = 'no response example';
            }

            foreach ($endpoint->parameters as $parameter) {
                if ($parameter->description === null || $parameter->description === '') {
                    $problems[] = "parameter `{$parameter->name}` undescribed";

                    break;
                }
            }

            if ($problems !== []) {
                $findings[$endpoint->method->value.' '.$endpoint->path()] = $problems;
            }
        }

        return $findings;
    }

    private function hasExample(Endpoint $endpoint): bool
    {
        foreach ($endpoint->responses as $response) {
            if ($response->examples !== [] || $response->schema !== null) {
                return true;
            }
        }

        return false;
    }
}
