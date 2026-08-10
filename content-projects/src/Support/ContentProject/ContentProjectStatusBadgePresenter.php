<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Semantic status badges + summary card accents — Filament tokens, dark-mode safe.
 * Labels via lang keys (EN fallback for pure PHPUnit without translator).
 *
 * @phpstan-type Badge array{key: string, label: string, classes: string, icon: string}
 * @phpstan-type SummaryAccent array{key: string, accent: string, icon: string, ring: string}
 */
final class ContentProjectStatusBadgePresenter
{
    /**
     * @return Badge
     */
    public static function generation(string $status, ?string $executionStatus = null): array
    {
        $status = strtolower(trim($status));
        $exec = strtolower(trim((string) $executionStatus));

        if ($status === 'writing' || $exec === 'running') {
            return self::badge('running', self::t('badge_running', 'Running'), 'heroicon-o-arrow-path', 'bg-info-100 text-info-800 ring-info-600/30 dark:bg-info-400/15 dark:text-info-300 dark:ring-info-400/40');
        }
        if ($exec === 'retrying') {
            return self::badge('retrying', self::t('badge_retrying', 'Retrying'), 'heroicon-o-arrow-uturn-right', 'bg-warning-100 text-warning-900 ring-warning-600/40 dark:bg-warning-400/15 dark:text-warning-200 dark:ring-warning-400/50');
        }
        // Latest attempt failure wins over sticky completed task status.
        if ($status === 'failed' || in_array($exec, ['failed', 'error', 'cancelled', 'stopped', 'timeout'], true)) {
            return self::badge('failed', self::t('badge_failed', 'Failed'), 'heroicon-o-x-circle', 'bg-danger-100 text-danger-800 ring-danger-600/30 dark:bg-danger-400/15 dark:text-danger-300 dark:ring-danger-400/40');
        }
        // Generated only when generation status completed AND latest exec is not a failure.
        // Do not treat bare exec success without completed status as Generated (lifecycle ≠ generation).
        if (in_array($status, ['completed', 'reviewing'], true)
            && ($exec === '' || in_array($exec, ['success', 'completed'], true))
        ) {
            return self::badge('success', self::t('badge_generated', 'Generated'), 'heroicon-o-check-circle', 'bg-success-100 text-success-800 ring-success-600/30 dark:bg-success-400/15 dark:text-success-300 dark:ring-success-400/40');
        }

        return self::badge('pending', self::t('badge_pending', 'Pending'), 'heroicon-o-clock', 'bg-gray-200/80 text-gray-800 ring-gray-500/30 dark:bg-gray-500/20 dark:text-gray-200 dark:ring-gray-400/30');
    }

    /**
     * @return Badge
     */
    public static function lifecycle(string $phase): array
    {
        return match (strtolower(trim($phase))) {
            'generating' => self::badge('generating', self::t('badge_generating', 'Generating'), 'heroicon-o-sparkles', 'bg-info-100 text-info-800 ring-info-600/30 dark:bg-info-400/15 dark:text-info-300 dark:ring-info-400/40'),
            'review' => self::badge('review', self::t('badge_review', 'Review'), 'heroicon-o-eye', 'bg-warning-100 text-warning-900 ring-warning-600/30 dark:bg-warning-400/15 dark:text-warning-200 dark:ring-warning-400/40'),
            'approved' => self::badge('approved', self::t('badge_approved', 'Approved'), 'heroicon-o-hand-thumb-up', 'bg-success-100 text-success-800 ring-success-600/30 dark:bg-success-400/15 dark:text-success-300 dark:ring-success-400/40'),
            'waiting_publish' => self::badge('scheduled', self::t('badge_scheduled', 'Scheduled'), 'heroicon-o-calendar-days', 'bg-primary-100 text-primary-800 ring-primary-600/30 dark:bg-primary-400/15 dark:text-primary-300 dark:ring-primary-400/40'),
            'published' => self::badge('published', self::t('badge_published', 'Published'), 'heroicon-o-globe-alt', 'bg-success-100 text-success-900 ring-success-700/30 dark:bg-success-400/20 dark:text-success-200 dark:ring-success-400/50'),
            'failed' => self::badge('failed', self::t('badge_failed', 'Failed'), 'heroicon-o-x-circle', 'bg-danger-100 text-danger-800 ring-danger-600/30 dark:bg-danger-400/15 dark:text-danger-300 dark:ring-danger-400/40'),
            'archived' => self::badge('archived', self::t('badge_archived', 'Archived'), 'heroicon-o-archive-box', 'bg-gray-200/80 text-gray-700 ring-gray-500/30 dark:bg-gray-500/20 dark:text-gray-300 dark:ring-gray-400/30'),
            default => self::badge('draft', self::t('badge_draft', 'Draft'), 'heroicon-o-document', 'bg-gray-200/80 text-gray-800 ring-gray-500/30 dark:bg-gray-500/20 dark:text-gray-200 dark:ring-gray-400/30'),
        };
    }

