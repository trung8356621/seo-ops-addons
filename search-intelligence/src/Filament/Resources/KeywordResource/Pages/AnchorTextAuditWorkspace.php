<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchFoundation\Services\KeywordLinkTargetResolver;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;
use Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectActionFactory;
use Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoLinkMapNetworkStatusPresenter;
use Omnichannel\Addons\Seo\Support\SeoLinkTriageQuery;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

final class AnchorTextAuditWorkspace extends Page implements HasActions, HasForms
{
    use HasKeywordWorkspaceNavigation;
    use InteractsWithActions;
    use InteractsWithForms;
    use WithPagination;

    protected static string $resource = KeywordResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.keywords.pages.anchor-text-audit-workspace';

    protected static ?string $navigationLabel = null;

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'filter')]
    public string $triageFilter = 'all_issues';

    public function mount(): void
    {
        $this->initializeKeywordWorkspaceSiteFilter();
        $this->dispatchKeywordWorkspaceLanguageContext();

        if (! in_array($this->triageFilter, ['all_issues', 'broken', 'weak_context', 'external'], true)) {
            $this->triageFilter = 'all_issues';
        }
    }

    public static function canAccess(array $parameters = []): bool
    {
        return KeywordResource::canViewAny();
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.keyword.link_triage_title');
    }

    protected function getActiveKeywordWorkspaceKey(): string
    {
        return 'anchor-audit';
    }

    public function setTriageFilter(string $filter): void
    {
        if (! in_array($filter, ['all_issues', 'broken', 'weak_context', 'external'], true)) {
            return;
        }

        $this->triageFilter = $filter;
        $this->resetPage();
    }

    public function onKeywordWorkspaceSiteFilterChanged(): void
    {
        $this->resetPage();
    }

    /**
     * @return array{all_issues: int, broken: int, weak_context: int, external: int}
     */
    public function getTriageTabCounts(): array
    {
        return SeoLinkTriageQuery::countTabs($this->baseTriageQuery());
    }

    public function getTriagePaginator(): LengthAwarePaginator
    {
        $query = SeoLinkTriageQuery::applyIssuesScope($this->baseTriageQuery());

        match ($this->triageFilter) {
            'broken' => SeoLinkTriageQuery::applyBrokenScope($query),
            'weak_context' => SeoLinkTriageQuery::applyWeakContextScope($query),
            'external' => SeoLinkTriageQuery::applyExternalScope($query),
            default => null,
        };

        /** @var LengthAwarePaginator $paginator */
        $paginatorQuery = $query;

        if (SeoLinkTriageQuery::supportsHttpAuditColumns()) {
            $paginatorQuery = $paginatorQuery->orderByDesc('seo_link_maps.last_audited_at');
        }

        $paginator = $paginatorQuery
            ->orderByDesc('seo_link_maps.updated_at')
            ->orderByDesc('seo_link_maps.id')
            ->paginate(20)
            ->through(fn (SeoLinkMap $map): array => $this->mapTriageRow($map));

        return $paginator;
    }

    public function assignToContentProjectAction(): Action
    {
        return AssignToContentProjectActionFactory::pageAction(
            resolvePayload: function (array $arguments): array {
                $mapId = (int) ($arguments['mapId'] ?? 0);
                $keyword = $this->resolveKeywordForMapId($mapId);
                $siteId = $this->resolveMapSiteId($mapId);

                return AssignToContentProjectContract::keywordPayload(
                    source: 'anchor_text_audit',
                    keywordIds: $keyword instanceof Keyword ? [(int) $keyword->id] : [],
                    siteIds: $siteId !== null && $siteId > 0 ? [$siteId] : [],
                    mapId: $mapId > 0 ? $mapId : null,
                );
            },
            name: 'assignToContentProject',
        );
    }

    public function markLinkMapAsActive(int $mapId): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return;
        }

        if ($mapId <= 0) {
            return;
        }

        $map = SeoLinkMap::query()->find($mapId);
        if (! $map instanceof SeoLinkMap) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_map_not_found'))
                ->danger()
                ->send();

            return;
        }

        if ($map->status === SeoLinkMapStatus::Active) {
            return;
        }

        $updates = ['status' => SeoLinkMapStatus::Active];

        if (SeoLinkTriageQuery::supportsHttpAuditColumns()) {
            $updates['last_http_status'] = 200;
            $updates['last_audited_at'] = now();
        }

        $map->update($updates);

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.link_triage_mark_active_success'))
            ->success()
            ->send();
    }

    /**
     * @return Builder<SeoLinkMap>
     */
    private function baseTriageQuery(): Builder
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();

        $query = SeoLinkMap::query()
            ->with([
                'keyword:id,phrase,type',
                'sourceArticle:id,site_id,title,slug,wp_post_id',
                'sourceArticle.site:id,domain,ssl',
                'targetArticle:id,site_id,title,slug',
            ])
            ->when(
                $siteId !== null && $siteId > 0,
                static fn (Builder $builder): Builder => $builder->whereHas(
                    'sourceArticle',
                    static fn (Builder $articleQuery): Builder => $articleQuery->where('site_id', $siteId),
                ),
            );

        return $this->applyKeywordWorkspaceLanguageScopeToLinkMaps($query);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapTriageRow(SeoLinkMap $map): array
    {
        $sourceArticle = $map->sourceArticle;
        $keyword = $map->keyword;
        $httpStatus = $map->last_http_status !== null ? (int) $map->last_http_status : null;
        $network = SeoLinkMapNetworkStatusPresenter::present($httpStatus, $map->status);
        $linkType = $map->link_type instanceof SeoLinkMapType ? $map->link_type : SeoLinkMapType::Internal;
        $targetUrl = $this->resolveTargetUrl($map);
        $sourcePermalink = $sourceArticle instanceof SeoArticle
            ? trim(app(WordPressArticleContentService::class)->resolvePermalink($sourceArticle))
            : '';
        $sourcePath = $sourcePermalink !== ''
            ? KeywordResource::formatLinkShorthand($sourcePermalink)
            : KeywordResource::formatLinkShorthand(trim((string) ($sourceArticle?->slug ?? '')));

        if ($sourcePath === '—' && $sourceArticle instanceof SeoArticle) {
            $sourcePath = trim((string) ($sourceArticle->title ?? '')) ?: '—';
        }

        return [
            'id' => (int) $map->id,
            'anchor_text' => (string) $map->anchor_text,
            'source_path_label' => $sourcePath,
            'source_edit_url' => $sourceArticle instanceof SeoArticle
                ? ArticleResource::getUrl('edit', ['record' => $sourceArticle->id], panel: ArticleResource::panelId())
                : null,
            'target_url' => $targetUrl,
            'target_label' => KeywordResource::formatLinkShorthand($targetUrl !== '' ? $targetUrl : '—', 48),
            'target_tone' => match ($linkType) {
                SeoLinkMapType::External => 'external',
                SeoLinkMapType::WikiTrust => 'wiki_trust',
                default => 'internal',
            },
            'network' => $network,
            'weak_context' => SeoLinkTriageQuery::hasWeakContext($map),
            'can_mark_link_ok' => $map->status !== SeoLinkMapStatus::Active
                && SeoAccessControl::canMutateInSeoPanel(),
            'can_assign_content_project' => $keyword instanceof Keyword
                && KeywordResource::canAssignKeywordToContentProject($keyword),
        ];
    }

    private function resolveTargetUrl(SeoLinkMap $map): string
    {
        $external = trim((string) ($map->target_external_url ?? ''));
        if ($external !== '') {
            return $external;
        }

        $targetArticle = $map->targetArticle;
        if (! $targetArticle instanceof SeoArticle) {
            return '';
        }

        return trim((string) (app(KeywordLinkTargetResolver::class)->resolveArticlePublicUrl($targetArticle) ?? ''));
    }

    private function resolveKeywordForMapId(int $mapId): ?Keyword
    {
        if ($mapId <= 0) {
            return null;
        }

        $map = SeoLinkMap::query()->with('keyword')->find($mapId);
        $keyword = $map?->keyword;

        return $keyword instanceof Keyword ? $keyword : null;
    }

    private function resolveMapSiteId(int $mapId): ?int
    {
        if ($mapId <= 0) {
            return null;
        }

        $siteId = SeoLinkMap::query()
            ->whereKey($mapId)
            ->join('articles', 'articles.id', '=', 'seo_link_maps.source_article_id')
            ->value('articles.site_id');

        return is_numeric($siteId) ? (int) $siteId : null;
    }
}
