<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use App\Models\Site;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\TopicalMapAuditHistoryLinker;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\RunsTopicalMapAuditAndTags;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditContracts;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapReadModel;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Throwable;

/**
 * Site-level Topical Map — Keyword Landscape visualization (Tree / Network / Sunburst).
 */
final class KeywordTopicalMap extends Page
{
    use HasKeywordWorkspaceNavigation;
    use RunsTopicalMapAuditAndTags;

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

    public ?int $auditPromptResultId = null;

    public bool $showAuditOverlay = false;

    /** When true, no tag filter restriction. */
    public bool $tagFilterAll = true;

    /** Include Topics with zero tags. */
    public bool $tagFilterUntagged = false;

    /** @var list<int> */
    public array $selectedTagIds = [];

    public function mount(): void
    {
        $this->initializeKeywordWorkspaceSiteFilter();
        if ($this->redirectToFirstAccessibleDomainIfNeeded()) {
            return;
        }
        $this->dispatchKeywordWorkspaceLanguageContext();
        $this->mapRenderer = 'tree';
        $this->refreshAiAuditSnapshot();
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
     * Focus a Topic from an AI Audit finding/opportunity/action topic_ref.
     */
    public function focusTopicFromRef(?string $topicRef): void
    {
        $id = TopicalMapAuditContracts::topicIdFromRef($topicRef);
        $this->focusTopic($id);
        $this->showAuditOverlay = false;
        if ($id !== null && $id > 0) {
            $this->dispatch('topical-map-focus-topic', topicId: $id);
        }
    }

    public function toggleTagFilterAll(): void
    {
        $this->tagFilterAll = true;
        $this->tagFilterUntagged = false;
        $this->selectedTagIds = [];
        $this->dispatchFilteredOverview();
    }

    public function toggleTagFilterUntagged(): void
    {
        if ($this->tagFilterAll) {
            $this->tagFilterAll = false;
        }
        $this->tagFilterUntagged = ! $this->tagFilterUntagged;
        if (! $this->tagFilterUntagged && $this->selectedTagIds === []) {
            $this->tagFilterAll = true;
        }
        $this->dispatchFilteredOverview();
    }

    public function toggleTagFilter(int $tagId): void
    {
        if ($tagId <= 0) {
            return;
        }
        if ($this->tagFilterAll) {
            $this->tagFilterAll = false;
        }
        if (in_array($tagId, $this->selectedTagIds, true)) {
            $this->selectedTagIds = array_values(array_filter(
                $this->selectedTagIds,
                static fn (int $id): bool => $id !== $tagId,
            ));
        } else {
            $this->selectedTagIds[] = $tagId;
        }
        if ($this->selectedTagIds === [] && ! $this->tagFilterUntagged) {
            $this->tagFilterAll = true;
        }
        $this->dispatchFilteredOverview();
    }

    public function openAuditOverlay(): void
    {
        if (is_array($this->auditResult)) {
            $this->showAuditOverlay = true;
        }
    }

    public function closeAuditOverlay(): void
    {
        $this->showAuditOverlay = false;
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
                    'untagged_count' => 0,
                ],
                'topics' => [],
                'tag_facets' => [],
                'empty' => true,
            ];
        }

        $payload = app(TopicalMapReadModel::class)->overview($siteId)->toArray();
        $payload['topics'] = $this->applyTagFilter(is_array($payload['topics'] ?? null) ? $payload['topics'] : []);
        $payload['empty'] = ($payload['topics'] ?? []) === [] && (int) ($payload['summary']['topic_count'] ?? 0) === 0;

        return $payload;
    }

    /**
     * Chart-facing overview with current tag filter applied (may filter to empty topic list).
     *
     * @return array<string, mixed>
     */
    public function filteredOverviewPayload(): array
    {
        return $this->topicalMapOverview;
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

        if (! $this->topicVisibleInCurrentFilter($topicId)) {
            return ['ok' => false, 'error' => 'topic_filtered_out', 'children' => []];
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

        $overview = app(TopicalMapReadModel::class)->overview($siteId);
        $visible = $this->applyTagFilter($overview->topics);
        $visibleIds = array_map(static fn (array $t): int => (int) $t['id'], $visible);

        if ($topicId !== null && $topicId > 0) {
            if (! in_array($topicId, $visibleIds, true)) {
                return ['nodes' => [], 'links' => [], 'truncated' => false, 'showing_topics' => 0, 'total_topics' => count($visibleIds)];
            }
            $ids = [$topicId];
        } else {
            $ids = array_slice($visibleIds, 0, 40);
        }

        return app(TopicalMapReadModel::class)->membershipNeighborhood($siteId, $ids);
    }

    public function openTopicDetail(int $topicId): void
    {
        if ($topicId <= 0) {
            return;
        }
        $this->redirect($this->topicDetailUrl($topicId), navigate: false);
    }

    /**
     * Canonical manual AI action — confirmation first (same as Topics page).
     */
    public function runTopicalMapAudit(): void
    {
        $this->beginConfirmAiAudit();
    }

    /**
     * After Topics-style confirm, also keep result on this page overlay.
     */
    public function confirmRunAiAuditAndTags(): void
    {
        $this->confirmAiAudit = false;
        if (! $this->canRunAiAuditAndTags()) {
            Notification::make()
                ->title((string) __('seo-content-ai::filament.keyword.ai_audit_tags_disabled'))
                ->warning()
                ->send();

            return;
        }

        $siteId = (int) $this->resolveMapSiteId();
        $this->aiAuditRunning = true;
        $this->auditRunning = true;
        $this->auditError = '';
        $this->auditResult = null;
        $this->auditPromptResultId = null;

        try {
            $actorId = auth()->id();
            $actor = is_numeric($actorId) ? (int) $actorId : null;
            $result = app(TopicalMapAuditService::class)->audit($siteId, $actor);
            $this->auditPromptResultId = isset($result['prompt_result_id']) && is_numeric($result['prompt_result_id'])
                ? (int) $result['prompt_result_id']
                : null;

            try {
                app(TopicalMapAuditHistoryLinker::class)->linkFromAuditResult($siteId, $actor, $result);
            } catch (Throwable $linkError) {
                report($linkError);
            }

            $this->refreshAiAuditSnapshot();

            if (! ($result['ok'] ?? false)) {
                $this->auditError = (string) ($result['message'] ?? '');
                Notification::make()
                    ->title((string) __('seo-content-ai::filament.keyword.topical_map_audit_failed'))
                    ->body($this->auditError)
                    ->danger()
                    ->send();

                return;
            }

            $this->auditResult = is_array($result['payload'] ?? null) ? $result['payload'] : null;
            $this->showAuditOverlay = true;

            $payload = $this->auditResult ?? [];
            $tagApply = is_array($result['tag_apply'] ?? null) ? $result['tag_apply'] : [];
            $findings = is_array($payload['findings'] ?? null) ? count($payload['findings']) : 0;
            $opps = is_array($payload['opportunities'] ?? null) ? count($payload['opportunities']) : 0;

            if (! ($tagApply['ok'] ?? true)) {
                Notification::make()
                    ->title((string) __('seo-content-ai::filament.keyword.ai_audit_tags_apply_failed'))
                    ->body((string) ($result['message'] ?? $tagApply['error'] ?? ''))
                    ->warning()
                    ->send();

                return;
            }

            Notification::make()
                ->title((string) __('seo-content-ai::filament.keyword.ai_audit_tags_complete'))
                ->body((string) __('seo-content-ai::filament.keyword.ai_audit_tags_summary', [
                    'findings' => $findings,
                    'opportunities' => $opps,
                    'tags' => (int) ($tagApply['tag_count'] ?? 0),
                    'tagged' => (int) ($tagApply['topic_tagged_count'] ?? 0),
                    'topics' => (int) ($this->aiAuditStatusSnapshot()['topic_count'] ?? 0),
                    'ai_assignments' => (int) ($tagApply['ai_assignment_count'] ?? 0),
                    'untagged' => (int) ($tagApply['untagged_topics'] ?? 0),
                ]))
                ->success()
                ->send();

            $this->dispatchFilteredOverview();
        } catch (Throwable $e) {
            $this->auditError = $e->getMessage();
            Notification::make()
                ->title((string) __('seo-content-ai::filament.keyword.topical_map_audit_failed'))
                ->body($this->auditError)
                ->danger()
                ->send();
        } finally {
            $this->aiAuditRunning = false;
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

    public function topicsPageUrl(): string
    {
        return KeywordResource::getUrl('clusters');
    }

    private function resolveMapSiteId(): int
    {
        $siteId = (int) ($this->keywordWorkspaceSiteId ?? 0);
        if ($siteId <= 0 || ! SeoAccessControl::canAccessSite($siteId)) {
            return 0;
        }

        return $siteId;
    }

    /**
     * @param  list<array<string, mixed>>  $topics
     * @return list<array<string, mixed>>
     */
    private function applyTagFilter(array $topics): array
    {
        if ($this->tagFilterAll) {
            return $topics;
        }

        return app(TopicalMapReadModel::class)->filterTopicsByTags(
            $topics,
            $this->selectedTagIds,
            $this->tagFilterUntagged,
        );
    }

    private function topicVisibleInCurrentFilter(int $topicId): bool
    {
        if ($this->tagFilterAll) {
            return true;
        }
        $siteId = $this->resolveMapSiteId();
        if ($siteId <= 0) {
            return false;
        }
        $all = app(TopicalMapReadModel::class)->overview($siteId)->topics;
        foreach ($this->applyTagFilter($all) as $topic) {
            if ((int) ($topic['id'] ?? 0) === $topicId) {
                return true;
            }
        }

        return false;
    }

    private function dispatchFilteredOverview(): void
    {
        $this->dispatch('topical-map-overview-updated', overview: $this->filteredOverviewPayload());
    }

    private function redirectToFirstAccessibleDomainIfNeeded(): bool
    {
        if ($this->resolveKeywordWorkspaceSiteId() !== null) {
            return false;
        }

        $first = SeoAccessControl::accessibleSitesQuery()->orderBy('domain')->first();
        if (! $first instanceof Site) {
            return false;
        }

        $this->redirect(
            app(DomainContextResolver::class)->appendSiteToUrl(
                KeywordResource::getUrl('topical-map'),
                (int) $first->getKey(),
            ),
            navigate: false,
        );

        return true;
    }
}
