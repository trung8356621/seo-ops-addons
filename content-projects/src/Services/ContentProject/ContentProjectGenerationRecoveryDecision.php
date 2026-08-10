<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

/**
 * Canonical generation recovery capability for one Content Project item.
 * UI visibility and CommandBus validation MUST share this decision.
 */
final class ContentProjectGenerationRecoveryDecision
{
    public const ACTION_NONE = 'none';

    public const ACTION_ACTIVE = 'active';

    public const ACTION_RESUME = 'resume';

    public const ACTION_RERUN = 'rerun';

    public const ACTION_GENERATE = 'generate';

    /** Manual fallback: rewrite/improve Existing Article unresolved after auto reconcile. */
    public const ACTION_SELECT_EXISTING_ARTICLE = 'select_existing_article';

    /**
     * @param  list<string>  $evidence
     */
    public function __construct(
        public readonly int $taskId,
        public readonly string $action,
        public readonly string $reason,
        public readonly ?string $resumableFromStep = null,
        public readonly ?int $existingArticleId = null,
        public readonly bool $repairable = false,
        public readonly bool $repaired = false,
        public readonly bool $staleRecovered = false,
        public readonly array $evidence = [],
    ) {}

    public function showResume(): bool
    {
        return $this->action === self::ACTION_RESUME;
    }

    public function showRerun(): bool
    {
        return $this->action === self::ACTION_RERUN;
    }

    public function showGenerate(): bool
    {
        return $this->action === self::ACTION_GENERATE;
    }

    public function showSelectExistingArticle(): bool
    {
        return $this->action === self::ACTION_SELECT_EXISTING_ARTICLE;
    }

    public function showSmartCreateOrRerun(): bool
    {
        return $this->showRerun() || $this->showGenerate();
    }

    public function isActive(): bool
    {
        return $this->action === self::ACTION_ACTIVE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'action' => $this->action,
            'reason' => $this->reason,
            'resumable_from_step' => $this->resumableFromStep,
            'existing_article_id' => $this->existingArticleId,
            'repairable' => $this->repairable,
            'repaired' => $this->repaired,
            'stale_recovered' => $this->staleRecovered,
            'evidence' => $this->evidence,
        ];
    }
}
