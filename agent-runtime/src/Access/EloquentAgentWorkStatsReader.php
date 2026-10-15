<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Access;

use App\Core\Sites\SiteAccess;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectNeedsEditingIndex;
use Omnichannel\Addons\Seo\Models\SeoArticleProfile;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Throwable;

final class EloquentAgentWorkStatsReader implements AgentWorkStatsReader
{
    public function summarize(AgentActorAccessContext $context, string $kind, WorkStatsWindow $window): array
    {
        $team = $this->isTeamKind($kind);
        $memberIds = $team && $context->role === SeoAccessControl::ROLE_CONTENT_MANAGER
            ? $this->contentManagerIds($context)
            : [];
        if ($team && $context->role === SeoAccessControl::ROLE_CONTENT_MANAGER && $memberIds === []) {
            return $this->emptyStats($kind, 'Không xác định được quan hệ team từ tài khoản hiện tại. So sánh nhân sự không được mở rộng ngoài phạm vi này.');
        }

        $siteIds = $context->siteIds;
        if ($team && $context->role === SeoAccessControl::ROLE_CONTENT_MANAGER && $siteIds === []) {
            $siteIds = $this->siteIdsFor($context->userId);
        }
        if ($team && $context->role === SeoAccessControl::ROLE_CONTENT_MANAGER && $siteIds === []) {
            return $this->emptyStats($kind, 'Không xác định được site trong phạm vi tài khoản. Bảng xếp hạng không lấy dữ liệu ngoài site được phép.');
        }

        $projects = $this->projects($context, $window, $team, $memberIds, $siteIds);
        $projectIds = array_map(static fn (SeoProject $project): int => (int) $project->getKey(), $projects);
        $openProjectIds = [];
        $userByProject = [];
        foreach ($projects as $project) {
            $projectId = (int) $project->getKey();
            $userByProject[$projectId] = (int) $project->user_id;
            if ((string) $project->status !== SeoProject::STATUS_COMPLETED) {
                $openProjectIds[] = $projectId;
            }
        }

        $taskRows = $projectIds === []
            ? []
            : SeoProjectTask::query()->active()
                ->whereIn('project_id', $projectIds)
                ->where('status', '!=', SeoProjectTask::STATUS_ARCHIVED)
                ->get([
                    'id',
                    'project_id',
                    'status',
                    'type',
                    'article_id',
                    'content_manager_reviewed_at',
                    'publish_queue_status',
                    'publish_published_at',
                    'publishing_queued_at',
                ])
                ->all();
        $needsReviewIds = array_fill_keys($this->needsReviewTaskIds($taskRows, (int) $context->userId, $openProjectIds), true);

        $open = 0;
        $done = 0;
        $openByProject = [];
        $assignedByUser = [];
        $completedByUser = [];
        $articlesByUser = [];
        foreach ($taskRows as $task) {
            $projectId = (int) $task->project_id;
            $status = (string) $task->status;
            $userId = $userByProject[$projectId] ?? 0;
            if ($status === SeoProjectTask::STATUS_CANCELLED) {
                continue;
            }
            if ($userId > 0) {
                $assignedByUser[$userId] = ($assignedByUser[$userId] ?? 0) + 1;
            }
            if ($status === SeoProjectTask::STATUS_COMPLETED) {
                $done++;
                if ($userId > 0) {
                    $completedByUser[$userId] = ($completedByUser[$userId] ?? 0) + 1;
                }
            }
            if (isset($needsReviewIds[(int) $task->id])) {
                $open++;
                $openByProject[$projectId] = ($openByProject[$projectId] ?? 0) + 1;
            }
            $articleId = (int) ($task->article_id ?? 0);
            if ($userId > 0 && $articleId > 0) {
                $articlesByUser[$userId][$articleId] = $articleId;
            }
        }

        $articleIds = [];
        foreach ($articlesByUser as $ids) {
            foreach ($ids as $articleId) {
                $articleIds[$articleId] = $articleId;
            }
        }
        $scores = $this->rankableScores(array_values($articleIds));
        $ownArticleIds = [];
        if (! $team) {
            foreach ($taskRows as $task) {
                $articleId = (int) ($task->article_id ?? 0);
                if ($articleId > 0 && (string) $task->status !== SeoProjectTask::STATUS_CANCELLED) {
                    $ownArticleIds[$articleId] = $articleId;
                }
            }
        }
        $average = $this->average(array_intersect_key($scores, $ownArticleIds));

        $members = [];
        $lowestUser = null;
        $slowestUser = null;
        $mostUser = null;
        if ($team) {
            $roster = $context->role === SeoAccessControl::ROLE_CONTENT_MANAGER
                ? $memberIds
                : array_values(array_unique(array_filter($userByProject, static fn (int $id): bool => $id > 0)));
            $members = $this->members($roster, $assignedByUser, $completedByUser, $articlesByUser, $scores, $kind);
            $lowestUser = $this->labeledScore($members, true);
            $slowestUser = $this->labeledCount($members, 'completed', true);
            $mostUser = $this->labeledCount($members, 'completed', false);
        }

        return [
            'open_tasks' => $open,
            'completed_tasks' => $done,
            'average_seo_score' => $average,
            'project_count' => count($projectIds),
            'lowest_score_user' => $lowestUser,
            'lowest_output_user' => $slowestUser,
            'most_completed_user' => $mostUser,
            'members' => $members,
            'projects' => $team ? [] : $this->breakdown($projects, $openByProject),
            'limitation' => null,
            'kind' => $kind,
        ];
    }

