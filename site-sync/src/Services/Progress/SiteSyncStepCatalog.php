<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Progress;

use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;

/**
 * Human labels for the frozen 7-step Site Sync orchestrator (V2)
 * and V3 phase machine labels.
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

    /**
     * @return list<string>
     */
    public static function v3Keys(): array
    {
        return [
            SiteSyncV3Schema::PHASE_DISCOVER,
            SiteSyncV3Schema::PHASE_IMPORT,
            SiteSyncV3Schema::PHASE_RECONCILE_STALE,
            SiteSyncV3Schema::PHASE_CATCH_UP,
            SiteSyncV3Schema::PHASE_VERIFY,
            SiteSyncV3Schema::PHASE_COMPLETE,
        ];
    }

    public static function totalSteps(): int
    {
        return count(self::keys());
    }

    public static function v3TotalSteps(): int
    {
        return count(self::v3Keys());
    }

    public static function order(string $stepKey): int
    {
        $index = array_search($stepKey, self::keys(), true);

        return $index === false ? 0 : $index + 1;
    }

    public static function v3Order(string $phase): int
    {
        $index = array_search($phase, self::v3Keys(), true);

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
            // V3 phases (also resolved via v3Label)
            SiteSyncV3Schema::PHASE_DISCOVER => 'Đang chuẩn bị đồng bộ',
            SiteSyncV3Schema::PHASE_IMPORT => 'Đang đồng bộ dữ liệu',
            SiteSyncV3Schema::PHASE_RECONCILE_STALE => 'Đang đối soát dữ liệu cũ',
            SiteSyncV3Schema::PHASE_CATCH_UP => 'Đang kiểm tra thay đổi mới',
            SiteSyncV3Schema::PHASE_VERIFY => 'Đang xác minh dữ liệu',
            SiteSyncV3Schema::PHASE_COMPLETE => 'Hoàn tất',
            SiteSyncV3Schema::PHASE_NEEDS_ATTENTION => 'Cần xử lý',
            default => $stepKey !== '' ? $stepKey : '—',
        };
    }

    public static function v3Label(string $phase): string
    {
        return self::label($phase);
    }

    /**
     * User-facing V3 macro groups (presentation only — orchestrator stays 6 phases).
     *
     * @return list<array{key: string, label: string, phases: list<string>}>
     */
    public static function v3MacroGroups(): array
    {
        return [
            [
                'key' => 'prepare',
                'label' => 'Chuẩn bị',
                'phases' => [SiteSyncV3Schema::PHASE_DISCOVER],
            ],
            [
                'key' => 'sync_data',
                'label' => 'Đồng bộ dữ liệu',
                'phases' => [
                    SiteSyncV3Schema::PHASE_IMPORT,
                    SiteSyncV3Schema::PHASE_RECONCILE_STALE,
                    SiteSyncV3Schema::PHASE_CATCH_UP,
                ],
            ],
            [
                'key' => 'verify_finish',
                'label' => 'Xác minh & hoàn tất',
                'phases' => [
                    SiteSyncV3Schema::PHASE_VERIFY,
                    SiteSyncV3Schema::PHASE_COMPLETE,
                ],
            ],
        ];
    }

    public static function v3MacroTotalSteps(): int
    {
        return count(self::v3MacroGroups());
    }

    /**
     * Project frozen V2 7-step timeline into the same 3 user macros (presentation only).
     *
     * @return list<array{key: string, label: string, steps: list<string>}>
     */
    public static function v2MacroGroups(): array
    {
        return [
            [
                'key' => 'prepare',
                'label' => 'Chuẩn bị',
                'steps' => ['detect_capability', 'request_snapshot_delta'],
            ],
            [
                'key' => 'sync_data',
                'label' => 'Đồng bộ dữ liệu',
                'steps' => [
                    'sync_site_profile',
                    'sync_url_catalog',
                    'sync_provider_keywords',
                    'missing_capability_fallback',
                ],
            ],
            [
                'key' => 'verify_finish',
                'label' => 'Xác minh & hoàn tất',
                'steps' => ['finalize'],
            ],
        ];
    }

    /**
     * @param  list<array{key: string, label: string, status: string, order: int}>  $stepTimeline
     * @return list<array{key: string, label: string, status: string, order: int, phases: list<string>}>
     */
    public static function v2MacroTimeline(array $stepTimeline): array
    {
        $byKey = [];
        foreach ($stepTimeline as $row) {
            $byKey[(string) ($row['key'] ?? '')] = $row;
        }

        $out = [];
        foreach (self::v2MacroGroups() as $i => $group) {
            $statuses = [];
            foreach ($group['steps'] as $stepKey) {
                $statuses[] = (string) ($byKey[$stepKey]['status'] ?? 'pending');
            }

            $out[] = [
                'key' => $group['key'],
                'label' => $group['label'],
                'status' => self::aggregateMacroStatus($statuses),
                'order' => $i + 1,
                'phases' => $group['steps'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array{key: string, label: string, status: string, order: int, phases: list<string>}>
     */
    public static function v3MacroTimeline(string $currentPhase, string $runStatus): array
    {
        $phaseByKey = [];
        foreach (self::v3Timeline($currentPhase, $runStatus) as $row) {
            $phaseByKey[$row['key']] = $row;
        }

        $out = [];
        foreach (self::v3MacroGroups() as $i => $group) {
            $statuses = [];
            foreach ($group['phases'] as $phaseKey) {
                $statuses[] = (string) ($phaseByKey[$phaseKey]['status'] ?? 'pending');
            }

            $out[] = [
                'key' => $group['key'],
                'label' => $group['label'],
                'status' => self::aggregateMacroStatus($statuses),
                'order' => $i + 1,
                'phases' => $group['phases'],
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $statuses
     */
    private static function aggregateMacroStatus(array $statuses): string
    {
        if ($statuses === []) {
            return 'pending';
        }
        if (in_array('failed', $statuses, true) || in_array('needs_attention', $statuses, true)) {
            return 'failed';
        }
        if (in_array('running', $statuses, true)) {
            return 'running';
        }

        $allDone = true;
        $allPending = true;
        $hasDone = false;
        foreach ($statuses as $status) {
            $done = in_array($status, ['completed', 'skipped'], true);
            if (! $done) {
                $allDone = false;
            } else {
                $hasDone = true;
            }
            if ($status !== 'pending') {
                $allPending = false;
            }
        }
        if ($allDone) {
            return 'completed';
        }
        if ($allPending) {
            return 'pending';
        }
        // Partially completed within group without an explicit running marker.
        if ($hasDone) {
            return 'running';
        }

        return 'pending';
    }

    /**
     * @return list<array{key: string, label: string, status: string, order: int}>
     */
    public static function v3Timeline(string $currentPhase, string $runStatus): array
    {
        $keys = self::v3Keys();
        $currentOrder = self::v3Order($currentPhase);
        $isNeedsAttention = $runStatus === 'needs_attention'
            || $currentPhase === SiteSyncV3Schema::PHASE_NEEDS_ATTENTION;
        if ($isNeedsAttention && $currentOrder === 0) {
            $currentOrder = self::v3Order(SiteSyncV3Schema::PHASE_VERIFY);
        }
        $isComplete = in_array($runStatus, ['completed', 'completed_with_warnings'], true);

        $out = [];
        foreach ($keys as $i => $key) {
            $order = $i + 1;
            if ($isComplete || ($currentPhase === SiteSyncV3Schema::PHASE_COMPLETE && $key === SiteSyncV3Schema::PHASE_COMPLETE)) {
                $status = 'completed';
            } elseif ($isNeedsAttention && $order === $currentOrder) {
                $status = 'failed';
            } elseif ($order < $currentOrder) {
                $status = 'completed';
            } elseif ($order === $currentOrder) {
                $status = in_array($runStatus, ['pending', 'running'], true) ? 'running' : $runStatus;
            } else {
                $status = 'pending';
            }

            $out[] = [
                'key' => $key,
                'label' => self::v3Label($key),
                'status' => $status,
                'order' => $order,
            ];
        }

        return $out;
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
