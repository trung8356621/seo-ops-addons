<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\RunEngine;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectRunSemanticStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunStatusMapper;

/**
 * DB-first cooperative cancellation checks at engine/job boundaries.
 */
final class RunCancellationGuard
{
    public function __construct(
        private readonly ContentProjectRunStatusMapper $mapper,
    ) {}

    public function semanticStatus(SeoProjectRun $run): ContentProjectRunSemanticStatus
    {
        $run->refresh();

        return $this->mapper->runFromDb((string) $run->status);
    }

    public function isStopRequested(SeoProjectRun $run): bool
    {
        return $this->semanticStatus($run)->isStopRequested();
    }

    public function allowsDispatch(SeoProjectRun $run): bool
    {
        return $this->semanticStatus($run)->allowsDispatch();
    }

    public function isTerminal(SeoProjectRun $run): bool
    {
        return $this->semanticStatus($run)->isTerminal();
    }

    /**
     * @throws \RuntimeException when run must not continue execution
     */
    public function assertAllowsArticleExecution(SeoProjectRun $run): void
    {
        $status = $this->semanticStatus($run);
        if ($status->allowsDispatch()) {
            return;
        }

        throw new \RuntimeException(
            'Run #'.(int) $run->id.' không cho phép chạy article (status='.$status->value.').'
        );
    }
}
