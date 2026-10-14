<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Access;

use App\Models\User;
use Illuminate\Support\Carbon;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Seo\Models\SeoArticleProfile;

final class EloquentAgentWorkStatsReader implements AgentWorkStatsReader
{
    public function summarize(AgentActorAccessContext $context, string $kind): array
    {
        $projectIds = $context->authorizedProjectIds();
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();
        $projects = SeoProject::query()->whereIn('id', $projectIds === [] ? [-1] : $projectIds);
        if (! $context->includeArchivedProjects) {
            $projects->whereNull('archived_at');
        }
        $projects->whereBetween('month', [$monthStart->toDateString(), $monthEnd->toDateString()]);
        $monthProjectIds = $projects->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        $tasks = SeoProjectTask::query()->active()->whereIn('project_id', $monthProjectIds === [] ? [-1] : $monthProjectIds);
        $open = (clone $tasks)->whereNotIn('status', [
            SeoProjectTask::STATUS_COMPLETED,
            SeoProjectTask::STATUS_ARCHIVED,
            SeoProjectTask::STATUS_CANCELLED,
        ])->count();
        $done = (clone $tasks)->where('status', SeoProjectTask::STATUS_COMPLETED)->count();
        $articleIds = (clone $tasks)->where('article_id', '>', 0)->pluck('article_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $average = $articleIds === []
            ? null
            : SeoArticleProfile::query()->whereIn('article_id', $articleIds)->avg('seo_score');

        $lowestUser = null;
        $slowestUser = null;
        if ($context->teamVisible && $monthProjectIds !== []) {
            $byUser = SeoProject::query()->whereIn('id', $monthProjectIds)->pluck('user_id', 'id');
            $scores = [];
            $output = [];
            foreach ($byUser as $projectId => $writerId) {
                $writerId = (int) $writerId;
                if ($writerId <= 0) {
                    continue;
                }
                $writerTasks = SeoProjectTask::query()->active()->where('project_id', (int) $projectId);
                $output[$writerId] = ($output[$writerId] ?? 0) + (clone $writerTasks)->where('status', SeoProjectTask::STATUS_COMPLETED)->count();
                $ids = (clone $writerTasks)->where('article_id', '>', 0)->pluck('article_id')->all();
                if ($ids !== []) {
                    $score = SeoArticleProfile::query()->whereIn('article_id', $ids)->avg('seo_score');
                    if ($score !== null) {
                        $scores[$writerId][] = (float) $score;
                    }
                }
            }
            $lowestUser = $this->lowestAverage($scores);
            if ($output !== []) {
                $sorted = $this->sortedAsc($output);
                $slowestId = (int) array_key_first($sorted);
                $slowestUser = $this->userLabel($slowestId).' ('.(int) $sorted[$slowestId].')';
            }
        }

        return [
            'open_tasks' => $open,
            'completed_tasks' => $done,
            'average_seo_score' => $average === null ? null : round((float) $average, 1),
            'project_count' => count($monthProjectIds),
            'lowest_score_user' => $lowestUser,
            'lowest_output_user' => $slowestUser,
            'kind' => $kind,
        ];
    }

    /**
     * @param  array<int, list<float>>  $scores
     */
    private function lowestAverage(array $scores): ?string
    {
        $bestId = null;
        $best = null;
        foreach ($scores as $userId => $values) {
            $avg = array_sum($values) / max(1, count($values));
            if ($best === null || $avg < $best) {
                $best = $avg;
                $bestId = (int) $userId;
            }
        }

        return $bestId === null ? null : $this->userLabel($bestId).' ('.round((float) $best, 1).')';
    }

    /**
     * @param  array<int, int>  $counts
     * @return array<int, int>
     */
    private function sortedAsc(array $counts): array
    {
        asort($counts);

        return $counts;
    }

    private function userLabel(int $userId): string
    {
        $name = User::query()->whereKey($userId)->value('name');

        return is_string($name) && $name !== '' ? $name : 'user:'.$userId;
    }
}
