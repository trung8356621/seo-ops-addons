<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\KeywordExternalRelationshipReadModel;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Keywords > External — semantic outbound relationships.
 * Not a broken-link / 404 scanner.
 */
final class KeywordExternalWorkspace extends Page
{
    use HasKeywordWorkspaceNavigation;
    use WithPagination;

    protected static string $resource = KeywordResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.keywords.pages.keyword-external-workspace';

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'filter')]
    public string $filter = 'all';

    #[Url(as: 'risk')]
    public string $risk = '';

    public function mount(): void
    {
        $this->initializeKeywordWorkspaceSiteFilter();
        $this->dispatchKeywordWorkspaceLanguageContext();

        // Compatibility fallback: check legacy ?external= query parameter if present
        $legacyExternal = request()->query('external');
        if (is_string($legacyExternal) && $legacyExternal !== '' && $this->filter === 'all') {
            $this->filter = $legacyExternal;
        }

        if ($this->risk !== '') {
            if (! in_array($this->risk, KeywordExternalRelationshipReadModel::uiRiskFilters(), true)) {
                $this->risk = '';
            } else {
                $this->filter = '';
            }
        }

        if ($this->risk === '') {
            if (! in_array($this->filter, KeywordExternalRelationshipReadModel::uiCategories(), true)) {
                $this->filter = 'all';
            }
        }
    }

    public static function canAccess(array $parameters = []): bool
    {
        return KeywordResource::canViewAny();
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.keyword.external_title');
    }

    protected function getActiveKeywordWorkspaceKey(): string
    {
        return 'external';
    }

    public function setTypeFilter(string $category): void
    {
        if (! in_array($category, KeywordExternalRelationshipReadModel::uiCategories(), true)) {
            return;
        }

        $this->filter = $category;
        $this->risk = '';
        $this->resetPage();
    }

    public function setRiskFilter(string $riskLevel): void
    {
        if (! in_array($riskLevel, KeywordExternalRelationshipReadModel::uiRiskFilters(), true)) {
            return;
        }

        $this->risk = $riskLevel;
        $this->filter = '';
        $this->resetPage();
    }

    /**
     * Backward compatibility helper for existing callers/views.
     */
    public function setExternalFilter(string $filter): void
    {
        $this->setTypeFilter($filter);
    }

    public function onKeywordWorkspaceSiteFilterChanged(): void
    {
        $this->resetPage();
    }

    /**
     * @return array{all: int, managed_cross_site: int, reference: int, needs_review: int, available: bool}
     */
    public function getExternalCategoryCounts(): array
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);

        return app(KeywordExternalRelationshipReadModel::class)->categoryCounts($siteId);
    }

    /**
     * @return array{all: int, safe: int, low: int, review: int, available: bool}
     */
    public function getRiskCounts(): array
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);

        return app(KeywordExternalRelationshipReadModel::class)->riskCounts($siteId);
    }

    public function getExternalPaginator(): LengthAwarePaginator
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        $readModel = app(KeywordExternalRelationshipReadModel::class);

        if ($this->risk !== '') {
            $payload = $readModel->externalLinksForRiskLevel(
                $siteId,
                $this->risk,
                $this->getPage(),
                25,
            );
        } else {
            $category = $this->filter !== '' ? $this->filter : 'all';
            $payload = $readModel->externalLinksForUiCategory(
                $siteId,
                $category,
                $this->getPage(),
                25,
            );
        }

        $items = $this->withAccessibleSiteDomains($payload['items']);

        return new LengthAwarePaginator(
            $items,
            (int) $payload['total'],
            (int) $payload['per_page'],
            (int) $payload['page'],
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => 'page',
            ],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function withAccessibleSiteDomains(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            foreach (['target_site_id', 'source_site_id'] as $key) {
                $id = (int) ($item[$key] ?? 0);
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }

        $domains = [];
        if ($ids !== []) {
            $domains = SeoAccessControl::accessibleSitesQuery()
                ->whereIn('id', array_keys($ids))
                ->pluck('domain', 'id')
                ->all();
        }

        foreach ($items as $index => $item) {
            $targetId = (int) ($item['target_site_id'] ?? 0);
            $sourceId = (int) ($item['source_site_id'] ?? 0);
            $items[$index]['target_site_domain'] = $targetId > 0
                ? trim((string) ($domains[$targetId] ?? $domains[(string) $targetId] ?? ''))
                : '';
            $items[$index]['source_site_domain'] = $sourceId > 0
                ? trim((string) ($domains[$sourceId] ?? $domains[(string) $sourceId] ?? ''))
                : '';
        }

        return $items;
    }
}
