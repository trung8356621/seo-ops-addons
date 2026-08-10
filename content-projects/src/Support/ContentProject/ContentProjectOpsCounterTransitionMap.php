<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Presentation-only counter deltas for Content Project ops optimistic UI.
 *
 * Derived from ContentProjectOpsStateClassifier bucket contributions (before→after).
 * Not lifecycle / generation SoT. Applied after command/request is accepted.
 */
final class ContentProjectOpsCounterTransitionMap
{
    public const ACTION_MARK_VIEWED = 'mark_viewed';

    public const ACTION_RETRY = 'retry';

    public const ACTION_ENQUEUE = 'enqueue';

    /** Approve from In Review (Content Manager handoff). */
    public const ACTION_APPROVE = 'approve';

    /** Approve from Needs Review (still unread). */
    public const ACTION_APPROVE_FROM_NEEDS_REVIEW = 'approve_from_needs_review';

    /**
     * Planner/Manager self-edit: already left Needs Review (mark viewed), skip In Review.
     * Only Approved +1 — Needs Review already decremented by mark_viewed.
     */
    public const ACTION_APPROVE_SELF_EDIT = 'approve_self_edit';

    /** Content Manager canonical Save: Needs Review → In Review (reporting only). */
    public const ACTION_CONTENT_MANAGER_HANDOFF = 'content_manager_handoff';

    public const ACTION_SCHEDULE = 'schedule';

    /** Schedule while still in Needs Review presentation (no Approved required). */
    public const ACTION_SCHEDULE_FROM_NEEDS_REVIEW = 'schedule_from_needs_review';

    /** Schedule after Content Manager reporting stamp (In Review). */
    public const ACTION_SCHEDULE_FROM_REVIEW = 'schedule_from_review';

    public const ACTION_UNSCHEDULE = 'unschedule';

    public const ACTION_DEBUG_PUBLISHED_TO_SCHEDULED = 'debug_published_to_scheduled';

    public const ACTION_DEBUG_PUBLISHED_TO_APPROVED = 'debug_published_to_approved';

    public const ACTION_DEBUG_SCHEDULED_TO_APPROVED = 'debug_scheduled_to_approved';

    public const ACTION_DEBUG_APPROVED_TO_SCHEDULED = 'debug_approved_to_scheduled';

    public const ACTION_DEBUG_APPROVED_TO_PUBLISHED = 'debug_approved_to_published';

    public const ACTION_DEBUG_SCHEDULED_TO_PUBLISHED = 'debug_scheduled_to_published';

