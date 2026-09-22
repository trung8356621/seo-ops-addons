<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapReadModel;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Throwable;

/**
 * Site-level Topical Map — Keyword Landscape visualization (Tree / Network / Sunburst).
 */
final class KeywordTopicalMap extends Page
{
    use HasKeywordWorkspaceNavigation;

    protected static string $resource = KeywordResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.keywords.pages.keyword-topical-map';

    protected static bool $shouldRegisterNavigation = false;

    /** tree|network|sunburst */
    public string $mapRenderer = 'tree';

    public ?int $focusedTopicId = null;

    public bool $auditRunning = false;

    /** @var array<string, mixed>|null */
    public ?array $auditResult = null;

    public string $auditError = '';

    public function mount(): void
    {
        $this->initializeKeywordWorkspaceSiteFilter();
        if ($this->redirectToFirstAccessibleDomainIfNeeded()) {
            return;
        }
        $this->dispatchKeywordWorkspaceLanguageContext();
        $this->mapRenderer = 'tree';
    }

    public function getTitle(): string|Htmlable
    {
        return (string) __('seo-content-ai::filament.keyword.topical_map_title');
    }

    protected function getActiveKeywordWorkspaceKey(): string
    {
        return 'topical-map';
    }

    public function setMapRenderer(string $renderer): void
    {
        $renderer = strtolower(trim($renderer));
        $this->mapRenderer = in_array($renderer, ['tree', 'network', 'sunburst'], true)
            ? $renderer
            : 'tree';
        $this->dispatch('topical-map-renderer-changed', renderer: $this->mapRenderer);
    }

    public function focusTopic(?int $topicId): void
    {
        $this->focusedTopicId = ($topicId !== null && $topicId > 0) ? $topicId : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTopicalMapOverviewProperty(): array
    {
        $siteId = $this->resolveMapSiteId();
        if ($siteId <= 0) {
            return [
                'site_id' => 0,
                'summary' => [
                    'topic_count' => 0,
                    'total_articles' => 0,
                    'total_keywords' => 0,
                    'source_updated_at' => null,
                ],
                'topics' => [],
                'empty' => true,
            ];
        }

        $payload = app(TopicalMapReadModel::class)->overview($siteId)->toArray();
        $payload['empty'] = ($payload['topics'] ?? []) === [];

        return $payload;
    }

    /**
     * Livewire-callable lazy children for chart drill-down.
     *
     * @return array<string, mixed>
     */
    public function loadTopicChildren(int $topicId): array
    {
        $siteId = $this->resolveMapSiteId();
        if ($siteId <= 0 || $topicId <= 0) {
            return ['ok' => false, 'error' => 'invalid_args', 'children' => []];
        }

        $children = app(TopicalMapReadModel::class)->topicChildren($siteId, $topicId);
        if ($children === null) {
            return ['ok' => false, 'error' => 'topic_not_found', 'children' => []];
        }

        return ['ok' => true, 'error' => null] + $children->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function loadNetworkNeighborhood(?int $topicId = null): array
    {
        $siteId = $this->resolveMapSiteId();
        if ($siteId <= 0) {
            return ['nodes' => [], 'links' => [], 'truncated' => false, 'showing_topics' => 0, 'total_topics' => 0];
        }

        $ids = ($topicId !== null && $topicId > 0) ? [$topicId] : [];

        return app(TopicalMapReadModel::class)->membershipNeighborhood($siteId, $ids);
    }

    public function runTopicalMapAudit(): void
    {
        if ($this->auditRunning) {
            return;
        }

        $siteId = $this->resolveMapSiteId();
        if ($siteId <= 0) {
            Notification::make()
                ->title((string) __('seo-content-ai::filament.keyword.topical_map_need_site'))
                ->danger()
                ->send();

            return;
        }

        $this->auditRunning = true;
        $this->auditError = '';
        $this->auditResult = null;

        try {
            $actorId = auth()->id();
            $result = app(TopicalMapAuditService::class)->audit(
                $siteId,
                is_numeric($actorId) ? (int) $actorId : null,
            );
            if (! $result['ok']) {
                $this->auditError = (string) $result['message'];
                Notification::make()
                    ->title((string) __('seo-content-ai::filament.keyword.topical_map_audit_failed'))
                    ->body($this->auditError)
                    ->danger()
                    ->send();

                return;
            }
            $this->auditResult = $result['payload'];
            Notification::make()
                ->title((string) __('seo-content-ai::filament.keyword.topical_map_audit_ready'))
                ->success()
                ->send();
        } catch (Throwable $e) {
            $this->auditError = $e->getMessage();
            Notification::make()
                ->title((string) __('seo-content-ai::filament.keyword.topical_map_audit_failed'))
                ->body($this->auditError)
                ->danger()
                ->send();
        } finally {
            $this->auditRunning = false;
        }
    }

    public function topicDetailUrl(int $topicId): string
    {
        if ($topicId <= 0) {
            return KeywordResource::getUrl('clusters');
        }

        return KeywordResource::getUrl('cluster', ['topic' => $topicId]);
    }

    private function resolveMapSiteId(): int
    {
        $siteId = (int) ($this->keywordWorkspaceSiteId ?? 0);
        if ($siteId <= 0 || ! SeoAccessControl::canAccessSite($siteId)) {
            return 0;
        }

        return $siteId;
    }
}
