<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft;

/**
 * Normalized result for Shared Draft intake (Add to Draft).
 */
final class PlanningDraftIntakeResult
{
    public const STATUS_ADDED = 'added';

    public const STATUS_ALREADY_IN_DRAFT = 'already_in_draft';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_MISSING_KEYWORD = 'missing_keyword';

    public const STATUS_SITE_NOT_RESOLVED = 'site_not_resolved';

    public const STATUS_DRAFT_NOT_RESOLVED = 'draft_not_resolved';

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<int>  $articleIds
     * @param  list<int>  $keywordIds
     * @param  list<int>  $taskIds
     */
    public function __construct(
        public readonly string $status,
        public readonly ?int $draftProjectId,
        public readonly array $summary = [],
        public readonly string $message = '',
        public readonly array $articleIds = [],
        public readonly array $keywordIds = [],
        public readonly array $taskIds = [],
    ) {}

    public function isSuccess(): bool
    {
        return in_array($this->status, [
            self::STATUS_ADDED,
            self::STATUS_ALREADY_IN_DRAFT,
            self::STATUS_PARTIAL,
        ], true);
    }

    public function isAlreadyInDraft(): bool
    {
        return $this->status === self::STATUS_ALREADY_IN_DRAFT;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public static function fromAssignmentSummary(
        array $summary,
        int $draftProjectId,
        string $addedMessage,
        string $alreadyMessage,
        string $failedMessage,
        array $articleIds = [],
        array $keywordIds = [],
    ): self {
        $added = (int) ($summary['added'] ?? 0);
        $duplicate = (int) ($summary['duplicate'] ?? 0);
        $already = (int) ($summary['already_in_project'] ?? 0);
        $siteNotResolved = (int) ($summary['site_not_resolved'] ?? 0);
        $existingArticle = (int) ($summary['existing_article'] ?? 0);
        // Draft semantics: ignore legacy overflow / domain_mismatch counters.
        $skipped = $duplicate + $already;

        unset($existingArticle);

        if ($siteNotResolved > 0 && $added === 0) {
            return new self(
                self::STATUS_SITE_NOT_RESOLVED,
                $draftProjectId,
                $summary,
                $failedMessage !== '' ? $failedMessage : $alreadyMessage,
                $articleIds,
                $keywordIds,
            );
        }

        if ($added > 0 && $skipped === 0) {
            return new self(
                self::STATUS_ADDED,
                $draftProjectId,
                $summary,
                $addedMessage,
                $articleIds,
                $keywordIds,
            );
        }

        if ($added === 0 && $skipped > 0) {
            return new self(
                self::STATUS_ALREADY_IN_DRAFT,
                $draftProjectId,
                $summary,
                $alreadyMessage,
                $articleIds,
                $keywordIds,
            );
        }

        if ($added > 0) {
            return new self(
                self::STATUS_PARTIAL,
                $draftProjectId,
                $summary,
                $addedMessage,
                $articleIds,
                $keywordIds,
            );
        }

        return new self(
            self::STATUS_FAILED,
            $draftProjectId,
            $summary,
            $failedMessage !== '' ? $failedMessage : $alreadyMessage,
            $articleIds,
            $keywordIds,
        );
    }
}