    /**
     * @return array<string, int> counter key => delta (atomic apply / rollback)
     */
    public static function deltas(string $action): array
    {
        return match ($action) {
            self::ACTION_MARK_VIEWED => ContentProjectOpsStateClassifier::deltaBetween(
                ContentProjectOpsStateClassifier::BUCKET_NEEDS_REVIEW,
                ContentProjectOpsStateClassifier::BUCKET_OTHER,
            ),
            self::ACTION_RETRY => ContentProjectOpsStateClassifier::deltaBetween(
                ContentProjectOpsStateClassifier::BUCKET_FAILED,
                ContentProjectOpsStateClassifier::BUCKET_PENDING,
            ),
            self::ACTION_ENQUEUE => ContentProjectOpsStateClassifier::deltaBetween(
                ContentProjectOpsStateClassifier::BUCKET_DRAFT,
                ContentProjectOpsStateClassifier::BUCKET_PENDING,
            ),
            self::ACTION_APPROVE => ContentProjectOpsStateClassifier::deltaBetween(
                ContentProjectOpsStateClassifier::BUCKET_IN_REVIEW,
                ContentProjectOpsStateClassifier::BUCKET_APPROVED,
            ),
            self::ACTION_APPROVE_FROM_NEEDS_REVIEW => ContentProjectOpsStateClassifier::deltaBetween(
                ContentProjectOpsStateClassifier::BUCKET_NEEDS_REVIEW,
                ContentProjectOpsStateClassifier::BUCKET_APPROVED,
            ),
            self::ACTION_APPROVE_SELF_EDIT => ContentProjectOpsStateClassifier::contribution(
                ContentProjectOpsStateClassifier::BUCKET_APPROVED,
            ),
            self::ACTION_CONTENT_MANAGER_HANDOFF => ContentProjectOpsStateClassifier::deltaBetween(
                ContentProjectOpsStateClassifier::BUCKET_NEEDS_REVIEW,
                ContentProjectOpsStateClassifier::BUCKET_IN_REVIEW,
            ),
            self::ACTION_SCHEDULE => ContentProjectOpsStateClassifier::deltaBetween(
                ContentProjectOpsStateClassifier::BUCKET_APPROVED,
                ContentProjectOpsStateClassifier::BUCKET_SCHEDULED,
            ),
            self::ACTION_SCHEDULE_FROM_NEEDS_REVIEW => ContentProjectOpsStateClassifier::deltaBetween(
                ContentProjectOpsStateClassifier::BUCKET_NEEDS_REVIEW,
                ContentProjectOpsStateClassifier::BUCKET_SCHEDULED,
            ),
            self::ACTION_SCHEDULE_FROM_REVIEW => ContentProjectOpsStateClassifier::deltaBetween(
                ContentProjectOpsStateClassifier::BUCKET_IN_REVIEW,
                ContentProjectOpsStateClassifier::BUCKET_SCHEDULED,
            ),
            self::ACTION_UNSCHEDULE => ContentProjectOpsStateClassifier::deltaBetween(
                ContentProjectOpsStateClassifier::BUCKET_SCHEDULED,
                ContentProjectOpsStateClassifier::BUCKET_APPROVED,
            ),
            self::ACTION_DEBUG_PUBLISHED_TO_SCHEDULED => ContentProjectOpsStateClassifier::deltaBetween(
                ContentProjectOpsStateClassifier::BUCKET_PUBLISHED,
                ContentProjectOpsStateClassifier::BUCKET_SCHEDULED,
            ),
            self::ACTION_DEBUG_PUBLISHED_TO_APPROVED => ContentProjectOpsStateClassifier::deltaBetween(
                ContentProjectOpsStateClassifier::BUCKET_PUBLISHED,
                ContentProjectOpsStateClassifier::BUCKET_APPROVED,
            ),
            self::ACTION_DEBUG_SCHEDULED_TO_APPROVED => ContentProjectOpsStateClassifier::deltaBetween(
                ContentProjectOpsStateClassifier::BUCKET_SCHEDULED,
                ContentProjectOpsStateClassifier::BUCKET_APPROVED,
            ),
            self::ACTION_DEBUG_APPROVED_TO_SCHEDULED => ContentProjectOpsStateClassifier::deltaBetween(
                ContentProjectOpsStateClassifier::BUCKET_APPROVED,
                ContentProjectOpsStateClassifier::BUCKET_SCHEDULED,
            ),
            self::ACTION_DEBUG_APPROVED_TO_PUBLISHED => ContentProjectOpsStateClassifier::deltaBetween(
                ContentProjectOpsStateClassifier::BUCKET_APPROVED,
                ContentProjectOpsStateClassifier::BUCKET_PUBLISHED,
            ),
            self::ACTION_DEBUG_SCHEDULED_TO_PUBLISHED => ContentProjectOpsStateClassifier::deltaBetween(
                ContentProjectOpsStateClassifier::BUCKET_SCHEDULED,
                ContentProjectOpsStateClassifier::BUCKET_PUBLISHED,
            ),
            default => [],
        };
    }

