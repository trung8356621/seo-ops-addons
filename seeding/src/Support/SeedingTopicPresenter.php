<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Support;

use Omnichannel\Addons\Seeding\Models\SeedingReport;
use Omnichannel\Addons\Seeding\Models\SeedingTopic;

final class SeedingTopicPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function topic(SeedingTopic $topic): array
    {
        $links = is_array($topic->links_json) ? array_values($topic->links_json) : [];

        return [
            'id' => (int) $topic->id,
            'installation_id' => (string) $topic->installation_id,
            'created_by' => (int) $topic->created_by,
            'created_by_user_id' => (int) $topic->created_by,
            'created_by_display_name' => $topic->created_by_display_name,
            'title' => $topic->title,
            'full_text' => (string) $topic->full_text,
            'source_html' => $topic->source_html,
            'social_url' => $topic->social_url,
            'social_platform' => $topic->social_platform?->value,
            'social_platform_label' => $topic->social_platform?->label(),
            'source_type' => $topic->source_type?->value ?? 'manual',
            'status' => $topic->status?->value ?? 'shared',
            'status_label' => $topic->status?->label(),
            'state' => 'shared',
            'links' => $links,
            'links_count' => count($links),
            'preview' => $topic->preview(60),
            'max_comments_target' => (int) $topic->max_comments_target,
            'target_comments' => $topic->targetComments(),
            'completed_comments' => (int) $topic->completed_comments,
            'progress_percent' => $topic->progressPercent(),
            'member_count_at_share' => (int) $topic->member_count_at_share,
            'required_comments_per_user' => $topic->requiredCommentsPerUser(),
            'required_report_count' => $topic->requiredCommentsPerUser(),
            'shared_at' => $topic->shared_at?->toIso8601String(),
            'archived_at' => $topic->archived_at?->toIso8601String(),
            'paused_at' => $topic->paused_at?->toIso8601String(),
            'cancelled_at' => $topic->cancelled_at?->toIso8601String(),
            'completed_at' => $topic->completed_at?->toIso8601String(),
            'is_archived' => $topic->isArchived(),
            'created_at' => $topic->created_at?->toIso8601String(),
            'updated_at' => $topic->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function feedItem(
        SeedingTopic $topic,
        int $userId,
        int $currentUserReportCount,
        bool $eligible,
    ): array {
        $base = self::topic($topic);
        $required = $topic->requiredCommentsPerUser();

        return array_merge($base, [
            'current_user_report_count' => max(0, $currentUserReportCount),
            'required_report_count' => $required,
            'eligibility' => [
                'eligible' => $eligible,
                'is_author' => (int) $topic->created_by === $userId,
                'remaining' => max(0, $required - $currentUserReportCount),
                'global_remaining' => max(0, $topic->targetComments() - (int) $topic->completed_comments),
            ],
            'author' => [
                'id' => (int) $topic->created_by,
                'display_name' => $topic->created_by_display_name,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function managerRow(SeedingTopic $topic): array
    {
        $base = self::topic($topic);

        return array_merge($base, [
            'progress_label' => ((int) $topic->completed_comments).' / '.$topic->targetComments(),
            'creator_name' => $topic->created_by_display_name ?: ('#'.$topic->created_by),
            'created_date_label' => $topic->shared_at?->format('d/m/Y')
                ?? $topic->created_at?->format('d/m/Y'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function report(SeedingReport $report): array
    {
        return [
            'id' => (int) $report->id,
            'topic_id' => (int) $report->topic_id,
            'user_id' => (int) $report->user_id,
            'user_display_name' => $report->user_display_name,
            'comment_text' => (string) $report->comment_text,
            'seed_link_id' => $report->seed_link_id,
            'seed_url' => $report->seed_url,
            'proof_path' => $report->proof_path,
            'proof_mime' => $report->proof_mime,
            'proof_meta' => is_array($report->proof_meta) ? $report->proof_meta : null,
            'reported_at' => $report->reported_at?->toIso8601String(),
            'created_at' => $report->created_at?->toIso8601String(),
        ];
    }
}
