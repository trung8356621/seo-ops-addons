<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectTaskStatus;
use InvalidArgumentException;

/**
 * Canonical task.status enum + legacy mapping (Batch D).
 * Fail closed on unknown free-form values.
 */
final class ContentProjectTaskStatusNormalizer
{
    /**
     * Legacy aliases → canonical SeoProjectTaskStatus value.
     *
     * @var array<string, string>
     */
    private const LEGACY_MAP = [
        'draft' => 'draft',
        'pending' => 'pending',
        'waiting' => 'pending',
        'queued' => 'pending',
        'writing' => 'writing',
        'running' => 'writing',
        'processing' => 'processing',
        'reviewing' => 'reviewing',
        'in_review' => 'reviewing',
        'completed' => 'completed',
        'done' => 'completed',
        'success' => 'completed',
        'failed' => 'failed',
        'error' => 'failed',
        'archived' => 'archived',
        'cancelled' => 'cancelled',
        'canceled' => 'cancelled',
        'skipped' => 'cancelled',
    ];

    public static function tryNormalize(?string $raw): ?SeoProjectTaskStatus
    {
        $key = strtolower(trim((string) $raw));
        if ($key === '') {
            return null;
        }

        $mapped = self::LEGACY_MAP[$key] ?? null;
        if ($mapped === null) {
            return SeoProjectTaskStatus::tryFrom($key);
        }

        return SeoProjectTaskStatus::tryFrom($mapped);
    }

    public static function normalizeOrFail(?string $raw): SeoProjectTaskStatus
    {
        $status = self::tryNormalize($raw);
        if (! $status instanceof SeoProjectTaskStatus) {
            throw new InvalidArgumentException('Unknown task status: '.(string) $raw);
        }

        return $status;
    }

    /**
     * Statuses Admin may set via manual override (excludes archive/cancel — dedicated commands).
     *
     * @return list<string>
     */
    public static function manualOverrideValues(): array
    {
        return [
            SeoProjectTaskStatus::Draft->value,
            SeoProjectTaskStatus::Pending->value,
            SeoProjectTaskStatus::Writing->value,
            SeoProjectTaskStatus::Processing->value,
            SeoProjectTaskStatus::Reviewing->value,
            SeoProjectTaskStatus::Completed->value,
            SeoProjectTaskStatus::Failed->value,
        ];
    }

    /**
     * Select options for manual status switch — values are canonical enum strings.
     *
     * @return array<string, string> value => label
     */
    public static function manualOverrideOptions(): array
    {
        $labels = [
            SeoProjectTaskStatus::Draft->value => self::t('badge_draft', 'Draft'),
            SeoProjectTaskStatus::Pending->value => self::t('run_item_pending', 'Chưa chạy'),
            SeoProjectTaskStatus::Writing->value => self::t('badge_generating_active', 'Generating'),
            SeoProjectTaskStatus::Processing->value => self::t('badge_processing', 'Processing'),
            SeoProjectTaskStatus::Reviewing->value => self::t('badge_review', 'Review'),
            SeoProjectTaskStatus::Completed->value => self::t('badge_generated', 'Đã tạo'),
            SeoProjectTaskStatus::Failed->value => self::t('badge_failed', 'Failed'),
        ];

        $out = [];
        foreach (self::manualOverrideValues() as $value) {
            $out[$value] = $labels[$value] ?? $value;
        }

        return $out;
    }

    private static function t(string $key, string $fallback): string
    {
        try {
            $translated = __('seo-content-ai::filament.projects.'.$key);
            if (! is_string($translated) || $translated === '' || $translated === 'seo-content-ai::filament.projects.'.$key) {
                return $fallback;
            }

            return $translated;
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * @return list<string>
     */
    public static function canonicalValues(): array
    {
        return SeoProjectTaskStatus::values();
    }

    /**
     * @return array<string, string>
     */
    public static function legacyMap(): array
    {
        return self::LEGACY_MAP;
    }
}