    /**
     * @return Badge
     */
    public static function queue(string $status): array
    {
        $normalized = strtolower(trim($status === '' ? 'none' : $status));

        return match ($normalized) {
            'waiting' => self::badge('waiting', self::t('badge_waiting', 'Waiting'), 'heroicon-o-queue-list', 'bg-warning-100 text-warning-900 ring-warning-600/30 dark:bg-warning-400/15 dark:text-warning-200 dark:ring-warning-400/40'),
            'processing' => self::badge('processing', self::t('badge_processing', 'Processing'), 'heroicon-o-cpu-chip', 'bg-info-100 text-info-800 ring-info-600/30 dark:bg-info-400/15 dark:text-info-300 dark:ring-info-400/40'),
            'retrying' => self::badge('retrying', self::t('badge_retrying', 'Retrying'), 'heroicon-o-arrow-uturn-right', 'bg-warning-100 text-warning-900 ring-warning-600/40 dark:bg-warning-400/15 dark:text-warning-200 dark:ring-warning-400/50'),
            'failed' => self::badge('failed', self::t('badge_failed', 'Failed'), 'heroicon-o-x-circle', 'bg-danger-100 text-danger-800 ring-danger-600/30 dark:bg-danger-400/15 dark:text-danger-300 dark:ring-danger-400/40'),
            'published' => self::badge('published', self::t('badge_published', 'Published'), 'heroicon-o-check-badge', 'bg-success-100 text-success-800 ring-success-600/30 dark:bg-success-400/15 dark:text-success-300 dark:ring-success-400/40'),
            'skipped' => self::badge('skipped', self::t('badge_skipped', 'Skipped'), 'heroicon-o-minus-circle', 'bg-gray-200/80 text-gray-700 ring-gray-500/30 dark:bg-gray-500/20 dark:text-gray-300 dark:ring-gray-400/30'),
            'cancelled' => self::badge('skipped', self::t('badge_cancelled', 'Cancelled'), 'heroicon-o-minus-circle', 'bg-gray-200/80 text-gray-700 ring-gray-500/30 dark:bg-gray-500/20 dark:text-gray-300 dark:ring-gray-400/30'),
            default => self::badge('none', self::t('badge_none', 'None'), 'heroicon-o-minus', 'bg-gray-100 text-gray-600 ring-gray-400/30 dark:bg-gray-800 dark:text-gray-400 dark:ring-gray-600/40'),
        };
    }

