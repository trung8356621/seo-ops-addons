<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftIntakeService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftResolver;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\KeywordExternalRelationshipReadModel;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
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

        // Backward compatibility: map old ?risk= parameters onto canonical single-filter dimension
        $riskParam = request()->query('risk');
        if (is_string($riskParam) && $riskParam !== '') {
            $this->risk = $riskParam;
        }

        if ($this->risk !== '') {
            $mapped = match ($this->risk) {
                'safe' => 'managed_cross_site',
                'low' => 'reference',
                'review' => 'needs_review',
                default => 'all',
            };
            $this->filter = $mapped;
            $this->risk = '';
        }

        if (! in_array($this->filter, KeywordExternalRelationshipReadModel::uiCategories(), true)) {
            $this->filter = 'all';
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

    /**
     * Backward compatibility helper for legacy callers/tests.
     */
    public function setRiskFilter(string $riskLevel): void
    {
        $category = match ($riskLevel) {
            'safe' => 'managed_cross_site',
            'low' => 'reference',
            'review' => 'needs_review',
            default => 'all',
        };

        $this->setTypeFilter($category);
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
     * Quick "Push to Draft" action for Warning (review) rows.
     * Targets SOURCE ARTICLE and adds it to Content Project Draft planning pool.
     */
    public function pushToDraft(int $mapId): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.articles_optimal.assign_failed'))
                ->warning()
                ->send();

            return;
        }

        $map = SeoLinkMap::query()->with('sourceArticle')->find($mapId);
        if (! $map instanceof SeoLinkMap) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_map_not_found'))
                ->danger()
                ->send();

            return;
        }

        $sourceArticle = $map->sourceArticle;
        if (! $sourceArticle instanceof SeoArticle) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_article_not_found'))
                ->danger()
                ->send();

            return;
        }

        $sourceSiteId = (int) ($sourceArticle->site_id ?? 0);
        if ($sourceSiteId <= 0 || ! SeoAccessControl::canAccessSite($sourceSiteId)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.assign_failed'))
                ->warning()
                ->send();

            return;
        }

        $readModel = app(KeywordExternalRelationshipReadModel::class);
        $riskLevel = $readModel->riskLevelForType($map->link_type ?? SeoLinkMapType::External);

        // Push to Draft is only allowed for Warning (review) rows
        if ($riskLevel !== 'review') {
            return;
        }

        $targetUrl = trim((string) ($map->target_external_url ?? ''));
        $notes = "External link cần xử lý:\n" . ($targetUrl !== '' ? $targetUrl : '—')
            . "\n\nReason:\nWarning / unmanaged external relationship\n\nRelationship/map ID:\n" . (int) $map->id;

        $result = app(PlanningDraftIntakeService::class)->addArticles(
            articles: [$sourceArticle],
            forcedType: SeoProjectTask::TYPE_IMPROVE,
            notes: $notes,
        );

        if ($result->isAlreadyInDraft()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.already_in_draft'))
                ->body($result->message)
                ->info()
                ->send();
        } elseif ($result->isSuccess()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.add_to_draft_completed'))
                ->body($result->message)
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title(__('seo-content-ai::filament.articles_optimal.assign_failed'))
                ->body($result->message)
                ->danger()
                ->send();
        }
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

        $category = $this->filter !== '' ? $this->filter : 'all';
        $payload = $readModel->externalLinksForUiCategory(
            $siteId,
            $category,
            $this->getPage(),
            25,
        );

        $items = $this->augmentItems($payload['items']);

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
     * Augment items with domain info, source article edit URL, and draft presence.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function augmentItems(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $items = $this->withAccessibleSiteDomains($items);

        $sourceArticleIds = [];
        foreach ($items as $item) {
            $aid = (int) ($item['source_article_id'] ?? 0);
            if ($aid > 0) {
                $sourceArticleIds[$aid] = true;
            }
        }

        $draftArticleIds = [];
        if ($sourceArticleIds !== []) {
            try {
                $draftArticleIds = SeoProjectTask::query()
                    ->whereIn('article_id', array_keys($sourceArticleIds))
                    ->pluck('article_id', 'article_id')
                    ->all();
            } catch (\Throwable) {
                $draftArticleIds = [];
            }
        }

        foreach ($items as $index => $item) {
            $sourceArticleId = (int) ($item['source_article_id'] ?? 0);

            $editUrl = null;
            if ($sourceArticleId > 0) {
                try {
                    $editUrl = ArticleResource::getUrl('edit', ['record' => $sourceArticleId]);
                } catch (\Throwable) {
                    $editUrl = null;
                }
            }

            $items[$index]['source_article_edit_url'] = $editUrl;
            $items[$index]['is_source_in_draft'] = $sourceArticleId > 0 && isset($draftArticleIds[$sourceArticleId]);
        }

        return $items;
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
