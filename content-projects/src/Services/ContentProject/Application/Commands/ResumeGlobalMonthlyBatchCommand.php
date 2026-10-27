<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ResumeGlobalMonthlyBatchCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly int $batchId,
    ) {}

    public function name(): string
    {
        return 'content_project.resume_global_monthly_batch';
    }
}
