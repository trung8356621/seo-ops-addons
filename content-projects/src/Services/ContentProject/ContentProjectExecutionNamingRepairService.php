<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use App\Services\Users\SeoOpsSystemUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Normalize Execution Project display names per writer + month.
 * Does not merge/move items — rename only.
 */
final class ContentProjectExecutionNamingRepairService
{
    public function __construct(
        private readonly ContentProjectExecutionPackingService $packing,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function previewGroups(?string $month = null, ?int $userId = null): array
    {
        $groups = [];
        foreach ($this->discoverWriterMonths($month, $userId) as $pair) {
            $plan = $this->planRename($pair['user_id'], $pair['month']);
            $groups[] = array_merge($plan, [
                'user_name' => $pair['user_name'],
                'needs_work' => ($plan['renames'] ?? []) !== [],
            ]);
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
                $results[] = array_merge($group, ['applied' => false, 'reason' => 'already_normalized']);

                continue;
            }

            if ($dryRun) {
                $results[] = array_merge($group, ['applied' => false, 'reason' => 'dry_run']);

                continue;
            }

            try {
                $this->applyRenames($group['renames'] ?? []);
                $repaired++;
                $results[] = array_merge($group, ['applied' => true]);
            } catch (\Throwable $e) {
                Log::error('content_project.execution_naming_repair.failed', [
                    'user_id' => $group['user_id'] ?? null,
                    'month' => $group['month'] ?? null,
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
     * @return array{
     *     user_id: int,
     *     month: string,
     *     month_label: string,
     *     before: list<string>,
     *     after: list<string>,
     *     renames: list<array{project_id: int, from: string, to: string}>
     * }
     */
    public function planRename(int $userId, Carbon|string $month): array
    {
        $monthDate = Carbon::parse($month)->startOfMonth()->format('Y-m-d');
        $base = SeoProject::defaultNameFromMonth($monthDate);

        $projects = SeoProject::query()
            ->activeProjects()
            ->where('user_id', $userId)
            ->whereDate('month', $monthDate)
            ->where('status', '!=', SeoProject::STATUS_DRAFT)
            ->where(function ($builder): void {
                $builder
                    ->where('kind', SeoProject::KIND_MONTHLY)
                    ->orWhereNull('kind');
            })
            ->orderBy('id')
            ->get();

        $mutable = $projects
            ->filter(fn (SeoProject $p): bool => $this->packing->isReusable($p))
            ->values();

        $before = [];
        $after = [];
        $renames = [];

        foreach ($mutable as $index => $project) {
            $from = trim((string) ($project->name ?? ''));
            $to = $index === 0 ? $base : $base.'-'.($index + 1);
            $before[] = $from;
            $after[] = $to;
            if ($from !== $to) {
                $renames[] = [
                    'project_id' => (int) $project->getKey(),
                    'from' => $from,
                    'to' => $to,
                ];
            }
        }

        return [
            'user_id' => $userId,
            'month' => $monthDate,
            'month_label' => Carbon::parse($monthDate)->format('m/Y'),
            'before' => $before,
            'after' => $after,
            'renames' => $renames,
        ];
    }

    /**
     * @param  list<array{project_id: int, from: string, to: string}>  $renames
     */
    public function applyRenames(array $renames): void
    {
        if ($renames === []) {
            return;
        }

        DB::connection('omi_seo_ai')->transaction(function () use ($renames): void {
            // Two-phase rename avoids transient collisions within the same writer/month.
            $temps = [];
            foreach ($renames as $i => $row) {
                $projectId = (int) ($row['project_id'] ?? 0);
                if ($projectId <= 0) {
                    continue;
                }
                $project = SeoProject::query()->whereKey($projectId)->lockForUpdate()->first();
                if (! $project instanceof SeoProject) {
                    throw new \RuntimeException('Project missing during naming repair: '.$projectId);
                }
                if (! $this->packing->isReusable($project)) {
                    throw new \RuntimeException('Project not mutable for naming repair: '.$projectId);
                }
                $temp = '__rename_tmp_'.$projectId.'_'.$i;
                $project->forceFill(['name' => $temp])->save();
                $temps[] = ['project' => $project, 'to' => (string) $row['to']];
            }

            foreach ($temps as $item) {
                /** @var SeoProject $project */
                $project = $item['project'];
                $project->forceFill(['name' => $item['to']])->save();
            }
        });
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

        $names = app(\Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterMonthlyCapacityService::class)
            ->displayNamesByUserId(
                $rows->pluck('user_id')->map(static fn ($id): int => (int) $id)->all(),
            );

        $pairs = [];
        foreach ($rows as $row) {
            $uid = (int) ($row->user_id ?? 0);
            if ($uid <= 0 || SeoOpsSystemUser::isSystemUserId($uid)) {
                continue;
            }
            $pairs[] = [
                'user_id' => $uid,
                'user_name' => (string) ($names[$uid] ?? '#'.$uid),
                'month' => Carbon::parse((string) $row->month)->startOfMonth()->format('Y-m-d'),
            ];
        }

        return $pairs;
    }
}
