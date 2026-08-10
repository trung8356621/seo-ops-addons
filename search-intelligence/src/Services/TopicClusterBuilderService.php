<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;


use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Illuminate\Database\Eloquent\Builder;

final class TopicClusterBuilderService
{
    public function __construct(
        private readonly KeywordPersistenceService $keywordPersistence,
    ) {}

    public function resolvePillarKeyword(int $keywordId): ?Keyword
    {
        if ($keywordId <= 0) {
            return null;
        }

        $keyword = Keyword::query()->find($keywordId);
        if (! $keyword instanceof Keyword) {
            return null;
        }

        if ($keyword->parent_id === null) {
            return $keyword;
        }

        $parent = Keyword::query()->find((int) $keyword->parent_id);

        return $parent instanceof Keyword ? $parent : $keyword;
    }

    public function resolvePillarId(int $keywordId): ?int
    {
        $pillar = $this->resolvePillarKeyword($keywordId);

        return $pillar instanceof Keyword ? (int) $pillar->id : null;
    }

    public function markAsPillar(int $keywordId): Keyword
    {
        $keyword = $this->findKeywordOrFail($keywordId);
        $keyword->update(['parent_id' => null]);

        return $keyword->fresh() ?? $keyword;
    }

    public function assignParent(int $keywordId, int $parentId): Keyword
    {
        if ($keywordId === $parentId) {
            throw new \InvalidArgumentException(__('seo-content-ai::filament.keyword.cluster_assign_parent_self'));
        }

        $keyword = $this->findKeywordOrFail($keywordId);
        $parent = Keyword::query()->find($parentId);

        if (! $parent instanceof Keyword || $parent->parent_id !== null) {
            throw new \InvalidArgumentException(__('seo-content-ai::filament.keyword.cluster_assign_parent_invalid'));
        }

        $keyword->update(['parent_id' => $parentId]);

        return $keyword->fresh() ?? $keyword;
    }

    public function attachChild(int $pillarId, int $childId): Keyword
    {
        if ($pillarId === $childId) {
            throw new \InvalidArgumentException(__('seo-content-ai::filament.keyword.cluster_attach_self'));
        }

        $pillar = Keyword::query()->find($pillarId);
        if (! $pillar instanceof Keyword || $pillar->parent_id !== null) {
            throw new \InvalidArgumentException(__('seo-content-ai::filament.keyword.cluster_attach_invalid_pillar'));
        }

        $child = $this->findKeywordOrFail($childId);

        if ((int) ($child->parent_id ?? 0) === $pillarId) {
            return $child;
        }

        if ($this->isOtherPillar($childId, $pillarId)) {
            throw new \InvalidArgumentException(__('seo-content-ai::filament.keyword.cluster_attach_other_pillar'));
        }

        $child->update(['parent_id' => $pillarId]);

        return $child->fresh() ?? $child;
    }

    public function detachChild(int $childId): Keyword
    {
        $child = $this->findKeywordOrFail($childId);
        $child->update(['parent_id' => null]);

        return $child->fresh() ?? $child;
    }

    /**
     * @return array{attached: int, detached: int}
     */
    public function saveClusterRelationships(int $pillarId, array $childIds): array
    {
        if ($pillarId <= 0) {
            throw new \InvalidArgumentException(__('seo-content-ai::filament.keyword.cluster_attach_invalid_pillar'));
        }

        $pillar = Keyword::query()->find($pillarId);
        if (! $pillar instanceof Keyword || $pillar->parent_id !== null) {
            throw new \InvalidArgumentException(__('seo-content-ai::filament.keyword.cluster_attach_invalid_pillar'));
        }

        $childIds = collect($childIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0 && $id !== $pillarId)
            ->unique()
            ->values()
            ->all();

        $detached = Keyword::query()
            ->where('parent_id', $pillarId)
            ->when($childIds !== [], static fn (Builder $query) => $query->whereNotIn('id', $childIds))
            ->update(['parent_id' => null]);

        $attached = 0;
        foreach ($childIds as $childId) {
            $this->attachChild($pillarId, $childId);
            $attached++;
        }

        return [
            'attached' => $attached,
            'detached' => (int) $detached,
        ];
    }

    /**
     * @return list<array{id: int, phrase: string}>
     */
    public function reverseScanSuggestions(int $pillarId, ?int $siteId = null, int $limit = 100): array
    {
        if ($pillarId <= 0) {
            return [];
        }

        $siteId ??= SeoAccessControl::globalSiteId();
        $pillar = Keyword::query()->find($pillarId);
        if (! $pillar instanceof Keyword) {
            return [];
        }

        $needle = trim((string) $pillar->phrase);
        if ($needle === '') {
            return [];
        }

        $otherPillarIds = $this->otherPillarIds($pillarId);

        return $this->scopedKeywordQuery($siteId)
            ->where('id', '!=', $pillarId)
            ->whereNotIn('id', $otherPillarIds)
            ->where(function (Builder $builder) use ($pillarId): void {
                $builder
                    ->whereNull('parent_id')
                    ->orWhere('parent_id', $pillarId);
            })
            ->whereRaw('phrase COLLATE utf8mb4_unicode_ci LIKE ?', ['%'.$needle.'%'])
            ->orderBy('phrase')
            ->limit($limit)
            ->get(['id', 'phrase'])
            ->map(static fn (Keyword $keyword): array => [
                'id' => (int) $keyword->id,
                'phrase' => (string) $keyword->phrase,
            ])
            ->values()
            ->all();
    }

