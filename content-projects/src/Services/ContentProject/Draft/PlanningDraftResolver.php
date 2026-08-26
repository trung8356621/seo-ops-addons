<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Canonical resolver: one reusable Planning Draft per site/domain.
 */
final class PlanningDraftResolver
{
    /**
     * Find the canonical reusable Planning Draft for a site.
     * When legacy duplicates exist, picks safest candidate and logs — no destructive merge.
     */
    public function findPlanningDraftForSite(int $siteId): ?SeoProject
    {
        if ($siteId <= 0) {
            return null;
        }

        $drafts = $this->baseQuery()
            ->where('site_id', $siteId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->filter(static fn (mixed $p): bool => $p instanceof SeoProject && $p->isDraftPlanning())
            ->values();

        if ($drafts->isEmpty()) {
            return null;
        }

        if ($drafts->count() > 1) {
            Log::warning('content_project.planning_draft.duplicate_detected', [
                'site_id' => $siteId,
                'draft_ids' => $drafts->map(static fn (SeoProject $p): int => (int) $p->getKey())->all(),
                'canonical_id' => (int) $drafts->first()->getKey(),
            ]);
        }

        $canonical = $drafts->first();

        return $canonical instanceof SeoProject ? $canonical : null;
    }

    /**
     * @return list<SeoProject>
     */
    public function listDuplicateDraftsForSite(int $siteId): array
    {
        if ($siteId <= 0) {
            return [];
        }

        $drafts = $this->baseQuery()
            ->where('site_id', $siteId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->filter(static fn (mixed $p): bool => $p instanceof SeoProject && $p->isDraftPlanning())
            ->values();

        if ($drafts->count() <= 1) {
            return [];
        }

        return $drafts->all();
    }

    private function baseQuery(): Builder
    {
        return SeoProjectResource::getRecordRouteBindingEloquentQuery()
            ->with('site:id,domain')
            ->where('status', SeoProject::STATUS_DRAFT)
            ->activeProjects()
            ->where(function (Builder $q): void {
                $q->whereNull('kind')->orWhere('kind', '!=', SeoProject::KIND_ARCHIVE);
            });
    }
}
