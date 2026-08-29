<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\SplitDraftContentProjectService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use App\Services\Users\SeoOpsSystemUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * One-time / on-demand compaction of fragmented mutable Execution Projects
 * for the same writer + execution month using {@see ContentProjectExecutionPackingService}.
 */
final class ContentProjectExecutionPackingRepairService
{
    public function __construct(
        private readonly ContentProjectExecutionPackingService $packing,
        private readonly SplitDraftContentProjectService $splitter,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function previewGroups(?string $month = null, ?int $userId = null): array
    {
        $groups = [];
        foreach ($this->discoverWriterMonths($month, $userId) as $pair) {
            $plan = $this->packing->planRepack($pair['user_id'], $pair['month']);
            $beforeCounts = array_column($plan['before'], 'item_count');
            $afterCounts = array_column($plan['bins'], 'item_count');
            $needsWork = $beforeCounts !== $afterCounts
                || ($plan['empty_project_ids'] ?? []) !== []
                || count(array_filter($plan['bins'], static fn (array $b): bool => empty($b['project_id']))) > 0;

            $groups[] = [
                'user_id' => $pair['user_id'],
                'user_name' => $pair['user_name'],
                'month' => $plan['month'],
                'month_label' => Carbon::parse($plan['month'])->format('m/Y'),
                'before' => $beforeCounts,
                'after' => $afterCounts,
                'before_projects' => $plan['before'],
                'empty_project_ids' => $plan['empty_project_ids'],
                'skipped_projects' => $plan['skipped_projects'],
                'task_count' => $plan['task_count'],
                'needs_work' => $needsWork && $plan['task_count'] > 0,
                'plan' => $plan,
            ];
        }

        return $groups;
    }

    /**
     * @return array{repaired: int, skipped: int, groups: list<array<string, mixed>>}
     */
    public function repair(?string $month = null, ?int $userId = null, bool $dryRun = true): array
    {
        $groups = $this->previewGroups($month, $userId);
        $repaired = 0;
        $skipped = 0;
        $results = [];

        foreach ($groups as $group) {
            if (! ($group['needs_work'] ?? false)) {
                $skipped++;
                $results[] = array_merge($group, ['applied' => false, 'reason' => 'already_compact']);

                continue;
            }

            if ($dryRun) {
                $results[] = array_merge($group, ['applied' => false, 'reason' => 'dry_run']);

                continue;
            }

            try {
                $apply = $this->packing->applyRepack(
                    $group['plan'],
                    fn (int $uid, Carbon $m, array $reserved): string => $this->splitter->nextExecutionProjectName($uid, $m, $reserved),
                );
                $repaired++;
                $results[] = array_merge($group, [
                    'applied' => true,
                    'apply' => $apply,
                ]);
            } catch (\Throwable $e) {
                Log::error('content_project.execution_packing_repair.failed', [
                    'user_id' => $group['user_id'],
                    'month' => $group['month'],
                    'message' => $e->getMessage(),
                ]);
                $results[] = array_merge($group, [
                    'applied' => false,
                    'reason' => 'error:'.$e->getMessage(),
                ]);
            }
        }

        return [
            'repaired' => $repaired,
            'skipped' => $skipped,
            'groups' => $results,
        ];
    }

    /**
     * @return list<array{user_id: int, user_name: string, month: string}>
     */
    private function discoverWriterMonths(?string $month, ?int $userId): array
    {
        $query = SeoProject::query()
            ->activeProjects()
            ->where('status', '!=', SeoProject::STATUS_DRAFT)
            ->where(function ($builder): void {
                $builder
                    ->where('kind', SeoProject::KIND_MONTHLY)
                    ->orWhereNull('kind');
            })
            ->whereNotNull('user_id')
            ->where('user_id', '>', 0);

        if ($userId !== null && $userId > 0) {
            $query->where('user_id', $userId);
        }

        if ($month !== null && trim($month) !== '') {
            $normalized = ContentProjectMonthContext::normalize($month);
            $query->whereDate('month', ContentProjectMonthContext::toDateString($normalized));
        }

        $rows = $query
            ->select(['user_id', 'month'])
            ->selectRaw('COUNT(*) as project_count')
            ->groupBy('user_id', 'month')
            ->havingRaw('COUNT(*) >= 1')
            ->orderBy('user_id')
            ->orderBy('month')
            ->get();

        $pairs = [];
        $names = app(\Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterMonthlyCapacityService::class)
            ->displayNamesByUserId(
                $rows->pluck('user_id')->map(static fn ($id): int => (int) $id)->all(),
            );

        foreach ($rows as $row) {
            $uid = (int) ($row->user_id ?? 0);
            if ($uid <= 0 || SeoOpsSystemUser::isSystemUserId($uid)) {
                continue;
            }
            $monthDate = Carbon::parse((string) $row->month)->startOfMonth()->format('Y-m-d');
            $pairs[] = [
                'user_id' => $uid,
                'user_name' => (string) ($names[$uid] ?? '#'.$uid),
                'month' => $monthDate,
            ];
        }

        return $pairs;
    }
}
