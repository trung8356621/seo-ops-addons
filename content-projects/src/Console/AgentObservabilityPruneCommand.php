<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Illuminate\Console\Command;

/**
 * Historical Agent observability prune entrypoint.
 * Agent Workspace retention runtime is isolated/reference-only — command is a no-op stub.
 */
final class AgentObservabilityPruneCommand extends Command
{
    protected $signature = 'agent:observability:prune {--dry-run : Report only} {--sync : Run inline}';

    protected $description = 'Prune Agent observability (disabled — Agent Workspace is reference-only).';

    public function handle(): int
    {
        $this->warn('Agent Workspace observability prune is disabled (legacy Agent runtime isolated).');

        return self::SUCCESS;
    }
}