    /**
     * Publishing Queue summary/list state chip.
     *
     * @return Badge
     */
    public static function publishQueueState(string $state): array
    {
        return match (strtolower(trim($state))) {
            'unscheduled' => self::badge('unscheduled', 'Chưa lên lịch', 'heroicon-o-clock', 'bg-gray-200/80 text-gray-800 ring-gray-500/30 dark:bg-gray-500/20 dark:text-gray-200 dark:ring-gray-400/30'),
            'scheduled' => self::badge('scheduled', 'Đã lên lịch', 'heroicon-o-calendar-days', 'bg-primary-100 text-primary-800 ring-primary-600/30 dark:bg-primary-400/15 dark:text-primary-300 dark:ring-primary-400/40'),
            'awaiting_delivery', 'awaiting_worker' => self::badge('awaiting_delivery', 'Đang chuẩn bị', 'heroicon-o-queue-list', 'bg-amber-100 text-amber-900 ring-amber-600/30 dark:bg-amber-400/15 dark:text-amber-200 dark:ring-amber-400/40'),
            'publishing' => self::badge('publishing', 'Đang xuất bản', 'heroicon-o-arrow-path', 'bg-info-100 text-info-800 ring-info-600/30 dark:bg-info-400/15 dark:text-info-300 dark:ring-info-400/40'),
            'retry_wait' => self::badge('retry_wait', 'Thử lại sau', 'heroicon-o-arrow-uturn-left', 'bg-warning-100 text-warning-900 ring-warning-600/30 dark:bg-warning-400/15 dark:text-warning-200 dark:ring-warning-400/40'),
            'published' => self::badge('published', self::t('badge_published', 'Đã xuất bản'), 'heroicon-o-globe-alt', 'bg-success-100 text-success-900 ring-success-700/30 dark:bg-success-400/20 dark:text-success-200 dark:ring-success-400/50'),
            'failed' => self::badge('failed', self::t('badge_failed', 'Không thể xuất bản'), 'heroicon-o-x-circle', 'bg-danger-100 text-danger-800 ring-danger-600/30 dark:bg-danger-400/15 dark:text-danger-300 dark:ring-danger-400/40'),
            'needs_attention' => self::badge('needs_attention', 'Cần xử lý', 'heroicon-o-exclamation-triangle', 'bg-danger-50 text-danger-900 ring-danger-500/40 dark:bg-danger-400/10 dark:text-danger-200 dark:ring-danger-400/40'),
            default => self::badge('none', self::t('badge_none', 'None'), 'heroicon-o-minus', 'bg-gray-100 text-gray-600 ring-gray-400/30 dark:bg-gray-800 dark:text-gray-400 dark:ring-gray-600/40'),
        };
    }

    /**
     * Workflow / publishing column — Scheduled / Published / Failed only.
     * Draft / Pending / Approved / reporting keys return null (dash in UI).
     *
     * @return Badge|null
     */
    public static function workflow(string $key): ?array
    {
        return match (strtolower(trim($key))) {
            'scheduled', 'waiting_publish' => self::badge('scheduled', self::t('badge_scheduled', 'Scheduled'), 'heroicon-o-calendar-days', 'bg-primary-100 text-primary-800 ring-primary-600/30 dark:bg-primary-400/15 dark:text-primary-300 dark:ring-primary-400/40'),
            'published' => self::badge('published', self::t('badge_published', 'Published'), 'heroicon-o-globe-alt', 'bg-success-100 text-success-900 ring-success-700/30 dark:bg-success-400/20 dark:text-success-200 dark:ring-success-400/50'),
            'failed' => self::badge('failed', self::t('badge_failed', 'Failed'), 'heroicon-o-x-circle', 'bg-danger-100 text-danger-800 ring-danger-600/30 dark:bg-danger-400/15 dark:text-danger-300 dark:ring-danger-400/40'),
            default => null,
        };
    }

