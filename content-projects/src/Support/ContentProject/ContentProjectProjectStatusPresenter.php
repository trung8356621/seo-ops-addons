<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;

/**
 * Project-level status label + Filament badge color (list / detail / archive).
 * Archived is lifecycle flag (archived_at), not a seo_projects.status enum value.
 *
 * @phpstan-type Presentation array{key: string, label: string, color: string}
 */
final class ContentProjectProjectStatusPresenter
{
    /**
     * @return Presentation
     */
    public static function present(SeoProject $project): array
    {
        if ($project->isProjectArchived()) {
            return [
                'key' => 'archived',
                'label' => self::t('status_archived', 'Đã lưu trữ'),
                'color' => self::colorMap()['archived'],
            ];
        }

        return self::fromStatus((string) ($project->status ?? ''));
    }

    /**
     * Canonical Filament badge colors — single map for list / detail / archive.
     * draft=amber, active family=blue/green, archived=gray (via present()).
     *
     * @return array<string, string> status => color
     */
    public static function colorMap(): array
    {
        return [
            SeoProject::STATUS_DRAFT => 'warning',
            SeoProject::STATUS_PENDING => 'info',
            SeoProject::STATUS_MANUAL => 'primary',
            SeoProject::STATUS_RUNNING => 'success',
            SeoProject::STATUS_COMPLETED => 'success',
            SeoProject::STATUS_PAUSED => 'danger',
            SeoProject::STATUS_APPROVED => 'success',
            'archived' => 'gray',
        ];
    }

    /**
     * @return Presentation
     */
    public static function fromStatus(string $status): array
    {
        $status = strtolower(trim($status));
        $colors = self::colorMap();

        return match ($status) {
            SeoProject::STATUS_DRAFT => [
                'key' => 'draft',
                'label' => self::t('status_draft', 'Bản nháp'),
                'color' => $colors[SeoProject::STATUS_DRAFT],
            ],
            SeoProject::STATUS_PENDING => [
                'key' => 'pending',
                'label' => self::t('status_pending', 'Chờ duyệt'),
                'color' => $colors[SeoProject::STATUS_PENDING],
            ],
            SeoProject::STATUS_MANUAL => [
                'key' => 'manual',
                'label' => self::t('status_manual', 'Thủ công'),
                'color' => $colors[SeoProject::STATUS_MANUAL],
            ],
            SeoProject::STATUS_RUNNING => [
                'key' => 'running',
                'label' => self::t('status_running', 'Đang chạy'),
                'color' => $colors[SeoProject::STATUS_RUNNING],
            ],
            SeoProject::STATUS_COMPLETED => [
                'key' => 'completed',
                'label' => self::t('status_completed', 'Hoàn thành'),
                'color' => $colors[SeoProject::STATUS_COMPLETED],
            ],
            SeoProject::STATUS_PAUSED => [
                'key' => 'paused',
                'label' => self::t('status_paused', 'Tạm dừng'),
                'color' => $colors[SeoProject::STATUS_PAUSED],
            ],
            SeoProject::STATUS_APPROVED => [
                'key' => 'approved',
                'label' => self::t('status_approved', 'Đã duyệt'),
                'color' => $colors[SeoProject::STATUS_APPROVED],
            ],
            default => [
                'key' => $status !== '' ? $status : 'unknown',
                'label' => $status !== '' ? $status : '—',
                'color' => 'gray',
            ],
        };
    }

    public static function label(SeoProject $project): string
    {
        return self::present($project)['label'];
    }

    public static function color(SeoProject $project): string
    {
        return self::present($project)['color'];
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
