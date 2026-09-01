<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compact Shared Planning Draft summary — items grouped by item.site_id.
 */
final class PlanningDraftDomainSummary
{
    /**
     * @return array{
     *     draft_id: int|null,
     *     total_items: int,
     *     rows: list<array{site_id: int, domain: string, count: int}>,
     *     empty: bool
     * }
     */
    public function forDraft(?SeoProject $draft): array
    {
        if (! $draft instanceof SeoProject || ! $draft->isDraftPlanning()) {
            return [
                'draft_id' => null,
                'total_items' => 0,
                'rows' => [],
                'empty' => true,
            ];
        }

        $draftId = (int) $draft->getKey();
        $query = DB::connection('omi_seo_ai')
            ->table('seo_project_tasks as t')
            ->where('t.project_id', $draftId)
            ->whereNull('t.archived_at')
            ->where('t.status', '!=', SeoProjectTask::STATUS_CANCELLED);

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'deleted_at')) {
            $query->whereNull('t.deleted_at');
        }

        $raw = $query
            ->groupBy('t.site_id')
            ->selectRaw('t.site_id as site_id, COUNT(t.id) as item_count')
            ->orderByDesc('item_count')
            ->get();

        $siteIds = [];
        foreach ($raw as $row) {
            $id = (int) ($row->site_id ?? 0);
            if ($id > 0) {
                $siteIds[] = $id;
            }
        }

        $domains = [];
        if ($siteIds !== []) {
            foreach (Site::query()->whereIn('id', $siteIds)->get(['id', 'domain']) as $site) {
                $domains[(int) $site->getKey()] = trim((string) ($site->domain ?? ''));
            }
        }

        $rows = [];
        $total = 0;
        foreach ($raw as $row) {
            $siteId = (int) ($row->site_id ?? 0);
            $count = max(0, (int) ($row->item_count ?? 0));
            if ($count <= 0) {
                continue;
            }
            $total += $count;
            $domain = $siteId > 0 ? ($domains[$siteId] ?? '') : '';
            $rows[] = [
                'site_id' => $siteId,
                'domain' => $domain !== '' ? $domain : ($siteId > 0 ? '#'.$siteId : '(no site)'),
                'count' => $count,
            ];
        }

        return [
            'draft_id' => $draftId,
            'total_items' => $total,
            'rows' => $rows,
            'empty' => $rows === [],
        ];
    }
}
