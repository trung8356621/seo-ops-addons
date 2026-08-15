<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Progress;

use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;

/**
 * Human labels for the frozen 7-step Site Sync orchestrator.
 */
final class SiteSyncStepCatalog
{
    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return SiteSyncSchema::ORCHESTRATOR_STEPS;
    }

    public static function totalSteps(): int
    {
        return count(self::keys());
    }

    public static function order(string $stepKey): int
    {
        $index = array_search($stepKey, self::keys(), true);

        return $index === false ? 0 : $index + 1;
    }

    public static function label(string $stepKey): string
    {
        return match ($stepKey) {
            'detect_capability' => 'Kiểm tra khả năng plugin',
            'request_snapshot_delta' => 'Lấy dữ liệu WordPress',
            'sync_site_profile' => 'Đồng bộ hồ sơ site',
            'sync_url_catalog' => 'Đồng bộ bài viết và URL',
            'sync_provider_keywords' => 'Đồng bộ từ khóa',
            'missing_capability_fallback' => 'Bổ sung dữ liệu thiếu',
            'finalize' => 'Hoàn tất dữ liệu SEO',
            'validate_changed_links' => 'Kiểm tra liên kết thay đổi',
            'score_missing_articles' => 'Chấm điểm SEO',
            default => $stepKey !== '' ? $stepKey : '—',
        };
    }

    /**
     * @param  iterable<int, object{step_key?: mixed, status?: mixed, step_order?: mixed}>|iterable<int, array<string, mixed>>  $rows
     * @return list<array{key: string, label: string, status: string, order: int}>
     */
    public static function timeline(iterable $rows): array
    {
        $byKey = [];
        foreach ($rows as $row) {
            $key = is_array($row) ? (string) ($row['step_key'] ?? '') : (string) ($row->step_key ?? '');
            $status = is_array($row) ? (string) ($row['status'] ?? 'pending') : (string) ($row->status ?? 'pending');
            $order = is_array($row)
                ? (int) ($row['step_order'] ?? self::order($key))
                : (int) ($row->step_order ?? self::order($key));
            if ($key === '') {
                continue;
            }
            $byKey[$key] = [
                'key' => $key,
                'label' => self::label($key),
                'status' => $status,
                'order' => $order,
            ];
        }

        $out = [];
        foreach (self::keys() as $i => $key) {
            $out[] = $byKey[$key] ?? [
                'key' => $key,
                'label' => self::label($key),
                'status' => 'pending',
                'order' => $i + 1,
            ];
        }

        return $out;
    }
}
