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
        $links = self::normalizeLinks($links);

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
     * Ensure Seeder always receives id / title / url / target_per_day from Topic snapshot.
     * Preserves legacy tlink:* ids and optional preview fields.
     *
     * @param  list<mixed>  $links
     * @return list<array<string, mixed>>
     */
    public static function normalizeLinks(array $links): array
    {
        $out = [];
        foreach ($links as $link) {
            if (is_string($link)) {
                $url = trim($link);
                if ($url === '') {
                    continue;
                }
                $out[] = [
                    'id' => 'tlink:'.md5(strtolower(rtrim($url, '/'))),
                    'title' => '',
                    'label' => '',
                    'url' => $url,
                    'target_per_day' => 0,
                ];
                continue;
            }
            if (! is_array($link)) {
                continue;
            }
            $url = trim((string) ($link['url'] ?? $link['normalized_url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $title = trim((string) ($link['title'] ?? $link['label'] ?? ''));
            $id = trim((string) ($link['id'] ?? ''));
            if ($id === '') {
                $id = 'tlink:'.md5(strtolower(rtrim($url, '/')));
            }
            $row = [
                'id' => $id,
                'title' => $title,
                'label' => $title,
                'url' => $url,
                'target_per_day' => max(0, (int) ($link['target_per_day'] ?? 0)),
            ];
            foreach (['normalized_url', 'preview_title', 'preview_description', 'preview_image_url', 'preview_domain'] as $extra) {
                if (array_key_exists($extra, $link) && $link[$extra] !== null && $link[$extra] !== '') {
                    $row[$extra] = $link[$extra];
                }
            }
            $out[] = $row;
        }

        return $out;
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

    /**
     * Manager inspection read-model — never exposes raw filesystem proof_path.
     *
     * @return array<string, mixed>
     */
    public static function managerReport(SeedingReport $report, ?string $assignmentTitle = null): array
    {
        $topic = $report->relationLoaded('topic') ? $report->topic : null;
        $comment = (string) $report->comment_text;
        $seedUrl = self::nullableTrim($report->seed_url);
        $seedLinkId = self::nullableTrim($report->seed_link_id);
        $hasProof = self::nullableTrim($report->proof_path) !== null
            && str_starts_with((string) ($report->proof_mime ?? 'image/'), 'image/');

        $proofMeta = is_array($report->proof_meta) ? $report->proof_meta : [];
        $safeMeta = [];
        foreach (['original_name', 'size', 'stored_at'] as $key) {
            if (array_key_exists($key, $proofMeta) && $proofMeta[$key] !== null && $proofMeta[$key] !== '') {
                $safeMeta[$key] = $proofMeta[$key];
            }
        }

        $title = $topic instanceof SeedingTopic
            ? self::nullableTrim($topic->title)
            : null;
        $preview = $topic instanceof SeedingTopic ? $topic->preview(80) : null;

        return [
            'id' => (int) $report->id,
            'reported_at' => $report->reported_at?->toIso8601String(),
            'reported_at_label' => $report->reported_at?->format('d/m/Y H:i') ?? '—',
            'user_id' => (int) $report->user_id,
            'user_display_name' => self::nullableTrim($report->user_display_name)
                ?? ('#'.(int) $report->user_id),
            'topic_id' => (int) $report->topic_id,
            'topic_title' => $title,
            'topic_preview' => $preview,
            'social_platform' => $topic?->social_platform?->value,
            'social_platform_label' => $topic?->social_platform?->label(),
            'social_url' => $topic instanceof SeedingTopic
                ? self::nullableTrim($topic->social_url)
                : null,
            'comment_text' => $comment,
            'comment_excerpt' => self::excerpt($comment, 100),
            'seed_link_id' => $seedLinkId,
            'seed_url' => $seedUrl,
            'seed_link_title' => self::nullableTrim($assignmentTitle),
            'seed_link_label' => self::seedLinkLabel($assignmentTitle, $seedUrl, $seedLinkId),
            'has_proof' => $hasProof,
            'proof_mime' => self::nullableTrim($report->proof_mime),
            'proof_meta' => $safeMeta !== [] ? $safeMeta : null,
            'proof_url' => $hasProof
                ? '/api/seeding/manager/reports/'.(int) $report->id.'/proof'
                : null,
            'is_approved' => $report->approved_at !== null,
            'approval_status' => $report->approved_at !== null ? 'approved' : 'pending',
            'approval_status_label' => $report->approved_at !== null ? 'Đã duyệt' : 'Chờ duyệt',
            'approved_at' => $report->approved_at?->toIso8601String(),
            'approved_at_label' => $report->approved_at?->format('d/m/Y H:i'),
            'approved_by' => $report->approved_by !== null ? (int) $report->approved_by : null,
        ];
    }

    private static function excerpt(string $text, int $max): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($normalized === '') {
            return '—';
        }
        if (mb_strlen($normalized) <= $max) {
            return $normalized;
        }

        return mb_substr($normalized, 0, $max).'…';
    }

    private static function seedLinkLabel(?string $assignmentTitle, ?string $seedUrl, ?string $seedLinkId): string
    {
        $title = self::nullableTrim($assignmentTitle);
        if ($title !== null) {
            return $title;
        }
        if ($seedUrl !== null) {
            $host = parse_url($seedUrl, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return $host;
            }

            return self::excerpt($seedUrl, 40);
        }

        return $seedLinkId ?? '—';
    }

    private static function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
