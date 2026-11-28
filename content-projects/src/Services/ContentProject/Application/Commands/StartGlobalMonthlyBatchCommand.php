<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class StartGlobalMonthlyBatchCommand implements ContentProjectCommand
{
    /**
     * @param  list<int>  $selectedProjectIds  Empty = all eligible projects in month.
     */
    public function __construct(
        public readonly string $planningMonth,
        public readonly string $aiCostPolicy = 'auto_cost',
        public readonly array $selectedProjectIds = [],
    ) {}

    public function name(): string
    {
        return 'content_project.start_global_monthly_batch';
    }
}