    public function createPillar(string $phrase, ?int $siteId = null): Keyword
    {
        $phrase = Keyword::decodePhrase($phrase);
        if ($phrase === '') {
            throw new \InvalidArgumentException(__('seo-content-ai::filament.keyword.cluster_pillar_phrase_required'));
        }

        $siteId ??= SeoAccessControl::globalSiteId();

        if ($siteId !== null && $siteId > 0) {
            $keyword = $this->keywordPersistence->upsert(
                phrase: $phrase,
                type: Keyword::TYPE_NORMAL,
                siteId: $siteId,
            );

            if (! $keyword instanceof Keyword) {
                throw new \InvalidArgumentException(__('seo-content-ai::filament.keyword.cluster_pillar_phrase_required'));
            }

            if ($keyword->parent_id !== null) {
                $keyword->update(['parent_id' => null]);
            }

            return $keyword->fresh() ?? $keyword;
        }

        $existing = Keyword::query()
            ->whereRaw('phrase COLLATE utf8mb4_unicode_ci = ?', [$phrase])
            ->first();

        if ($existing instanceof Keyword) {
            if ($existing->parent_id !== null) {
                $existing->update(['parent_id' => null]);
            }

            return $existing->fresh() ?? $existing;
        }

        $keyword = Keyword::query()->create([
            'phrase' => $phrase,
            'type' => Keyword::TYPE_NORMAL,
            'parent_id' => null,
        ]);

        if ($siteId > 0) {
            $this->keywordPersistence->upsert(
                phrase: $phrase,
                type: Keyword::TYPE_NORMAL,
                siteId: $siteId,
            );
        }

        return $keyword;
    }

    /**
     * @return list<array{id: int, phrase: string}>
     */
    public function searchAttachableKeywords(int $pillarId, string $query, ?int $siteId = null, int $limit = 12): array
    {
        $query = trim($query);
        if ($pillarId <= 0 || mb_strlen($query) < 2) {
            return [];
        }

        $siteId ??= SeoAccessControl::globalSiteId();
        $otherPillarIds = $this->otherPillarIds($pillarId);

        $keywords = $this->scopedKeywordQuery($siteId)
            ->where('id', '!=', $pillarId)
            ->whereNotIn('id', $otherPillarIds)
            ->where(function (Builder $builder) use ($pillarId): void {
                $builder
                    ->whereNull('parent_id')
                    ->orWhere('parent_id', '!=', $pillarId);
            })
            ->whereRaw('phrase COLLATE utf8mb4_unicode_ci LIKE ?', ['%'.$query.'%'])
            ->orderBy('phrase')
            ->limit($limit)
            ->get(['id', 'phrase']);

        return $keywords
            ->map(static fn (Keyword $keyword): array => [
                'id' => (int) $keyword->id,
                'phrase' => (string) $keyword->phrase,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, phrase: string}>
     */
    public function searchParentCandidates(int $keywordId, string $query, ?int $siteId = null, int $limit = 12): array
    {
        $query = trim($query);
        if ($keywordId <= 0 || mb_strlen($query) < 2) {
            return [];
        }

        $siteId ??= SeoAccessControl::globalSiteId();

        $keywords = $this->scopedKeywordQuery($siteId)
            ->whereNull('parent_id')
            ->where('id', '!=', $keywordId)
            ->whereRaw('phrase COLLATE utf8mb4_unicode_ci LIKE ?', ['%'.$query.'%'])
            ->orderBy('phrase')
            ->limit($limit)
            ->get(['id', 'phrase']);

        return $keywords
            ->map(static fn (Keyword $keyword): array => [
                'id' => (int) $keyword->id,
                'phrase' => (string) $keyword->phrase,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function otherPillarIds(int $excludePillarId): array
    {
        return Keyword::query()
            ->whereNull('parent_id')
            ->where('id', '!=', $excludePillarId)
            ->whereHas('children')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    private function isOtherPillar(int $keywordId, int $activePillarId): bool
    {
        if ($keywordId === $activePillarId) {
            return false;
        }

        $keyword = Keyword::query()->find($keywordId);
        if (! $keyword instanceof Keyword) {
            return false;
        }

        return $keyword->parent_id === null && $keyword->children()->exists();
    }

    /**
     * @return Builder<Keyword>
     */
    private function scopedKeywordQuery(?int $siteId): Builder
    {
        $query = Keyword::query();

        if ($siteId !== null && $siteId > 0) {
            $query->forSite($siteId);
        }

        return $query;
    }

    private function findKeywordOrFail(int $keywordId): Keyword
    {
        $keyword = Keyword::query()->find($keywordId);
        if (! $keyword instanceof Keyword) {
            throw new \InvalidArgumentException(__('seo-content-ai::filament.keyword.cluster_keyword_not_found'));
        }

        return $keyword;
    }
}
