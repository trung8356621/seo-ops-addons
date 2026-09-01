<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\DissolvesTopicClusters;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\ReclustersTopicClusters;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterExclusionService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterDetailBuilder;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicClusterDirtyState;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\CreateManualTopicClusterService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterIndexMcpPreviewSummary;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\McpTopicGroupService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordPhrasePresentation;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\UpdateClusterCanonicalService;
use Omnichannel\Addons\Seo\Support\DomainContext;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use Filament\Notifications\Notification;
use Livewire\Attributes\Renderless;
use RuntimeException;

final class KeywordTopicClusters extends Page
{
    use DissolvesTopicClusters;
    use HasKeywordWorkspaceNavigation;
    use ReclustersTopicClusters;

    protected static string $resource = KeywordResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.keywords.pages.topic-cluster-index';

    protected static bool $shouldRegisterNavigation = false;

    public string $clusterSearch = '';

    public string $clusterSearchInput = '';

    public string $quickCreateInput = '';

    public string $coverageFilter = '';

    public bool $hasArticles = false;

    public string $clusterSort = 'mcp_share_desc';

    /** mcp = grouped projection; seo = raw SEO clusters. */
    public string $clusterProjection = 'mcp';

    /** Bumps after cluster topology mutations so summary counters remount. */
    public int $clusterDataEpoch = 0;

    /**
     * Request-scoped summary cache keyed by site/language/epoch.
     *
     * @var array{key: string, value: array<string, mixed>}|null
     */
    private ?array $summaryCache = null;

    public bool $mcpGroupModalOpen = false;

    /** Cluster that opened the modal (seed / context only). */
    public string $mcpGroupAnchorKey = '';

    public string $mcpGroupMode = 'create';

    public string $mcpGroupGroupRef = '';

    public string $mcpGroupMaskName = '';

    public bool $mcpGroupMaskManual = false;

    /** @var list<string> */
    public array $mcpGroupMemberKeys = [];

    public string $mcpGroupSearch = '';

    public function mount(): void
    {
        $this->initializeKeywordWorkspaceSiteFilter();
        $this->redirectToFirstAccessibleDomainIfNeeded();
        $this->dispatchKeywordWorkspaceLanguageContext();
        $this->clusterSearchInput = $this->clusterSearch;
    }

    public function applyClusterSearch(): void
    {
        $this->clusterSearch = trim($this->clusterSearchInput);
        $this->clusterSearchInput = $this->clusterSearch;
    }

    public function clearClusterSearch(): void
    {
        $this->clusterSearch = '';
        $this->clusterSearchInput = '';
    }

    public function onKeywordWorkspaceSiteFilterChanged(): void
    {
        $this->clusterDataEpoch++;
    }

