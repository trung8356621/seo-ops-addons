<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Jobs;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentMetricAggregator;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class AggregateAgentMetricsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly ?string $date = null,
    ) {}

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        AgentMetricAggregator $aggregator,
    ): void {
        $databaseConnection->bootstrapLegacySharedConnection();
        $aggregator->aggregateDaily($this->date);
    }
}
