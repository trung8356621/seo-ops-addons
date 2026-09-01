<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Canonical shared Planning Draft pool.
 * Draft is NOT month-versioned and NOT domain-bound — items own site_id.
 *
 * Canonical lookup: status=draft AND site_id IS NULL AND not archived.
 * Legacy per-site drafts (site_id NOT NULL) are never Add-to-Draft targets.
 *
 * Uses SeoProject::query() (connection-scoped tenant DB), NOT Filament user scope —
 * Shared Draft must resolve in CLI merge/audit and for all panel roles.
 */
final class PlanningDraftResolver
{
    /**
     * Find the canonical Shared Planning Draft (site_id MUST be null).
     */
    public function findCanonicalSharedDraft(): ?SeoProject
    {
        $drafts = $this->baseQuery()
            ->whereNull('site_id')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->filter(static fn (mixed $p): bool => $p instanceof SeoProject && $p->isDraftPlanning())
            ->values();

        if ($drafts->isEmpty()) {
            return null;
        }

        $canonical = $drafts->first();

        if ($drafts->count() > 1) {
            Log::warning('content_project.planning_draft.multiple_shared_detected', [
                'draft_ids' => $drafts->map(static fn (SeoProject $p): int => (int) $p->getKey())->all(),
                'canonical_id' => $canonical instanceof SeoProject ? (int) $canonical->getKey() : null,
            ]);
        }

        return $canonical instanceof SeoProject ? $canonical : null;
    }

    /**
     * @deprecated Draft is no longer per-site. Always returns {@see findCanonicalSharedDraft()}.
     * Site id is ignored — kept so leftover callers cannot reintroduce per-site resolution.
     */
    public function findPlanningDraftForSite(int $siteId): ?SeoProject
    {
        unset($siteId);

        return $this->findCanonicalSharedDraft();
    }

    /**
     * Forensic/migration: legacy active Draft bound to a site_id.
     */
    public function findLegacyPlanningDraftForSite(int $siteId): ?SeoProject
    {
        if ($siteId <= 0) {
            return null;
        }

        $forSite = $this->baseQuery()
            ->where('site_id', $siteId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->filter(static fn (mixed $p): bool => $p instanceof SeoProject && $p->isDraftPlanning())
            ->values();

        if ($forSite->isEmpty()) {
            return null;
        }

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
     * Active Draft rows (shared + legacy). Prefer {@see listLegacyPerSiteDrafts()} for merge.
     *
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

    /**
     * Legacy per-site drafts still active (must be merged then archived).
     *
     * @return list<SeoProject>
     */
    public function listLegacyPerSiteDrafts(): array
    {
        return $this->baseQuery()
            ->whereNotNull('site_id')
            ->orderBy('site_id')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->filter(static fn (mixed $p): bool => $p instanceof SeoProject && $p->isDraftPlanning())
            ->values()
            ->all();
    }

    private function baseQuery(): Builder
    {
        return SeoProject::query()
            ->with('site:id,domain')
            ->where('status', SeoProject::STATUS_DRAFT)
            ->activeProjects()
            ->where(function (Builder $q): void {
                $q->whereNull('kind')->orWhere('kind', '!=', SeoProject::KIND_ARCHIVE);
            });
    }
}
