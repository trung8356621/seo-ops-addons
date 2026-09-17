<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns;

use Livewire\Attributes\Renderless;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\Seo\Services\DomainOverviewService;

trait InteractsWithKeywordDetailDrawer
{
    public ?int $selectedKeywordId = null;

    public function selectKeyword(string $recordKey): void
    {
        $keywordId = (int) $recordKey;
        if ($keywordId <= 0) {
            return;
        }

        $this->selectedKeywordId = $keywordId;
        $this->dispatch('keyword-detail-open', keywordId: $keywordId);
        $this->skipRender();
    }

    public function selectKeywordForDetail(Keyword $record): void
    {
        $this->selectKeyword((string) $record->getKey());
    }

    public function closeSidebar(): void
    {
        $this->selectedKeywordId = null;
        $this->dispatch('keyword-detail-close');
        $this->skipRender();
    }

    /**
     * @return array{
     *     phrase: string,
     *     html: string,
     *     canEdit: bool,
     *     canDelete: bool,
     *     error: string|null,
     *     contentAnalysisUrl?: string|null,
     * }
     */
    #[Renderless]
    public function loadKeywordDetailPanel(int $keywordId): array
    {
        if ($keywordId <= 0) {
            return [
                'phrase' => '',
                'html' => '',
                'canEdit' => false,
                'canDelete' => false,
                'error' => __('seo-content-ai::filament.keyword.destinations_modal_not_found'),
            ];
        }

        $siteScope = $this->keywordDetailDrawerSiteScope();

        $keyword = Keyword::query()
            ->withCount([
                'mainArticles as main_articles_count' => static function ($query) use ($siteScope): void {
                    if ($siteScope !== null && $siteScope > 0) {
                        $query->where('site_id', $siteScope)->whereNull('deleted_at');
                    }
                },
                'linkMaps as linked_articles_count' => static function ($query) use ($siteScope): void {
                    $query->whereNotNull('source_article_id')
                        ->whereHas(
                            'sourceArticle',
                            static function ($articleQuery) use ($siteScope): void {
                                $articleQuery->whereNull('deleted_at');
                                if ($siteScope !== null && $siteScope > 0) {
                                    $articleQuery->where('site_id', $siteScope);
                                }
                            },
                        );
                },
                'linkMaps as site_links_count' => static function ($query) use ($siteScope): void {
                    if ($siteScope !== null && $siteScope > 0) {
                        $query->whereHas(
                            'sourceArticle',
                            static fn ($articleQuery) => $articleQuery->where('site_id', $siteScope),
                        );
                    }
                },
            ])
            ->with([
                'linkMaps' => static function ($linkQuery) use ($siteScope): void {
                    $linkQuery->orderBy('seo_link_maps.id');
                    if ($siteScope !== null && $siteScope > 0) {
                        $linkQuery->where(static function ($q) use ($siteScope): void {
                            $q->whereHas(
                                'sourceArticle',
                                static fn ($articleQuery) => $articleQuery->where('site_id', $siteScope),
                            )->orWhereHas(
                                'targetArticle',
                                static fn ($articleQuery) => $articleQuery->where('site_id', $siteScope),
                            );
                        });
                    }
                    $linkQuery->with([
                        'sourceArticle' => static fn ($articleQuery): mixed => $articleQuery
                            ->withTrashed()
                            ->select('id', 'site_id', 'title', 'slug'),
                        'sourceArticle.site:id,domain',
                        'targetArticle:id,site_id,title,slug',
                        'targetArticle.site:id,domain',
                    ]);
                },
                'mainArticles' => static function ($query) use ($siteScope): void {
                    if ($siteScope !== null && $siteScope > 0) {
                        $query->where('site_id', $siteScope);
                    }
                    $query->with('site:id,domain');
                },
            ])
            ->find($keywordId);

        if ($keyword === null) {
            return [
                'phrase' => '',
                'html' => '',
                'canEdit' => false,
                'canDelete' => false,
                'error' => __('seo-content-ai::filament.keyword.destinations_modal_not_found'),
            ];
        }

        $siteId = $siteScope !== null && $siteScope > 0
            ? $siteScope
            : (int) (KeywordResource::resolveKeywordSiteId($keyword) ?? 0);
        $contentAnalysisUrl = $siteId > 0 && (int) ($keyword->linked_articles_count ?? 0) > 0
            ? app(DomainOverviewService::class)->buildArticlesFilterUrlForInternalAnchorKeyword($siteId, (int) $keyword->id)
            : null;

        return [
            'phrase' => (string) $keyword->phrase,
            'html' => view('seo-content-ai::filament.resources.keywords.pages.partials.keyword-dictionary-drawer-content', [
                'record' => $keyword,
            ])->render(),
            'contentAnalysisUrl' => $contentAnalysisUrl,
            'canEdit' => KeywordResource::canEdit($keyword),
            'canDelete' => KeywordResource::canDelete($keyword),
            'error' => null,
            'site_id' => $siteId > 0 ? $siteId : null,
        ];
    }

    /**
     * Optional site scope for Topic detail drawer. Dictionary leaves this null.
     */
    protected function keywordDetailDrawerSiteScope(): ?int
    {
        return null;
    }

    public function editSelectedKeyword(): void
    {
        if ($this->selectedKeywordId === null || $this->selectedKeywordId <= 0) {
            return;
        }

        if (method_exists($this, 'mountTableAction')) {
            $this->mountTableAction('edit', (string) $this->selectedKeywordId);

            return;
        }

        if (method_exists($this, 'openKeywordEdit')) {
            $this->openKeywordEdit($this->selectedKeywordId);
        }
    }

    public function deleteSelectedKeyword(): void
    {
        if ($this->selectedKeywordId === null || $this->selectedKeywordId <= 0) {
            return;
        }

        if (method_exists($this, 'mountTableAction')) {
            $this->mountTableAction('delete', (string) $this->selectedKeywordId);
        }
    }
}
