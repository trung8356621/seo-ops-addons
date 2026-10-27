<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class StartGlobalMonthlyBatchCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $planningMonth,
        public readonly string $aiCostPolicy,
    ) {}

    public function name(): string
    {
        return 'content_project.start_global_monthly_batch';
    }
}