    /**
     * Topic Cluster / DNA / recluster require one concrete domain — never All.
     */
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
                KeywordResource::getUrl('clusters'),
                (int) $first->getKey(),
            ),
            navigate: false,
        );

        return true;
    }

    public static function canAccess(array $parameters = []): bool
    {
        return KeywordResource::canViewAny();
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.keyword.topic_cluster_title');
    }

    protected function getActiveKeywordWorkspaceKey(): string
    {
        return 'workspace-2';
    }

    /**
     * @return array<string, int>
     */
    /**
     * @return array{cluster_count: int, coverage_percent: float, estimated_tokens: int, total_topics: int}
     */
    public function getMcpPreviewSummary(): array
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();

        return ($siteId !== null && $siteId > 0)
            ? app(ClusterIndexMcpPreviewSummary::class)->summarize(
                $siteId,
                $this->resolveKeywordLanguageFilterVariants(),
            )
            : [
                'cluster_count' => 0,
                'coverage_percent' => 0.0,
                'estimated_tokens' => 0,
                'total_topics' => 0,
            ];
    }

    public function quickCreateClusterExists(): bool
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();
        $phrase = trim($this->quickCreateInput !== '' ? $this->quickCreateInput : $this->clusterSearchInput);
        if ($siteId === null || $siteId <= 0 || $phrase === '') {
            return false;
        }

        return app(CreateManualTopicClusterService::class)->normalizedExists($siteId, $phrase);
    }

    /**
     * @return array{ok: bool, row?: array<string, mixed>}
     */
    public function quickCreateCluster(): array
    {
        if (! $this->canEditClusterCanonical()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_canonical_edit_denied'))
                ->danger()
                ->send();

            return ['ok' => false];
        }

        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();
        $phrase = trim($this->quickCreateInput !== '' ? $this->quickCreateInput : $this->clusterSearchInput);
        if ($phrase === '') {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_quick_create_empty'))
                ->warning()
                ->send();

            return ['ok' => false];
        }

        try {
            $created = app(CreateManualTopicClusterService::class)->create($siteId, $phrase);
        } catch (RuntimeException $e) {
            $message = $e->getMessage() === 'duplicate_cluster'
                ? __('seo-content-ai::filament.keyword.topic_quick_create_duplicate')
                : $e->getMessage();
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_quick_create_failed'))
                ->body($message)
                ->danger()
                ->send();

            return ['ok' => false];
        }

        $this->quickCreateInput = '';
        $this->clusterSearch = '';
        $this->clusterSearchInput = '';
        $this->refreshClusterSummaryCounters();

        $attached = (int) ($created['attached'] ?? 0);
        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.topic_quick_create_success'))
            ->body(KeywordPhrasePresentation::present($created['label'])
                .($attached > 0
                    ? ' · '.__('seo-content-ai::filament.keyword.topic_quick_create_attached', ['count' => $attached])
                    : ''))
            ->success()
            ->send();

        return [
            'ok' => true,
            'row' => [
                ...$created,
                'label' => KeywordPhrasePresentation::present($created['label']),
            ],
        ];
    }

    public function openMcpGroupModal(string $clusterKey): void
    {
        if (! $this->canEditClusterCanonical()) {
            return;
        }

        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();
        $clusterKey = trim($clusterKey);
        if ($siteId <= 0 || $clusterKey === '') {
            return;
        }

        $svc = app(McpTopicGroupService::class);
        $map = $svc->membershipMapForSite($siteId);
        $existing = $map[$clusterKey] ?? null;

        $this->mcpGroupAnchorKey = $clusterKey;
        $this->mcpGroupSearch = '';
        $this->mcpGroupModalOpen = true;

        if (is_array($existing)) {
            $this->mcpGroupMode = 'manage';
            $this->mcpGroupGroupRef = (string) ($existing['group_ref'] ?? '');
            $this->mcpGroupMaskName = (string) ($existing['mask_name'] ?? '');
            $this->mcpGroupMaskManual = true;
            $members = [];
            foreach ($map as $key => $row) {
                if (($row['group_ref'] ?? '') === $this->mcpGroupGroupRef) {
                    $members[] = (string) $key;
                }
            }
            $this->mcpGroupMemberKeys = array_values(array_unique(array_filter($members)));
            if (! in_array($clusterKey, $this->mcpGroupMemberKeys, true)) {
                $this->mcpGroupMemberKeys[] = $clusterKey;
            }

            return;
        }

        $this->mcpGroupMode = 'create';
        $this->mcpGroupGroupRef = '';
        $this->mcpGroupMaskManual = false;
        $this->mcpGroupMemberKeys = [$clusterKey];
        $this->refreshMcpGroupMaskSuggestion();
    }

    public function closeMcpGroupModal(): void
    {
        $this->mcpGroupModalOpen = false;
        $this->mcpGroupAnchorKey = '';
        $this->mcpGroupMode = 'create';
        $this->mcpGroupGroupRef = '';
        $this->mcpGroupMaskName = '';
        $this->mcpGroupMaskManual = false;
        $this->mcpGroupMemberKeys = [];
        $this->mcpGroupSearch = '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getMcpGroupSuggestionsProperty(): array
    {
        if (! $this->mcpGroupModalOpen) {
            return [];
        }
        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();
        $q = trim($this->mcpGroupSearch);
        if ($siteId <= 0 || mb_strlen($q) < 1) {
            return [];
        }

        return app(McpTopicGroupService::class)->searchClusters(
            $siteId,
            $q,
            $this->mcpGroupMemberKeys,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getMcpGroupMemberCardsProperty(): array
    {
        if (! $this->mcpGroupModalOpen) {
            return [];
        }
        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();

        return app(McpTopicGroupService::class)->clusterCards(
            $siteId,
            $this->mcpGroupMemberKeys,
        );
    }

    /**
     * @return array{ready: bool, name: string, article_count: int, internal_link_count: int, weight: float, weight_display: string}
     */
    public function getMcpGroupPreviewProperty(): array
    {
        if (! $this->mcpGroupModalOpen) {
            return [
                'ready' => false,
                'name' => '',
                'article_count' => 0,
                'internal_link_count' => 0,
                'weight' => 0.0,
                'weight_display' => '0',
            ];
        }

        return app(McpTopicGroupService::class)->previewDraft(
            (int) $this->resolveKeywordWorkspaceSiteId(),
            $this->mcpGroupMemberKeys,
            $this->mcpGroupMaskName,
        );
    }

    public function selectMcpGroupSuggestion(string $clusterKey): void
    {
        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();
        $clusterKey = trim($clusterKey);
        if ($siteId <= 0 || $clusterKey === '') {
            return;
        }

        $svc = app(McpTopicGroupService::class);
        $map = $svc->membershipMapForSite($siteId);
        $existing = $map[$clusterKey] ?? null;

        if (is_array($existing)) {
            // Absorb existing peer group into this draft (no nested groups).
            $members = $this->mcpGroupMemberKeys;
            foreach ($map as $key => $row) {
                if (($row['group_ref'] ?? '') === ($existing['group_ref'] ?? '')) {
                    $members[] = (string) $key;
                }
            }
            $this->mcpGroupMemberKeys = array_values(array_unique(array_filter($members)));
            if ($this->mcpGroupMode === 'create' && ! $this->mcpGroupMaskManual && $this->mcpGroupMaskName === '') {
                $this->mcpGroupMaskName = (string) ($existing['mask_name'] ?? '');
            }
            $this->mcpGroupSearch = '';
            if (! $this->mcpGroupMaskManual) {
                $this->refreshMcpGroupMaskSuggestion();
            }

            return;
        }

        $this->mcpGroupMemberKeys = array_values(array_unique(array_filter([
            ...$this->mcpGroupMemberKeys,
            $clusterKey,
            $this->mcpGroupAnchorKey,
        ])));
        $this->mcpGroupSearch = '';
        if (! $this->mcpGroupMaskManual) {
            $this->refreshMcpGroupMaskSuggestion();
        }
    }

    public function removeMcpGroupMember(string $clusterKey): void
    {
        $clusterKey = trim($clusterKey);
        if ($clusterKey === '') {
            return;
        }

        $this->mcpGroupMemberKeys = array_values(array_filter(
            $this->mcpGroupMemberKeys,
            static fn (string $key): bool => $key !== $clusterKey,
        ));
        // Never silently overwrite a user-authored mask when removing members.
        if (! $this->mcpGroupMaskManual && $this->mcpGroupMode === 'create') {
            $this->refreshMcpGroupMaskSuggestion();
        }
    }

    public function markMcpGroupMaskManual(): void
    {
        $this->mcpGroupMaskManual = true;
    }

    public function resuggestMcpGroupMask(): void
    {
        $this->mcpGroupMaskManual = false;
        $this->refreshMcpGroupMaskSuggestion();
    }

    public function confirmMcpGroup(): void
    {
        if (! $this->canEditClusterCanonical()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_canonical_edit_denied'))
                ->danger()
                ->send();

            return;
        }

        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();
        $mask = KeywordPhrasePresentation::present(trim($this->mcpGroupMaskName));
        $members = array_values(array_unique(array_filter($this->mcpGroupMemberKeys)));
        if ($siteId <= 0) {
            return;
        }

        if (count($members) < 2) {
            if ($this->mcpGroupMode === 'manage' && $this->mcpGroupGroupRef !== '') {
                app(McpTopicGroupService::class)->dissolveGroup($siteId, $this->mcpGroupGroupRef);
                $this->closeMcpGroupModal();
                $this->refreshClusterSummaryCounters();
                Notification::make()
                    ->title(__('seo-content-ai::filament.keyword.topic_mcp_ungroup_success'))
                    ->success()
                    ->send();

                return;
            }
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_mcp_group_pick_required'))
                ->warning()
                ->send();

            return;
        }

        if ($mask === '') {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_mcp_group_mask_required'))
                ->warning()
                ->send();

            return;
        }

        try {
            app(McpTopicGroupService::class)->syncGroup(
                $siteId,
                $members,
                $mask,
                $this->mcpGroupMaskManual,
                $this->mcpGroupGroupRef !== '' ? $this->mcpGroupGroupRef : null,
            );
        } catch (RuntimeException $e) {
            $body = match ($e->getMessage()) {
                'mcp_group_table_missing' => __('seo-content-ai::filament.keyword.topic_mcp_group_table_missing'),
                'invalid_mcp_group_input' => __('seo-content-ai::filament.keyword.topic_mcp_group_pick_required'),
                default => $e->getMessage(),
            };
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_mcp_group_failed'))
                ->body($body)
                ->danger()
                ->send();

            return;
        }

        $this->closeMcpGroupModal();
        $this->refreshClusterSummaryCounters();

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.topic_mcp_group_success'))
            ->success()
            ->send();
    }

    public function dissolveMcpGroupFromModal(): void
    {
        if (! $this->canEditClusterCanonical()) {
            return;
        }
        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();
        $ref = trim($this->mcpGroupGroupRef !== '' ? $this->mcpGroupGroupRef : $this->mcpGroupAnchorKey);
        if ($siteId <= 0 || $ref === '') {
            return;
        }

        app(McpTopicGroupService::class)->dissolveGroup($siteId, $ref);
        $this->closeMcpGroupModal();
        $this->refreshClusterSummaryCounters();

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.topic_mcp_ungroup_success'))
            ->success()
            ->send();
    }

    public function ungroupMcp(string $clusterKey): void
    {
        if (! $this->canEditClusterCanonical()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_canonical_edit_denied'))
                ->danger()
                ->send();

            return;
        }

        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();
        $clusterKey = trim($clusterKey);
        if ($siteId <= 0 || $clusterKey === '') {
            return;
        }

        app(McpTopicGroupService::class)->ungroup($siteId, $clusterKey);
        $this->refreshClusterSummaryCounters();

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.topic_mcp_ungroup_success'))
            ->success()
            ->send();
    }

    private function refreshMcpGroupMaskSuggestion(): void
    {
        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();
        if ($siteId <= 0 || $this->mcpGroupMemberKeys === []) {
            $this->mcpGroupMaskName = '';

            return;
        }
        $this->mcpGroupMaskName = app(McpTopicGroupService::class)
            ->suggestMaskNameFromKeys($siteId, $this->mcpGroupMemberKeys);
    }

    public function getSummary(): array
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();
        $languageVariants = $this->resolveKeywordLanguageFilterVariants();
        $key = ($siteId ?? 0).'|'.implode(',', $languageVariants ?? []).'|'.$this->clusterDataEpoch;

        if ($this->summaryCache !== null && ($this->summaryCache['key'] ?? null) === $key) {
            return $this->summaryCache['value'];
        }

        $value = app(KeywordClusterQuery::class)->summary($siteId, $languageVariants);
        $this->summaryCache = ['key' => $key, 'value' => $value];

        return $value;
    }

    public function clusterStateIsDirty(): bool
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();

        return $siteId !== null && $siteId > 0 && TopicClusterDirtyState::isDirty($siteId);
    }

    public function getClusters()
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();
        $listPath = KeywordResource::getUrl('clusters');
        $appends = array_filter([
            'search' => $this->clusterSearch !== '' ? $this->clusterSearch : null,
            'coverage' => $this->coverageFilter !== '' ? $this->coverageFilter : null,
            'has_articles' => $this->hasArticles ? 1 : null,
            'sort' => $this->clusterSort !== 'mcp_share_desc' ? $this->clusterSort : null,
            DomainContext::SITE_ID_QUERY_KEY => ($siteId !== null && $siteId > 0) ? $siteId : null,
        ], static fn (mixed $v): bool => $v !== null);

        $paginator = app(KeywordClusterQuery::class)
            ->paginateClusters(
                $siteId,
                [
                    'search' => $this->clusterSearch,
                    'coverage' => $this->coverageFilter,
                    'has_articles' => $this->hasArticles,
                    'sort' => $this->clusterSort,
                    'projection' => $this->clusterProjection === 'seo' ? 'seo' : 'mcp',
                    'language_variants' => $this->resolveKeywordLanguageFilterVariants(),
                ],
                path: $listPath,
            )
            ->appends($appends);

        return $paginator;
    }

    public function clusterUrl(string $clusterKey): string
    {
        return app(DomainContextResolver::class)->appendSiteToUrl(
            KeywordResource::getUrl('cluster', ['clusterKey' => $clusterKey]),
            $this->resolveKeywordWorkspaceSiteId(),
        );
    }

    public function unclusteredUrl(): string
    {
        return app(KeywordClusterQuery::class)->unclusteredListUrl($this->resolveKeywordWorkspaceSiteId());
    }

    public function canEditClusterCanonical(): bool
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();

        return SeoAccessControl::canMutateInSeoPanel()
            && $siteId !== null
            && $siteId > 0
            && SeoAccessControl::canAccessSite($siteId);
    }

    /**
     * Inline MCP mask rename from grouped Cluster Index — item-level, no list refresh.
     *
     * @return array{ok: bool, label?: string}
     */
    #[Renderless]
    public function saveMcpGroupMaskFromIndex(string $groupRef, string $maskName): array
    {
        if (! $this->canEditClusterCanonical()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_canonical_edit_denied'))
                ->danger()
                ->send();

            return ['ok' => false];
        }

        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();
        $groupRef = trim($groupRef);
        $maskName = trim($maskName);
        if ($siteId <= 0 || $groupRef === '' || $maskName === '') {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_mcp_group_mask_required'))
                ->warning()
                ->send();

            return ['ok' => false];
        }

        try {
            $result = app(McpTopicGroupService::class)->updateMaskName($siteId, $groupRef, $maskName);
        } catch (RuntimeException $e) {
            $body = match ($e->getMessage()) {
                'mcp_group_table_missing' => __('seo-content-ai::filament.keyword.topic_mcp_group_table_missing'),
                'mcp_group_not_found' => __('seo-content-ai::filament.keyword.topic_mcp_group_mask_failed'),
                'invalid_mcp_group_input' => __('seo-content-ai::filament.keyword.topic_mcp_group_mask_required'),
                default => $e->getMessage(),
            };
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_mcp_group_mask_failed'))
                ->body($body)
                ->danger()
                ->send();

            return ['ok' => false];
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.topic_mcp_group_mask_saved'))
            ->success()
            ->send();

        return [
            'ok' => true,
            'label' => KeywordPhrasePresentation::present((string) ($result['mask_name'] ?? $maskName)),
        ];
    }

    /**
     * Inline canonical edit from Cluster Index — item-level patch, no full list refresh.
     *
     * @return array{
     *     ok: bool,
     *     label?: string,
     *     keyword_count?: int,
     *     dna_branch_count?: int,
     *     covered_branch_count?: int,
     *     uncovered_branch_count?: int,
     *     article_count?: int,
     *     internal_link_count?: int,
     *     intent?: string,
     *     coverage?: string,
     *     topical_share?: float,
     *     canonical_source?: string,
     *     state?: string,
     *     removed?: bool
     * }
     */
    #[Renderless]
    public function saveClusterCanonicalFromIndex(string $clusterKey, string $phrase): array
    {
        if (! $this->canEditClusterCanonical()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_canonical_edit_denied'))
                ->danger()
                ->send();

            return ['ok' => false];
        }

        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();
        $clusterKey = trim($clusterKey);

        try {
            $result = app(UpdateClusterCanonicalService::class)
                ->setManualCanonical($siteId, $clusterKey, $phrase);
        } catch (RuntimeException $e) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_canonical_edit_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return ['ok' => false];
        }

        $detail = app(KeywordClusterDetailBuilder::class)->build($siteId, $clusterKey);
        if ($detail === null) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_canonical_edit_saved'))
                ->body(__('seo-content-ai::filament.keyword.topic_canonical_edit_body', [
                    'attached' => $result['attached'],
                    'detached' => $result['detached'],
                ]))
                ->success()
                ->send();

            return [
                'ok' => true,
                'removed' => true,
                'cluster_key' => $clusterKey,
                'label' => trim($phrase),
                'keyword_count' => 0,
                'dna_branch_count' => 0,
                'covered_branch_count' => 0,
                'uncovered_branch_count' => 0,
                'article_count' => 0,
                'internal_link_count' => 0,
                'intent' => '',
                'coverage' => 'unknown',
            ];
        }

        $shareMap = ($siteId > 0)
            ? app(\Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpClusterTopicalProfileBuilder::class)->topicalShareMap($siteId)
            : [];

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.topic_canonical_edit_saved'))
            ->body(__('seo-content-ai::filament.keyword.topic_canonical_edit_body', [
                'attached' => $result['attached'],
                'detached' => $result['detached'],
            ]))
            ->success()
            ->send();

        return [
            'ok' => true,
            'removed' => false,
            'cluster_key' => $clusterKey,
            'label' => KeywordPhrasePresentation::present((string) ($detail['label'] ?? $result['canonical_phrase'])),
            'keyword_count' => (int) ($detail['keyword_count'] ?? 0),
            'article_count' => (int) ($detail['article_count'] ?? 0),
            'internal_link_count' => (int) ($detail['internal_link_count'] ?? $detail['internal_links'] ?? 0),
            'intent' => (string) ($detail['intent'] ?? ''),
            'coverage' => (string) ($detail['coverage'] ?? 'unknown'),
            'topical_share' => (float) ($shareMap[$clusterKey] ?? 0.0),
            'canonical_source' => (string) ($detail['canonical_source'] ?? 'auto'),
            'state' => ((int) ($detail['keyword_count'] ?? 0)) === 0 ? 'planned' : 'active',
        ];
    }

    public function refreshClusterSummaryCounters(): void
    {
        $this->clusterDataEpoch++;
        $this->dispatch('cluster-data-updated');
    }

    public function skipClusterFromMcp(string $clusterKey): void
    {
        $this->mutateClusterExclusion($clusterKey, static fn (ClusterExclusionService $svc, int $siteId, string $key): array => $svc->skipMcp($siteId, $key));
    }

    public function restoreClusterMcp(string $clusterKey): void
    {
        $this->mutateClusterExclusion($clusterKey, static fn (ClusterExclusionService $svc, int $siteId, string $key): array => $svc->restoreMcp($siteId, $key));
    }

    public function excludeClusterFromSeo(string $clusterKey): void
    {
        $this->mutateClusterExclusion($clusterKey, static fn (ClusterExclusionService $svc, int $siteId, string $key): array => $svc->excludeFromSeo($siteId, $key));
    }

    public function restoreClusterSeo(string $clusterKey): void
    {
        $this->mutateClusterExclusion($clusterKey, static fn (ClusterExclusionService $svc, int $siteId, string $key): array => $svc->restoreSeo($siteId, $key));
    }

    /**
     * @param  callable(ClusterExclusionService, int, string): array{cluster_key: string, mcp_excluded: bool, seo_excluded: bool}  $action
     */
    private function mutateClusterExclusion(string $clusterKey, callable $action): void
    {
        if (! $this->canEditClusterCanonical()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_canonical_edit_denied'))
                ->danger()
                ->send();

            return;
        }

        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();
        $clusterKey = trim($clusterKey);
        if ($siteId <= 0 || $clusterKey === '') {
            return;
        }

        try {
            $action(app(ClusterExclusionService::class), $siteId, $clusterKey);
        } catch (RuntimeException $e) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.keyword_item_exclusion_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.keyword_item_exclusion_saved'))
            ->success()
            ->send();

        $this->refreshClusterSummaryCounters();
    }
}