    /**
     * Reporting chip — null key yields empty badge (caller must hide).
     *
     * @return Badge|null
     */
    public static function reporting(?string $key): ?array
    {
        $key = strtolower(trim((string) $key));
        if ($key === '' || $key === 'null') {
            return null;
        }

        return match ($key) {
            'needs_review' => self::badge(
                'needs_review',
                self::t('badge_needs_review', 'Needs Review'),
                'heroicon-o-pencil-square',
                'bg-primary-50 text-primary-800 ring-primary-600/30 dark:bg-primary-400/15 dark:text-primary-300 dark:ring-primary-400/40',
            ),
            'in_review' => self::badge(
                'in_review',
                self::t('badge_reviewed_by_content_manager', 'Reviewed by Content Manager'),
                'heroicon-o-eye',
                'bg-warning-100 text-warning-900 ring-warning-600/30 dark:bg-warning-400/15 dark:text-warning-200 dark:ring-warning-400/40',
            ),
            default => null,
        };
    }

    /**
     * @return SummaryAccent
     */
    public static function summaryAccent(string $card): array
    {
        return match ($card) {
            'pending' => ['key' => 'pending', 'accent' => 'text-gray-700 dark:text-gray-200', 'icon' => 'heroicon-o-clock', 'ring' => 'border-l-gray-400'],
            'draft' => ['key' => 'draft', 'accent' => 'text-gray-700 dark:text-gray-200', 'icon' => 'heroicon-o-document', 'ring' => 'border-l-gray-400'],
            'normal' => ['key' => 'normal', 'accent' => 'text-gray-700 dark:text-gray-200', 'icon' => 'heroicon-o-document', 'ring' => 'border-l-gray-400'],
            'recently_completed' => ['key' => 'recently_completed', 'accent' => 'text-primary-700 dark:text-primary-300', 'icon' => 'heroicon-o-inbox', 'ring' => 'border-l-primary-500'],
            'running' => ['key' => 'running', 'accent' => 'text-info-700 dark:text-info-300', 'icon' => 'heroicon-o-arrow-path', 'ring' => 'border-l-info-500'],
            'failed' => ['key' => 'failed', 'accent' => 'text-danger-700 dark:text-danger-300', 'icon' => 'heroicon-o-x-circle', 'ring' => 'border-l-danger-500'],
            'review' => ['key' => 'review', 'accent' => 'text-warning-800 dark:text-warning-300', 'icon' => 'heroicon-o-eye', 'ring' => 'border-l-warning-500'],
            'approved' => ['key' => 'approved', 'accent' => 'text-success-700 dark:text-success-300', 'icon' => 'heroicon-o-hand-thumb-up', 'ring' => 'border-l-success-500'],
            'unscheduled' => ['key' => 'unscheduled', 'accent' => 'text-gray-700 dark:text-gray-200', 'icon' => 'heroicon-o-clock', 'ring' => 'border-l-gray-400'],
            'scheduled' => ['key' => 'scheduled', 'accent' => 'text-primary-700 dark:text-primary-300', 'icon' => 'heroicon-o-calendar-days', 'ring' => 'border-l-primary-500'],
            'publishing' => ['key' => 'publishing', 'accent' => 'text-info-700 dark:text-info-300', 'icon' => 'heroicon-o-arrow-path', 'ring' => 'border-l-info-500'],
            'published' => ['key' => 'published', 'accent' => 'text-success-800 dark:text-success-200', 'icon' => 'heroicon-o-globe-alt', 'ring' => 'border-l-success-600'],
            default => ['key' => 'total', 'accent' => 'text-gray-900 dark:text-white', 'icon' => 'heroicon-o-squares-2x2', 'ring' => 'border-l-gray-500'],
        };
    }

    /**
     * @return Badge
     */
    private static function badge(string $key, string $label, string $icon, string $colorClasses): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'classes' => 'inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-semibold ring-1 ring-inset '.$colorClasses,
        ];
    }

    private static function t(string $key, string $fallback): string
    {
        try {
            $translated = __('seo-content-ai::filament.projects.'.$key);
            if (! is_string($translated) || $translated === '') {
                return $fallback;
            }
            if ($translated === 'seo-content-ai::filament.projects.'.$key) {
                return $fallback;
            }

            return $translated;
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
