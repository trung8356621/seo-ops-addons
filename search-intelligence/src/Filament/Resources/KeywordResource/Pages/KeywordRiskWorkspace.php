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
 * Keywords > Risk — prioritized outbound semantic relationships.
 * Projection of outbound semantic links for inspection.
 */
final class KeywordRiskWorkspace extends Page
{
    use HasKeywordWorkspaceNavigation;
    use WithPagination;

    protected static string $resource = KeywordResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.keywords.pages.keyword-risk-workspace';

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'risk')]
    public string $riskFilter = 'review';

    public function mount(): void
    {
        $this->initializeKeywordWorkspaceSiteFilter();
        $this->dispatchKeywordWorkspaceLanguageContext();

        if (! in_array($this->riskFilter, KeywordExternalRelationshipReadModel::uiRiskFilters(), true)) {
            $this->riskFilter = 'review';
        }
    }

    public static function canAccess(array $parameters = []): bool
    {
        return KeywordResource::canViewAny();
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.keyword.risk_title');
    }

    protected function getActiveKeywordWorkspaceKey(): string
    {
        return 'risk';
    }

    public function setRiskFilter(string $filter): void
    {
        if (! in_array($filter, KeywordExternalRelationshipReadModel::uiRiskFilters(), true)) {
            return;
        }

        $this->riskFilter = $filter;
        $this->resetPage();
    }

    public function onKeywordWorkspaceSiteFilterChanged(): void
    {
        $this->resetPage();
    }

    /**
     * @return array{all: int, safe: int, low: int, review: int, available: bool}
     */
    public function getRiskCounts(): array
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);

        return app(KeywordExternalRelationshipReadModel::class)->riskCounts($siteId);
    }

    public function getRiskPaginator(): LengthAwarePaginator
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        $payload = app(KeywordExternalRelationshipReadModel::class)->externalLinksForRiskLevel(
            $siteId,
            $this->riskFilter,
            $this->getPage(),
            25,
        );

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