    /**
     * @param  list<int>  $memberIds
     * @param  list<int>  $siteIds
     * @return list<SeoProject>
     */
    private function projects(AgentActorAccessContext $context, WorkStatsWindow $window, bool $team, array $memberIds, array $siteIds): array
    {
        $query = SeoProject::query();
        if ($team && $context->role === SeoAccessControl::ROLE_CONTENT_MANAGER) {
            $query->whereIn('user_id', $memberIds);
            $query->where(function (Builder $inner) use ($siteIds): void {
                $inner->whereIn('site_id', $siteIds)->orWhereNull('site_id');
            });
        } else {
            $ids = $context->authorizedProjectIds();
            $query->whereIn('id', $ids === [] ? [-1] : $ids);
        }
        if (! $context->includeArchivedProjects) {
            $query->whereNull('archived_at');
        }
        $query->where(function (Builder $inner): void {
            $inner->whereNull('kind')->orWhere('kind', '!=', SeoProject::KIND_ARCHIVE);
        });
        if ($window->mode !== 'all' && $window->from !== null && $window->to !== null) {
            $query->whereBetween('month', [$window->from, $window->to]);
        }

        return $query->get(['id', 'user_id', 'status', 'name', 'month'])->all();
    }

    /**
     * @param  list<SeoProjectTask>  $tasks
     * @param  list<int>  $openProjectIds
     * @return list<int>
     */
    public function needsReviewTaskIds(array $tasks, int $viewerId, array $openProjectIds): array
    {
        return ContentProjectNeedsEditingIndex::taskIds($tasks, $viewerId, $openProjectIds);
    }

