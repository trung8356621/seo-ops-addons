<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;

/**
 * Canonical active/terminal helpers cho content-project run items.
 * Normalize alias cũ tại một chỗ — không dùng $status !== 'completed'.
 */
final class ContentProjectExecutionStatus
{
    /**
     * Runtime statuses thực sự còn chạy (seo_project_run_items).
     *
     * @return list<string>
     */
    public static function activeStatuses(): array
    {
        return [
            SeoProjectRunItemStatus::Pending->value,
            SeoProjectRunItemStatus::Processing->value,
        ];
    }

    /**
     * Terminal statuses (canonical + alias đã normalize).
     *
     * @return list<string>
     */
    public static function terminalStatuses(): array
    {
        return [
            SeoProjectRunItemStatus::Success->value,
            'completed',
            SeoProjectRunItemStatus::Failed->value,
            'error',
            'cancelled',
            'canceled',
            'stopped',
            'ignored_stale',
            'blocked',
            SeoProjectRunItemStatus::Skipped->value,
            'timeout',
            'timed_out',
            SeoProjectRunItemStatus::Manual->value,
        ];
    }

    public static function normalize(?string $status): string
    {
        $raw = strtolower(trim((string) $status));

        return match ($raw) {
            'success', 'completed', 'done' => SeoProjectRunItemStatus::Success->value,
            'error', 'failed' => SeoProjectRunItemStatus::Failed->value,
            'canceled', 'cancelled', 'stopped' => 'cancelled',
            'timed_out', 'timeout' => 'timeout',
            'queued', 'dispatching', 'running' => self::mapRuntimeAlias($raw),
            default => $raw,
        };
    }

    public static function isActive(?string $status): bool
    {
        $normalized = self::normalize($status);

        return in_array($normalized, self::activeStatuses(), true)
            || in_array($normalized, ['queued', 'dispatching', 'running'], true);
    }

    public static function isTerminal(?string $status): bool
    {
        if (self::isActive($status)) {
            return false;
        }

        $normalized = self::normalize($status);

        return in_array($normalized, [
            SeoProjectRunItemStatus::Success->value,
            SeoProjectRunItemStatus::Failed->value,
            SeoProjectRunItemStatus::Skipped->value,
            SeoProjectRunItemStatus::Manual->value,
            'cancelled',
            'ignored_stale',
            'blocked',
            'timeout',
        ], true);
    }

    private static function mapRuntimeAlias(string $alias): string
    {
        // Alias runtime ngoài enum — vẫn coi active nếu lọt vào DB/legacy.
        return match ($alias) {
            'queued', 'dispatching' => SeoProjectRunItemStatus::Pending->value,
            'running' => SeoProjectRunItemStatus::Processing->value,
            default => $alias,
        };
    }
}
