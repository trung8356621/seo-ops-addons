<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Single classifier for Content Project ops Summary + List + badges.
 *
 * Layers:
 * - Generation: pending / running / generated / failed
 * - Workflow: draft / pending(AI) / scheduled / published / failed (+ approved internal)
 * - Reporting: needs_review / in_review (hidden after Approve/Schedule/Publish)
 *
 * Summary cards (UX): Draft, Pending, Needs Review, In Review, Scheduled, Published, Failed.
 * Approved kept as internal bucket only — not an active workflow card.
 */
final class ContentProjectOpsStateClassifier
{
    public const BUCKET_DRAFT = 'draft';

    public const BUCKET_PENDING = 'pending';

    public const BUCKET_NEEDS_REVIEW = 'needs_review';

    public const BUCKET_IN_REVIEW = 'in_review';

    public const BUCKET_APPROVED = 'approved';

    public const BUCKET_SCHEDULED = 'scheduled';

    public const BUCKET_PUBLISHED = 'published';

    public const BUCKET_FAILED = 'failed';

    public const BUCKET_OTHER = 'other';

    /**
     * @return array<string, int>
     */
    public static function contribution(string $summaryBucket): array
    {
        return match ($summaryBucket) {
            self::BUCKET_DRAFT => ['draft' => 1],
            self::BUCKET_PENDING => ['pending' => 1],
            self::BUCKET_NEEDS_REVIEW => ['needs_review' => 1],
            self::BUCKET_IN_REVIEW => ['review' => 1],
            self::BUCKET_APPROVED => ['approved' => 1],
            self::BUCKET_SCHEDULED => ['scheduled' => 1],
            self::BUCKET_PUBLISHED => ['published' => 1],
            self::BUCKET_FAILED => ['failed' => 1],
            default => [],
        };
    }

