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
