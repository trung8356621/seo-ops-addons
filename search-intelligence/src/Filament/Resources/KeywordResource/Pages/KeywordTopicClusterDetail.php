<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\DissolvesTopicClusters;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\InteractsWithKeywordDetailDrawer;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\InteractsWithKeywordItemActions;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterDetailBuilder;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\UpdateClusterCanonicalService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordPhrasePresentation;
use Omnichannel\Addons\Seo\Support\DomainContext;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use RuntimeException;

final class KeywordTopicClusterDetail extends Page
{
    use DissolvesTopicClusters;
    use HasKeywordWorkspaceNavigation;
    use InteractsWithKeywordDetailDrawer;
    use InteractsWithKeywordItemActions;

    protected static string $resource = KeywordResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.keywords.pages.topic-cluster-detail';

    protected static bool $shouldRegisterNavigation = false;

    public string $clusterKey = '';

    public int $clusterDataEpoch = 0;

    public function mount(string $clusterKey): void
    {
        $this->initializeKeywordWorkspaceSiteFilter();
        $this->clusterKey = rawurldecode($clusterKey);
        $this->dispatchKeywordWorkspaceLanguageContext();

        if ($this->getDetail() !== null) {
            $this->maybeRedirectToScopedSiteUrl();

            return;
        }

        abort_unless(app(KeywordClusterQuery::class)->clusterExists($this->clusterKey, $this->resolveKeywordWorkspaceSiteId()), 404);
        $this->maybeRedirectToScopedSiteUrl();
    }

    public function onKeywordWorkspaceSiteFilterChanged(): void
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();
        if ($siteId === null || $siteId <= 0) {
            return;
        }

        // Always Filament page URL — request()->fullUrl() during Livewire is /livewire/update.
        $this->redirect($this->clusterDetailPageUrl());
    }

    private function maybeRedirectToScopedSiteUrl(): void
    {
        // Full-page only: Livewire POST must never redirect from request URL.
        if ($this->isLivewireUpdateRequest()) {
            return;
        }

        if (request()->has(DomainContext::QUERY_KEY) || request()->has(DomainContext::SITE_ID_QUERY_KEY)) {
            return;
        }

        $siteId = $this->resolveKeywordWorkspaceSiteId();
        if ($siteId === null || $siteId <= 0) {
            return;
        }

        $this->redirect($this->clusterDetailPageUrl());
    }

    private function isLivewireUpdateRequest(): bool
    {
        $path = trim((string) request()->path(), '/');

        return $path === 'livewire/update' || str_starts_with($path, 'livewire/');
    }

    public static function canAccess(array $parameters = []): bool
    {
        return KeywordResource::canViewAny();
    }

    public function getTitle(): string|Htmlable
    {
        return (string) ($this->getDetail()['label'] ?? __('seo-content-ai::filament.keyword.topic_cluster_title'));
    }

    protected function getActiveKeywordWorkspaceKey(): string
    {
        return 'workspace-2';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDetail(): ?array
    {
        return app(KeywordClusterDetailBuilder::class)->build(
            $this->resolveKeywordWorkspaceSiteId(),
            $this->clusterKey,
            $this->resolveKeywordLanguageFilterVariants(),
        );
    }

    /**
     * @return array<int, list<string>>
     */
    public function getKeywordDnaMap(): array
    {
        $keywords = $this->getKeywords();
        $ids = collect($keywords->items())->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        return app(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordDnaService::class)
            ->displayValuesForKeywords($ids);
    }

    public function getKeywords()
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();
        $path = KeywordResource::getUrl('cluster', ['clusterKey' => $this->clusterKey]);

        return app(KeywordClusterDetailBuilder::class)
            ->paginateKeywords(
                $siteId,
                $this->clusterKey,
                25,
                $this->resolveKeywordLanguageFilterVariants(),
            )
            ->withPath($path)
            ->appends(array_filter([
                DomainContext::SITE_ID_QUERY_KEY => ($siteId !== null && $siteId > 0) ? $siteId : null,
            ], static fn (mixed $v): bool => $v !== null));
    }

    public function clusterDetailPageUrl(): string
    {
        return app(DomainContextResolver::class)->appendSiteToUrl(
            KeywordResource::getUrl('cluster', ['clusterKey' => $this->clusterKey]),
            $this->resolveKeywordWorkspaceSiteId(),
        );
    }

    public function refreshClusterSummaryCounters(): void
    {
        $this->clusterDataEpoch++;
    }

    public function openKeywordEdit(int $keywordId): void
    {
        if ($keywordId <= 0) {
            return;
        }

        $siteId = $this->resolveKeywordWorkspaceSiteId();
        $this->redirect(
            app(DomainContextResolver::class)->appendSiteToUrl(
                KeywordResource::getUrl('index'),
                $siteId,
            ),
        );
    }

    public function canEditClusterCanonical(): bool
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();

        return SeoAccessControl::canMutateInSeoPanel()
            && $siteId !== null
            && $siteId > 0
            && SeoAccessControl::canAccessSite($siteId);
    }

    public function saveClusterCanonicalPhrase(string $phrase): string
    {
        if (! $this->canEditClusterCanonical()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_canonical_edit_denied'))
                ->danger()
                ->send();

            return (string) ($this->getDetail()['label'] ?? $phrase);
        }

        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();

        try {
            $result = app(UpdateClusterCanonicalService::class)
                ->setManualCanonical($siteId, $this->clusterKey, $phrase);
        } catch (RuntimeException $e) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_canonical_edit_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return (string) ($this->getDetail()['label'] ?? $phrase);
        }

        $label = KeywordPhrasePresentation::present((string) ($this->getDetail()['label'] ?? trim($phrase)));

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.topic_canonical_edit_saved'))
            ->body(__('seo-content-ai::filament.keyword.topic_canonical_edit_body', [
                'attached' => $result['attached'],
                'detached' => $result['detached'],
            ]))
            ->success()
            ->send();

        $this->dispatch('cluster-canonical-sync', label: $label);
        $this->refreshClusterSummaryCounters();

        return $label;
    }

    public function resetClusterCanonicalToAuto(): string
    {
        if (! $this->canEditClusterCanonical()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_canonical_edit_denied'))
                ->danger()
                ->send();

            return (string) ($this->getDetail()['label'] ?? '');
        }

        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();

        try {
            app(UpdateClusterCanonicalService::class)->resetToAuto($siteId, $this->clusterKey);
        } catch (RuntimeException $e) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_canonical_edit_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return (string) ($this->getDetail()['label'] ?? '');
        }

        $label = (string) ($this->getDetail()['label'] ?? '');

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.topic_canonical_reset_auto'))
            ->success()
            ->send();

        $this->dispatch('cluster-canonical-sync', label: $label);
        $this->refreshClusterSummaryCounters();

        return $label;
    }

    public function backUrl(): string
    {
        return $this->dissolveClustersListUrl();
    }

    protected function shouldRedirectAfterDissolve(): bool
    {
        return true;
    }
}
