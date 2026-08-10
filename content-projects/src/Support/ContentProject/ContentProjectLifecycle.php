<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use RuntimeException;

/**
 * Map task/article → business lifecycle phase + transition guard.
 * Batch D: phase resolution delegates to ContentProjectItemStateResolver.
 */
final class ContentProjectLifecycle
{
    private readonly ContentProjectItemStateResolver $stateResolver;

    public function __construct(?ContentProjectItemStateResolver $resolver = null)
    {
        $this->stateResolver = $resolver ?? new ContentProjectItemStateResolver;
    }

    public function resolvePhase(SeoProjectTask $task, ?SeoArticle $article = null): ContentProjectLifecyclePhase
    {
        return $this->stateResolver->resolvePhase($task, $article);
    }

    /**
     * @param  array<string, mixed>  $hints
     */
    public function resolveState(SeoProjectTask $task, ?SeoArticle $article = null, array $hints = []): ContentProjectItemState
    {
        return $this->stateResolver->resolve($task, $article, $hints);
    }

    public function assertCanTransition(
        SeoProjectTask $task,
        ContentProjectLifecyclePhase $to,
        ?SeoArticle $article = null,
    ): void {
        $from = $this->resolvePhase($task, $article);
        if ($from->canTransitionTo($to)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Lifecycle không cho phép chuyển %s → %s.',
            $from->value,
            $to->value,
        ));
    }

    public function assertNotArchivedForGenerate(SeoProjectTask $task): void
    {
        $phase = $this->resolvePhase($task);
        if ($phase === ContentProjectLifecyclePhase::Archived) {
            throw new RuntimeException('Project/Item đã Archived — không được Generate lại trên Workspace cũ.');
        }
    }
}
