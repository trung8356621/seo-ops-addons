<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Illuminate\Support\Facades\DB;

/**
 * Persist safe site_id recovery for Draft items that display Domain = —.
 * Never infers site from keyword phrase.
 */
final class DraftItemDomainRepairService
{
    /**
     * @return array{
     *     total: int,
     *     already_had_site: int,
     *     repaired: int,
     *     still_missing: int,
     *     repaired_ids: list<int>,
     *     missing_ids: list<int>
     * }
     */
    public function repairProject(SeoProject $draft, bool $persist = true): array
    {
        $tasks = SeoProjectTask::query()
            ->where('project_id', (int) $draft->getKey())
            ->whereNull('archived_at')
            ->where('status', '!=', SeoProjectTask::STATUS_CANCELLED)
            ->with(['itemOrigin', 'article:id,site_id'])
            ->orderBy('id')
            ->get();

        $already = 0;
        $repaired = 0;
        $missing = 0;
        $repairedIds = [];
        $missingIds = [];

        foreach ($tasks as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            $current = (int) ($task->site_id ?? 0);
            if ($current > 0) {
                $already++;

                continue;
            }

            $recovered = $this->recoverSiteId($task, $draft);
            if ($recovered <= 0) {
                $missing++;
                $missingIds[] = (int) $task->getKey();

                continue;
            }

            if ($persist) {
                $task->forceFill(['site_id' => $recovered])->save();
            }
            $repaired++;
            $repairedIds[] = (int) $task->getKey();
        }

        return [
            'total' => $tasks->count(),
            'already_had_site' => $already,
            'repaired' => $repaired,
            'still_missing' => $missing,
            'repaired_ids' => $repairedIds,
            'missing_ids' => $missingIds,
        ];
    }

    public function recoverSiteId(SeoProjectTask $task, ?SeoProject $project = null): int
    {
        $articleId = (int) ($task->article_id ?? 0);
        if ($articleId > 0) {
            $fromArticle = (int) (SeoArticle::query()->whereKey($articleId)->value('site_id') ?? 0);
            if ($fromArticle > 0) {
                return $fromArticle;
            }
        }

        $origin = $task->relationLoaded('itemOrigin')
            ? $task->itemOrigin
            : SeoContentProjectItemOrigin::query()->where('project_task_id', (int) $task->getKey())->first();

        if ($origin instanceof SeoContentProjectItemOrigin) {
            $fromOriginArticle = (int) ($origin->source_article_id ?? 0);
            if ($fromOriginArticle > 0) {
                $site = (int) (SeoArticle::query()->whereKey($fromOriginArticle)->value('site_id') ?? 0);
                if ($site > 0) {
                    return $site;
                }
            }
        }

        if ($project instanceof SeoProject) {
            $legacyProjectSite = (int) ($project->site_id ?? 0);
            // Only when this Draft row is still a legacy per-site shell (not Shared).
            if ($legacyProjectSite > 0) {
                return $legacyProjectSite;
            }
        }

        return 0;
    }
}
