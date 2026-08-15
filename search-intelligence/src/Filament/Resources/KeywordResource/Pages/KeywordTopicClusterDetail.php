<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterDetailBuilder;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordTagResolver;

final class KeywordTopicClusterDetail extends Page
{
    use HasKeywordWorkspaceNavigation;

    protected static string $resource = KeywordResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.keywords.pages.topic-cluster-detail';

    protected static bool $shouldRegisterNavigation = false;

    public string $clusterKey = '';

    public function mount(string $clusterKey): void
    {
        $this->initializeKeywordWorkspaceSiteFilter();
        $this->clusterKey = rawurldecode($clusterKey);
        abort_unless($this->getDetail() !== null, 404);
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
        );
    }

    public function getKeywords()
    {
        return app(KeywordClusterDetailBuilder::class)->paginateKeywords(
            $this->resolveKeywordWorkspaceSiteId(),
            $this->clusterKey,
        );
    }

    public function tagResolver(): KeywordTagResolver
    {
        return app(KeywordTagResolver::class);
    }

    public function backUrl(): string
    {
        return $this->appendKeywordWorkspaceSiteToUrl(KeywordResource::getUrl('clusters'));
    }
}
