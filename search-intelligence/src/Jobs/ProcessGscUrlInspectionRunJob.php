<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Jobs;

use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection\GscUrlInspectionRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessGscUrlInspectionRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 900;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [60, 180];

    public function __construct(
        public int $runId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GscUrlInspectionRunService $runs): array
    {
        return $runs->processRun($this->runId);
    }
}
