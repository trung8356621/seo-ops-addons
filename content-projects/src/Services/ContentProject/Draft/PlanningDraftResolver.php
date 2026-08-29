<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Canonical shared Planning Draft pool.
 * Draft is NOT month-versioned and NOT domain-bound — items own site_id.
 */
final class PlanningDraftResolver
{
    /**
     * Find the canonical shared Planning Draft (any site_id / null).
     * Prefer most recently updated active draft.
     */
    public function findCanonicalSharedDraft(): ?SeoProject
    {
        $drafts = $this->baseQuery()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->filter(static fn (mixed $p): bool => $p instanceof SeoProject && $p->isDraftPlanning())
            ->values();

        if ($drafts->isEmpty()) {
            return null;
        }

        if ($drafts->count() > 1) {
            Log::warning('content_project.planning_draft.multiple_shared_detected', [
                'draft_ids' => $drafts->map(static fn (SeoProject $p): int => (int) $p->getKey())->all(),
                'canonical_id' => (int) $drafts->first()->getKey(),
            ]);
        }

        $canonical = $drafts->first();

        return $canonical instanceof SeoProject ? $canonical : null;
    }

    /**
     * @deprecated Prefer {@see findCanonicalSharedDraft()} — Draft is no longer per-site.
     * Kept for callers; ignores site uniqueness and returns the shared pool
     * (or a draft that happens to match site_id when several still exist).
     */
    public function findPlanningDraftForSite(int $siteId): ?SeoProject
    {
        if ($siteId > 0) {
            $forSite = $this->baseQuery()
                ->where('site_id', $siteId)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->limit(5)
                ->get()
                ->filter(static fn (mixed $p): bool => $p instanceof SeoProject && $p->isDraftPlanning())
                ->values();

            if ($forSite->isNotEmpty()) {
                if ($forSite->count() > 1) {
                    Log::warning('content_project.planning_draft.duplicate_detected', [
                        'site_id' => $siteId,
                        'draft_ids' => $forSite->map(static fn (SeoProject $p): int => (int) $p->getKey())->all(),
                        'canonical_id' => (int) $forSite->first()->getKey(),
                    ]);
                }

                $hit = $forSite->first();

                return $hit instanceof SeoProject ? $hit : null;
            }
        }

        return $this->findCanonicalSharedDraft();
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

    /**
     * @return list<SeoProject>
     */
    public function listAllActiveDrafts(): array
    {
        return $this->baseQuery()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->filter(static fn (mixed $p): bool => $p instanceof SeoProject && $p->isDraftPlanning())
            ->values()
            ->all();
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
