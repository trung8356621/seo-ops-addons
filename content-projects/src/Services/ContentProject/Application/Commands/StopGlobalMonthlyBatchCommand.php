<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class StopGlobalMonthlyBatchCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly int $batchId,
        public readonly ?string $reason = null,
    ) {}

    public function name(): string
    {
        return 'content_project.stop_global_monthly_batch';
    }
}