    public static function debugAction(string $from, string $to): ?string
    {
        $from = strtolower(trim($from));
        $to = strtolower(trim($to));
        if ($from === 'waiting_publish') {
            $from = 'scheduled';
        }
        if ($to === 'waiting_publish') {
            $to = 'scheduled';
        }

        return match ($from.'_'.$to) {
            'published_scheduled' => self::ACTION_DEBUG_PUBLISHED_TO_SCHEDULED,
            'published_approved' => self::ACTION_DEBUG_PUBLISHED_TO_APPROVED,
            'scheduled_approved' => self::ACTION_DEBUG_SCHEDULED_TO_APPROVED,
            'approved_scheduled' => self::ACTION_DEBUG_APPROVED_TO_SCHEDULED,
            'approved_published' => self::ACTION_DEBUG_APPROVED_TO_PUBLISHED,
            'scheduled_published' => self::ACTION_DEBUG_SCHEDULED_TO_PUBLISHED,
            default => null,
        };
    }

    /**
     * Resolve approve counter action from row / article review state.
     *
     * @param  array<string, mixed>  $row
     */
    public static function approveActionForRow(array $row): string
    {
        if (! empty($row['is_content_manager_reviewed'])) {
            return self::ACTION_APPROVE;
        }

        $reviewStatus = strtolower(trim((string) ($row['review_status'] ?? '')));
        // Legacy handoff residue.
        if (in_array($reviewStatus, ['pending_review', 'pending'], true)) {
            return self::ACTION_APPROVE;
        }

        if (! empty($row['is_recently_completed'])) {
            return self::ACTION_APPROVE_FROM_NEEDS_REVIEW;
        }

        return self::ACTION_APPROVE_SELF_EDIT;
    }

    /**
     * Resolve schedule counter action from row reporting/lifecycle state.
     *
     * @param  array<string, mixed>  $row
     */
    public static function scheduleActionForRow(array $row): string
    {
        $lifecycle = strtolower(trim((string) ($row['lifecycle'] ?? '')));
        $reviewStatus = strtolower(trim((string) ($row['review_status'] ?? '')));

        if ($lifecycle === 'approved' || $reviewStatus === 'approved') {
            return self::ACTION_SCHEDULE;
        }

        if (! empty($row['is_content_manager_reviewed'])) {
            return self::ACTION_SCHEDULE_FROM_REVIEW;
        }

        if (in_array($reviewStatus, ['pending_review', 'pending'], true)) {
            return self::ACTION_SCHEDULE_FROM_REVIEW;
        }

        if (! empty($row['is_recently_completed'])) {
            return self::ACTION_SCHEDULE_FROM_NEEDS_REVIEW;
        }

        if ($lifecycle === 'review') {
            return self::ACTION_SCHEDULE_FROM_NEEDS_REVIEW;
        }

        return self::ACTION_SCHEDULE;
    }

    /**
     * @return array<string, array<string, int>>
     */
    public static function all(): array
    {
        $keys = [
            self::ACTION_MARK_VIEWED,
            self::ACTION_RETRY,
            self::ACTION_ENQUEUE,
            self::ACTION_APPROVE,
            self::ACTION_APPROVE_FROM_NEEDS_REVIEW,
            self::ACTION_APPROVE_SELF_EDIT,
            self::ACTION_CONTENT_MANAGER_HANDOFF,
            self::ACTION_SCHEDULE,
            self::ACTION_SCHEDULE_FROM_NEEDS_REVIEW,
            self::ACTION_SCHEDULE_FROM_REVIEW,
            self::ACTION_UNSCHEDULE,
            self::ACTION_DEBUG_PUBLISHED_TO_SCHEDULED,
            self::ACTION_DEBUG_PUBLISHED_TO_APPROVED,
            self::ACTION_DEBUG_SCHEDULED_TO_APPROVED,
            self::ACTION_DEBUG_APPROVED_TO_SCHEDULED,
            self::ACTION_DEBUG_APPROVED_TO_PUBLISHED,
            self::ACTION_DEBUG_SCHEDULED_TO_PUBLISHED,
        ];
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = self::deltas($key);
        }

        return $out;
    }
}
