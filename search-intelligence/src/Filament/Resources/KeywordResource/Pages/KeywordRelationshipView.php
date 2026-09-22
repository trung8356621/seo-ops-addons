<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\Seo\Services\KeywordRelationship\KeywordRelationshipGateway;
use Omnichannel\Addons\Seo\Services\KeywordRelationship\KeywordRelationshipGraphPresenter;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * One-keyword Relationship View — Keyword MCP type-2 visualization.
 * Uses KeywordRelationshipGateway only (no direct relationship table queries).
 */
final class KeywordRelationshipView extends Page
{
    use HasKeywordWorkspaceNavigation;

    protected static string $resource = KeywordResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.keywords.pages.keyword-relationship';

    protected static bool $shouldRegisterNavigation = false;

    public int $keyword = 0;

    /** @var array<string, bool> */
    public array $categoryFilters = [
        'topic' => true,
        'article' => true,
        'dna' => true,
        'related_keyword' => true,
        'gsc' => false,
        'internal_link' => false,
        'planning' => false,
    ];

    public function mount(int|string $keyword): void
    {
        $this->initializeKeywordWorkspaceSiteFilter();
        $this->keyword = (int) $keyword;
        abort_unless($this->keyword > 0, 404);
        $this->dispatchKeywordWorkspaceLanguageContext();

        $payload = $this->getRelationshipPayloadProperty();
        abort_unless(($payload['ok'] ?? false) === true, 404);
    }

    public function getTitle(): string|Htmlable
    {
        return (string) __('seo-content-ai::filament.keyword.relationship_title');
    }

    protected function getActiveKeywordWorkspaceKey(): string
    {
        return 'index';
    }

    public function toggleCategory(string $key): void
    {
        if (! array_key_exists($key, $this->categoryFilters)) {
            return;
        }
        $this->categoryFilters[$key] = ! $this->categoryFilters[$key];
        $this->dispatch('keyword-relationship-filters-changed', filters: $this->categoryFilters);
    }

    /**
     * @return array{ok: bool, data?: array<string, mixed>, graph?: array<string, mixed>, message?: string}
     */
    public function getRelationshipPayloadProperty(): array
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        if ($siteId <= 0 || $this->keyword <= 0) {
            return ['ok' => false, 'message' => 'Keyword not found.'];
        }

        if (! SeoAccessControl::canAccessSite($siteId)) {
            return ['ok' => false, 'message' => 'Keyword not found.'];
        }

        $gateway = app(KeywordRelationshipGateway::class);
        $relationship = $gateway->forKeyword($siteId, $this->keyword);
        if ($relationship === null) {
            return ['ok' => false, 'message' => 'Keyword not found.'];
        }

        $data = $relationship->toArray();
        $graph = app(KeywordRelationshipGraphPresenter::class)->present($relationship, $this->categoryFilters);

        return [
            'ok' => true,
            'data' => $data,
            'graph' => $graph,
        ];
    }

    public function dictionaryUrl(): string
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        $url = KeywordResource::getUrl('index');
        if ($siteId > 0) {
            return app(DomainContextResolver::class)->appendSiteToUrl($url, $siteId);
        }

        return $url;
    }
}