    /**
     * @return list<int>
     */
    private function contentManagerIds(AgentActorAccessContext $context): array
    {
        $actor = $context->userId > 0 ? User::query()->find($context->userId) : null;
        if (! $actor instanceof User) {
            return [];
        }
        $ownerId = (int) ($actor->accountOwnerId() ?? 0);
        if ($ownerId <= 0) {
            return [];
        }

        $ids = [];
        $staff = User::query()->where('parent_id', $ownerId)->where('role', User::ROLE_STAFF)->get();
        foreach ($staff as $member) {
            if (SeoAccessControl::roleForUser($member) === SeoAccessControl::ROLE_CONTENT_MANAGER) {
                $ids[] = (int) $member->getKey();
            }
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function siteIdsFor(int $userId): array
    {
        $user = User::query()->find($userId);
        if (! $user instanceof User) {
            return [];
        }
        try {
            return app(SiteAccess::class)->accessibleSiteIds($user);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<int>  $articleIds
     * @return array<int, float>
     */
    private function rankableScores(array $articleIds): array
    {
        if ($articleIds === []) {
            return [];
        }

        $rows = SeoArticleProfile::query()
            ->whereIn('article_id', $articleIds)
            ->whereNotNull('seo_score')
            ->where(function (Builder $inner): void {
                $inner->where('skip_seo_score', false)->orWhereNull('skip_seo_score');
            })
            ->get(['article_id', 'seo_score']);

        $scores = [];
        foreach ($rows as $row) {
            $scores[(int) $row->article_id] = (float) $row->seo_score;
        }

        return $scores;
    }

    /**
     * @param  array<int, float>  $scores
     */
    private function average(array $scores): ?float
    {
        if ($scores === []) {
            return null;
        }

        return round(array_sum($scores) / count($scores), 1);
    }

    /**
     * @param  list<int>  $roster
     * @param  array<int, int>  $assignedByUser
     * @param  array<int, int>  $completedByUser
     * @param  array<int, array<int, int>>  $articlesByUser
     * @param  array<int, float>  $scores
     * @return list<array{user_id: int, name: string, assigned: int, completed: int, average_seo_score: float|null, rank: int|null}>
     */
    private function members(array $roster, array $assignedByUser, array $completedByUser, array $articlesByUser, array $scores, string $kind): array
    {
        $rows = [];
        foreach ($roster as $userId) {
            $userId = (int) $userId;
            if ($userId <= 0 || isset($rows[$userId])) {
                continue;
            }
            $articleScores = [];
            foreach ($articlesByUser[$userId] ?? [] as $articleId) {
                if (isset($scores[$articleId])) {
                    $articleScores[] = $scores[$articleId];
                }
            }
            $rows[$userId] = [
                'user_id' => $userId,
                'name' => $this->userLabel($userId),
                'assigned' => (int) ($assignedByUser[$userId] ?? 0),
                'completed' => (int) ($completedByUser[$userId] ?? 0),
                'average_seo_score' => $articleScores === [] ? null : round(array_sum($articleScores) / count($articleScores), 1),
                'rank' => null,
            ];
        }

        $list = array_values($rows);
        $byScore = $kind === 'leaderboard' || $kind === 'lowest_score';
        $lowestFirst = $kind === 'lowest_score' || $kind === 'lowest_output';
        usort($list, static function (array $a, array $b) use ($byScore, $lowestFirst): int {
            if ($byScore) {
                $aScore = $a['average_seo_score'];
                $bScore = $b['average_seo_score'];
                if ($aScore === null && $bScore !== null) {
                    return 1;
                }
                if ($aScore !== null && $bScore === null) {
                    return -1;
                }
                if ($aScore !== null && $bScore !== null && $aScore !== $bScore) {
                    return $lowestFirst ? $aScore <=> $bScore : $bScore <=> $aScore;
                }
            } else {
                $count = $lowestFirst ? $a['completed'] <=> $b['completed'] : $b['completed'] <=> $a['completed'];
                if ($count !== 0) {
                    return $count;
                }
            }

            return $a['user_id'] <=> $b['user_id'];
        });

        $rank = 0;
        foreach ($list as $index => $row) {
            if ($byScore && $row['average_seo_score'] === null) {
                $list[$index]['rank'] = null;
                continue;
            }
            $rank++;
            $list[$index]['rank'] = $rank;
        }

        return $list;
    }

    /**
     * @param  list<array{name: string, average_seo_score: float|null}>  $members
     */
    private function labeledScore(array $members, bool $lowest): ?string
    {
        $picked = null;
        foreach ($members as $row) {
            if ($row['average_seo_score'] === null) {
                continue;
            }
            if ($picked === null) {
                $picked = $row;
                continue;
            }
            $better = $lowest
                ? $row['average_seo_score'] < $picked['average_seo_score']
                : $row['average_seo_score'] > $picked['average_seo_score'];
            if ($better) {
                $picked = $row;
            }
        }

        return $picked === null ? null : $picked['name'].' ('.$picked['average_seo_score'].')';
    }

    /**
     * @param  list<array{name: string, completed: int}>  $members
     */
    private function labeledCount(array $members, string $field, bool $lowest): ?string
    {
        if ($members === []) {
            return null;
        }
        $picked = null;
        foreach ($members as $row) {
            if ($picked === null) {
                $picked = $row;
                continue;
            }
            $value = (int) ($row[$field] ?? 0);
            $best = (int) ($picked[$field] ?? 0);
            $better = $lowest ? $value < $best : $value > $best;
            if ($better) {
                $picked = $row;
            }
        }

        return $picked['name'].' ('.(int) ($picked[$field] ?? 0).')';
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyStats(string $kind, string $limitation): array
    {
        return [
            'open_tasks' => 0,
            'completed_tasks' => 0,
            'average_seo_score' => null,
            'project_count' => 0,
            'lowest_score_user' => null,
            'lowest_output_user' => null,
            'most_completed_user' => null,
            'members' => [],
            'projects' => [],
            'limitation' => $limitation,
            'kind' => $kind,
        ];
    }

    /**
     * @param  list<SeoProject>  $projects
     * @param  array<int, int>  $openByProject
     * @return list<array{id: int, name: string, month: string, outstanding: int}>
     */
    private function breakdown(array $projects, array $openByProject): array
    {
        $rows = [];
        foreach ($projects as $project) {
            $id = (int) $project->getKey();
            $outstanding = (int) ($openByProject[$id] ?? 0);
            if ($id <= 0 || $outstanding <= 0) {
                continue;
            }
            $month = $project->month;
            $rows[] = [
                'id' => $id,
                'name' => trim((string) ($project->name ?? '')) !== '' ? (string) $project->name : 'Content Project '.$id,
                'month' => $month ? Carbon::parse($month)->format('m/Y') : '',
                'outstanding' => $outstanding,
            ];
        }

        return $rows;
    }

    private function isTeamKind(string $kind): bool
    {
        return in_array($kind, ['lowest_score', 'lowest_output', 'leaderboard', 'most_completed'], true);
    }

    private function userLabel(int $userId): string
    {
        $name = User::query()->whereKey($userId)->value('name');

        return is_string($name) && $name !== '' ? $name : 'user:'.$userId;
    }
}