    /**
     * @return array<string, int>
     */
    public static function deltaBetween(string $fromBucket, string $toBucket): array
    {
        $from = self::contribution($fromBucket);
        $to = self::contribution($toBucket);
        $keys = array_unique(array_merge(array_keys($from), array_keys($to)));
        $out = [];
        foreach ($keys as $key) {
            $d = ((int) ($to[$key] ?? 0)) - ((int) ($from[$key] ?? 0));
            if ($d !== 0) {
                $out[$key] = $d;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{
     *     summary_bucket: string,
     *     generation_key: string,
     *     workflow_key: string,
     *     reporting_key: string|null,
     *     show_reporting_chip: bool,
     *     failure_type: string|null,
     *     is_draft_ops: bool,
     *     is_pending_ops: bool,
     *     is_needs_review: bool,
     *     is_in_review_reporting: bool,
     *     is_published_canonical: bool,
     *     is_scheduled_canonical: bool,
     *     is_approved_canonical: bool,
     *     is_failed_ops: bool,
     * }
     */
    public static function classify(array $row): array
    {
        $published = ContentProjectPublishedDefinition::matches($row);
        $scheduled = ContentProjectScheduledDefinition::matches($row);
        $approved = ContentProjectApprovedDefinition::matches($row);
        $failed = ContentProjectFailedOpsDefinition::matches($row);
        $pending = ContentProjectPendingOpsDefinition::matches($row);
        $draft = ContentProjectDraftOpsDefinition::matches($row);
        $needsReview = ContentProjectRecentlyCompletedDefinition::matches($row);
        $inReview = ContentProjectInReviewReportingDefinition::matches($row);
        $running = ! empty($row['is_genuinely_running']) || $pending;

        $summary = match (true) {
            $published => self::BUCKET_PUBLISHED,
            $scheduled => self::BUCKET_SCHEDULED,
            $failed => self::BUCKET_FAILED,
            $approved => self::BUCKET_APPROVED,
            $inReview => self::BUCKET_IN_REVIEW,
            $needsReview => self::BUCKET_NEEDS_REVIEW,
            $pending => self::BUCKET_PENDING,
            $draft => self::BUCKET_DRAFT,
            default => self::BUCKET_OTHER,
        };

        $generationKey = self::generationKey($row, $running, $failed);
        $workflowKey = self::workflowKey($published, $scheduled, $approved, $failed, $draft, $pending, $row);
        $reportingKey = null;
        if ($needsReview) {
            $reportingKey = 'needs_review';
        } elseif ($inReview) {
            $reportingKey = 'in_review';
        }

        return [
            'summary_bucket' => $summary,
            'generation_key' => $generationKey,
            'workflow_key' => $workflowKey,
            'reporting_key' => $reportingKey,
            'show_reporting_chip' => $reportingKey !== null,
            'failure_type' => $failed ? ContentProjectFailureTypeMapper::resolve($row) : null,
            'is_draft_ops' => $draft,
            'is_pending_ops' => $pending,
            'is_needs_review' => $needsReview,
            'is_in_review_reporting' => $inReview,
            'is_published_canonical' => $published,
            'is_scheduled_canonical' => $scheduled,
            'is_approved_canonical' => $approved,
            'is_failed_ops' => $failed,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public static function countSummary(array $rows): array
    {
        $counts = [
            'total_items' => count($rows),
            'normal' => count($rows),
            'draft' => 0,
            'pending' => 0,
            'recently_completed' => 0,
            'waiting_review' => 0,
            'approved' => 0,
            'waiting_publish' => 0,
            'published' => 0,
            'failed' => 0,
            'running' => 0,
            'generated' => 0,
        ];

        foreach ($rows as $row) {
            $c = self::classify($row);
            match ($c['summary_bucket']) {
                self::BUCKET_DRAFT => $counts['draft']++,
                self::BUCKET_PENDING => $counts['pending']++,
                self::BUCKET_NEEDS_REVIEW => $counts['recently_completed']++,
                self::BUCKET_IN_REVIEW => $counts['waiting_review']++,
                self::BUCKET_APPROVED => $counts['approved']++,
                self::BUCKET_SCHEDULED => $counts['waiting_publish']++,
                self::BUCKET_PUBLISHED => $counts['published']++,
                self::BUCKET_FAILED => $counts['failed']++,
                default => null,
            };
            if ($c['summary_bucket'] === self::BUCKET_PENDING) {
                $counts['running']++;
            }
            if ($c['generation_key'] === 'generated') {
                $counts['generated']++;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function matchesSummaryFilter(array $row, string $cardOrFilter): bool
    {
        $card = strtolower(trim($cardOrFilter));
        // Normal = whole Content Project working set (not lifecycle, not never-generated only).
        // 'draft' kept as legacy alias — same match-all semantics as Normal.
        if ($card === '' || $card === 'all' || $card === 'total' || $card === 'normal' || $card === 'draft') {
            return true;
        }

        $c = self::classify($row);

        return match ($card) {
            'pending', ContentProjectPendingOpsDefinition::FILTER => $c['is_pending_ops'],
            'recently_completed', 'needs_review', ContentProjectRecentlyCompletedDefinition::FILTER => $c['is_needs_review'],
            'review', 'in_review', ContentProjectInReviewReportingDefinition::FILTER => $c['is_in_review_reporting'],
            'approved', ContentProjectApprovedDefinition::FILTER => $c['is_approved_canonical'],
            'scheduled', 'waiting_publish', ContentProjectScheduledDefinition::FILTER => $c['is_scheduled_canonical'],
            'published', ContentProjectPublishedDefinition::FILTER => $c['is_published_canonical'],
            'failed', ContentProjectFailedOpsDefinition::FILTER => $c['is_failed_ops'],
            'running' => $c['is_pending_ops'],
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function generationKey(array $row, bool $running, bool $failed): string
    {
        if ($running) {
            return 'running';
        }
        if ($failed) {
            return 'failed';
        }
        $gs = strtolower(trim((string) ($row['generation_status'] ?? '')));
        $exec = strtolower(trim((string) ($row['execution_status'] ?? '')));
        if (in_array($gs, ['completed', 'reviewing'], true)
            && ($exec === '' || in_array($exec, ['success', 'completed'], true))
        ) {
            return 'generated';
        }

        return 'pending';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function workflowKey(
        bool $published,
        bool $scheduled,
        bool $approved,
        bool $failed,
        bool $draft,
        bool $pending,
        array $row,
    ): string {
        if ($published) {
            return 'published';
        }
        if ($scheduled) {
            return 'scheduled';
        }
        if ($failed) {
            return 'failed';
        }
        if ($approved) {
            return 'approved';
        }
        if ($pending) {
            return 'pending';
        }
        if ($draft) {
            return 'draft';
        }

        $lifecycle = strtolower(trim((string) ($row['lifecycle'] ?? '')));
        if ($lifecycle === 'failed') {
            return 'failed';
        }

        return 'draft';
    }
}
