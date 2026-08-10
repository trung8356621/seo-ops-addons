<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

/**
 * Result of safe Existing Article reconciliation for rewrite/improve items.
 */
final class ContentProjectExistingArticleReconcileResult
{
    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_REPAIRED = 'repaired';

    public const STATUS_MISSING = 'missing';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_NOT_REQUIRED = 'not_required';

    public const STATUS_CONFLICT = 'conflict';

    public function __construct(
        public readonly string $status,
        public readonly ?int $articleId = null,
        public readonly string $matchedBy = '',
        public readonly string $reason = '',
        public readonly bool $persisted = false,
    ) {}

    public function isUsable(): bool
    {
        return in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_REPAIRED], true)
            && $this->articleId !== null
            && $this->articleId > 0;
    }

    public function isRepairable(): bool
    {
        return $this->status === self::STATUS_REPAIRED
            || ($this->status === self::STATUS_MISSING && $this->articleId !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'article_id' => $this->articleId,
            'matched_by' => $this->matchedBy,
            'reason' => $this->reason,
            'persisted' => $this->persisted,
        ];
    }
}
