<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\DissolvesTopics;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\InteractsWithKeywordDetailDrawer;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\InteractsWithKeywordItemActions;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\ReclustersSiteTopics;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicDetailQuery;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicRenameService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordPhrasePresentation;
use Omnichannel\Addons\Seo\Support\DomainContext;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;

/**
 * Topic detail — route param is numeric topic_id; always site-scoped.
 */
final class KeywordTopicClusterDetail extends Page
{
    use DissolvesTopics;
    use HasKeywordWorkspaceNavigation;
    use InteractsWithKeywordDetailDrawer;
    use InteractsWithKeywordItemActions;
    use ReclustersSiteTopics;

    protected static string $resource = KeywordResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.keywords.pages.topic-cluster-detail';

    protected static bool $shouldRegisterNavigation = false;

    public int $topic = 0;

    public int $clusterDataEpoch = 0;

    public function mount(int|string $topic): void
    {
        $this->initializeKeywordWorkspaceSiteFilter();
        $this->topic = (int) $topic;
        $this->dispatchKeywordWorkspaceLanguageContext();
        $this->syncReclusterStateFromCache();

        abort_unless($this->topic > 0, 404);
        abort_unless($this->getDetail() !== null, 404);
        $this->maybeRedirectToScopedSiteUrl();
    }

    public function onKeywordWorkspaceSiteFilterChanged(): void
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();
        if ($siteId === null || $siteId <= 0) {
            return;
        }

        $this->syncReclusterStateFromCache();
        // Cross-site topic_id must 404 — remount via redirect only if still owned.
        if ($this->getDetail() === null) {
            $this->redirect(
                app(DomainContextResolver::class)->appendSiteToUrl(
                    KeywordResource::getUrl('clusters'),
                    $siteId,
                ),
            );

            return;
        }

        $this->redirect($this->topicDetailPageUrl());
    }

    private function maybeRedirectToScopedSiteUrl(): void
    {
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

        $this->redirect($this->topicDetailPageUrl());
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
        return 'clusters';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDetail(): ?array
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);

        return app(TopicDetailQuery::class)->find($siteId, $this->topic);
    }

    /**
     * @return array<int, list<string>>
     */
    public function getKeywordDnaMap(): array
    {
        $keywords = $this->getKeywords();
        $ids = collect($keywords->items())->pluck('keyword_id')->map(static fn ($id): int => (int) $id)->all();
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);

        return app(TopicDetailQuery::class)->dnaDisplayByKeyword($siteId, $this->topic, $ids);
    }

    public function getKeywords()
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        $path = KeywordResource::getUrl('cluster', ['topic' => $this->topic]);

        return app(TopicDetailQuery::class)
            ->paginateMembers($siteId, $this->topic, 25)
            ->withPath($path)
            ->appends(array_filter([
                DomainContext::SITE_ID_QUERY_KEY => $siteId > 0 ? $siteId : null,
            ], static fn (mixed $v): bool => $v !== null));
    }

    public function topicDetailPageUrl(): string
    {
        return app(DomainContextResolver::class)->appendSiteToUrl(
            KeywordResource::getUrl('cluster', ['topic' => $this->topic]),
            $this->resolveKeywordWorkspaceSiteId(),
        );
    }

    public function backUrl(): string
    {
        return app(DomainContextResolver::class)->appendSiteToUrl(
            KeywordResource::getUrl('clusters'),
            $this->resolveKeywordWorkspaceSiteId(),
        );
    }

    public function refreshClusterSummaryCounters(): void
    {
        $this->clusterDataEpoch++;
    }

    public function canEditTopicName(): bool
    {
        $detail = $this->getDetail();

        return $this->hasTopicMutationPermission()
            && ! $this->isTopicMutationLocked()
            && ! (bool) ($detail['is_locked'] ?? false);
    }

    public function saveTopicName(string $phrase): string
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        $detail = $this->getDetail();
        $fallback = (string) ($detail['label'] ?? $phrase);
        if ($siteId <= 0 || ! $this->canEditTopicName()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_canonical_edit_denied'))
                ->danger()
                ->send();

            return $fallback;
        }

        $result = app(TopicRenameService::class)->rename($siteId, $this->topic, $phrase);
        if (! $result['ok']) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_canonical_edit_failed'))
                ->danger()
                ->send();

            return $fallback;
        }

        $this->clusterDataEpoch++;
        $label = KeywordPhrasePresentation::present(trim($phrase));

        return $label !== '' ? $label : trim($phrase);
    }

    public function openKeywordEdit(int $keywordId): void
    {
        if ($keywordId <= 0) {
            return;
        }

        $this->redirect(
            app(DomainContextResolver::class)->appendSiteToUrl(
                KeywordResource::getUrl('index'),
                $this->resolveKeywordWorkspaceSiteId(),
            ),
        );
    }

    /** @deprecated BC aliases for adapted blades */
    public function canEditClusterCanonical(): bool
    {
        return $this->canEditTopicName();
    }

    public function saveClusterCanonicalPhrase(string $phrase): string
    {
        return $this->saveTopicName($phrase);
    }

    public function hasTopicClusterMutationPermission(): bool
    {
        return $this->hasTopicMutationPermission();
    }

    public function canDissolveCluster(): bool
    {
        $detail = $this->getDetail();

        return $this->canDissolveTopic() && ! (bool) ($detail['is_locked'] ?? false);
    }

    public function dissolveCurrentTopic(): void
    {
        $result = $this->dissolveTopic($this->topic);
        if ($result['ok'] ?? false) {
            $this->redirect($this->backUrl());
        }
    }
}
