<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Pages\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiTopic;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoTopicalMapVersion;
use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResultNotifier;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\AnalyzeKeywordWorkspaceCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ApproveKeywordClustersCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ApproveKeywordsCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ApproveTopicalMapCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ArchiveKeywordWorkspaceCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\BuildTopicalMapCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\CreateContentProjectFromKeywordClustersCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\CreateContentProjectFromTopicalMapCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ImportKeywordsCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\PreviewContentProjectFromClustersCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\PreviewContentProjectFromTopicalMapCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ReviewTopicalMapCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\SaveTopicalMapVersionCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Providers\ManualImportSerpProvider;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpResultClassifier;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpUrlNormalizationService;

final class ViewKeywordWorkspace extends SeoPanelPage
{
    protected static ?string $slug = 'keyword-intelligence/{workspace_ref}';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'seo-content-ai::filament.pages.keyword-intelligence.view-keyword-workspace';

    public string $workspaceRef = '';

    /** @var array<string, mixed> */
    public array $workspace = [];

    public string $activeTab = 'overview';

    /** @var list<array<string, mixed>> */
    public array $keywords = [];

    /** @var list<array<string, mixed>> */
    public array $clusters = [];

    /** @var array<string, mixed>|null */
    public ?array $topicalMap = null;

    /** @var list<array<string, mixed>> */
    public array $topics = [];

    public string $topicalMapMode = 'balanced';

    public string $mapConversionPolicy = 'new_only';

    /** @var array<string, mixed>|null */
    public ?array $mapConvertPreview = null;

    public ?string $mapConvertConfirmationToken = null;

    public string $importText = '';

    public bool $importAsPreview = true;

    public bool $importKeepDuplicates = false;

    /** @var array<string, mixed>|null */
    public ?array $importResult = null;

    public string $serpImportPayload = '';

    public string $serpImportFormat = 'json';

    /** @var array<string, mixed>|null */
    public ?array $serpImportPreview = null;

    /** @var list<string> */
    public array $selectedKeywordRefs = [];

    /** @var list<string> */
    public array $selectedClusterRefs = [];

    /** @var array<string, mixed>|null */
    public ?array $convertPreview = null;

    public ?string $convertConfirmationToken = null;

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }

    public function getTitle(): string
    {
        return (string) ($this->workspace['name'] ?? __('seo-content-ai::filament.keyword_intelligence.title'));
    }

    public function mount(string $workspace_ref): void
    {
        $this->workspaceRef = $workspace_ref;
        $this->loadOverview();
        $this->loadKeywords();
        $this->loadClusters();
    }

    public function switchTab(string $tab): void
    {
        $allowed = ['overview', 'keywords', 'clusters', 'existing_content', 'analysis', 'topical_map', 'serp_intelligence'];
        if (! in_array($tab, $allowed, true)) {
            return;
        }

        $this->activeTab = $tab;

        match ($tab) {
            'keywords' => $this->loadKeywords(),
            'clusters' => $this->loadClusters(),
            'topical_map' => $this->loadTopicalMap(),
            default => $this->loadOverview(),
        };
    }

    public function previewSerpImport(): void
    {
        if (trim($this->serpImportPayload) === '') {
            return;
        }

        $provider = new ManualImportSerpProvider(new SerpUrlNormalizationService, new SerpResultClassifier);
        $preview = $provider->preview($this->serpImportPayload, $this->serpImportFormat);

        $this->serpImportPreview = [
            'summary' => $preview->summary,
            'valid_rows' => count($preview->validRows),
            'invalid_rows' => count($preview->invalidRows),
            'duplicate_rows' => count($preview->duplicateRows),
            'unknown_types' => count($preview->unknownTypeRows),
            'missing_urls' => count($preview->missingUrlRows),
        ];
    }

    public function importKeywords(): void
    {
        $rows = $this->parseImportText($this->importText);
        if ($rows === []) {
            return;
        }

        $command = new ImportKeywordsCommand(
            $this->workspaceRef,
            $rows,
            $this->importAsPreview,
            $this->importKeepDuplicates,
            'manual',
        );

        $result = $this->dispatchCommand($command);
        $this->importResult = $result->metadata;

        if ($result->success && ! $this->importAsPreview) {
            $this->importText = '';
            $this->loadOverview();
            $this->loadKeywords();
        }
    }

    public function analyzeWorkspace(): void
    {
        $result = $this->dispatchCommand(new AnalyzeKeywordWorkspaceCommand(
            workspaceRef: $this->workspaceRef,
            clusteringStrategy: null,
            keywordRefs: null,
            options: [
                'preserve_manual_overrides' => true,
                'recluster_draft_only' => true,
            ],
        ));

        if ($result->success) {
            $this->loadOverview();
            $this->loadKeywords();
            $this->loadClusters();
        }
    }

    public function approveSelectedKeywords(bool $approve): void
    {
        if ($this->selectedKeywordRefs === []) {
            return;
        }

        $result = $this->dispatchCommand(new ApproveKeywordsCommand(
            $this->workspaceRef,
            $this->selectedKeywordRefs,
            $approve,
        ));

        if ($result->success) {
            $this->selectedKeywordRefs = [];
            $this->loadKeywords();
        }
    }

    public function approveSelectedClusters(bool $approve): void
    {
        if ($this->selectedClusterRefs === []) {
            return;
        }

        $result = $this->dispatchCommand(new ApproveKeywordClustersCommand(
            $this->workspaceRef,
            $this->selectedClusterRefs,
            $approve,
        ));

        if ($result->success) {
            $this->loadClusters();
        }
    }

    public function buildTopicalMap(): void
    {
        $result = $this->dispatchCommand(new BuildTopicalMapCommand(
            workspaceRef: $this->workspaceRef,
            mode: $this->topicalMapMode !== '' ? $this->topicalMapMode : 'balanced',
            includeReviewedClusters: false,
            preserveManualTopics: true,
        ));

        if ($result->success) {
            $this->loadOverview();
            $this->loadTopicalMap();
        }
    }

    public function reviewTopicalMap(): void
    {
        $ref = (string) ($this->topicalMap['map_version_ref'] ?? '');
        if ($ref === '') {
            return;
        }

        $result = $this->dispatchCommand(new ReviewTopicalMapCommand($this->workspaceRef, $ref));
        if ($result->success) {
            $this->loadTopicalMap();
        }
    }

    public function approveTopicalMap(): void
    {
        $ref = (string) ($this->topicalMap['map_version_ref'] ?? '');
        if ($ref === '') {
            return;
        }

        $result = $this->dispatchCommand(new ApproveTopicalMapCommand(
            $this->workspaceRef,
            $ref,
            false,
            null,
        ));

        if ($result->success) {
            $this->loadTopicalMap();
        } elseif (isset($result->metadata['confirmation_token'])) {
            $result = $this->dispatchCommand(new ApproveTopicalMapCommand(
                $this->workspaceRef,
                $ref,
                false,
                (string) $result->metadata['confirmation_token'],
            ));
            if ($result->success) {
                $this->loadTopicalMap();
            }
        }
    }

    public function saveTopicalMapVersion(): void
    {
        $result = $this->dispatchCommand(new SaveTopicalMapVersionCommand(
            $this->workspaceRef,
            $this->topicalMapMode !== '' ? $this->topicalMapMode : null,
        ));

        if ($result->success) {
            $this->loadTopicalMap();
        }
    }

    public function previewConvertFromMap(): void
    {
        $ref = (string) ($this->topicalMap['map_version_ref'] ?? '');
        if ($ref === '' || ($this->topicalMap['status'] ?? '') !== 'approved') {
            return;
        }

        $result = $this->dispatchCommand(new PreviewContentProjectFromTopicalMapCommand(
            $this->workspaceRef,
            $ref,
            $this->mapConversionPolicy !== '' ? $this->mapConversionPolicy : 'new_only',
        ));

        if ($result->success) {
            $this->mapConvertPreview = (array) ($result->metadata['preview'] ?? []);
            $this->mapConvertConfirmationToken = isset($result->metadata['confirmation_token'])
                ? (string) $result->metadata['confirmation_token']
                : null;
        }
    }

    public function convertFromMap(): void
    {
        $ref = (string) ($this->topicalMap['map_version_ref'] ?? '');
        if ($ref === '' || ($this->topicalMap['status'] ?? '') !== 'approved') {
            return;
        }

        $result = $this->dispatchCommand(new CreateContentProjectFromTopicalMapCommand(
            workspaceRef: $this->workspaceRef,
            mapVersionRef: $ref,
            policy: $this->mapConversionPolicy !== '' ? $this->mapConversionPolicy : 'new_only',
            confirmationToken: $this->mapConvertConfirmationToken,
        ));

        if ($result->success) {
            $this->mapConvertPreview = null;
            $this->mapConvertConfirmationToken = null;
            $this->loadTopicalMap();
            $this->loadClusters();
        } elseif (isset($result->metadata['confirmation_token'])) {
            $this->mapConvertConfirmationToken = (string) $result->metadata['confirmation_token'];
            $this->mapConvertPreview = (array) ($result->metadata['preview'] ?? $this->mapConvertPreview);
        }
    }

    public function previewConvert(): void
    {
        if ($this->selectedClusterRefs === []) {
            return;
        }

        $result = $this->dispatchCommand(new PreviewContentProjectFromClustersCommand(
            $this->workspaceRef,
            $this->selectedClusterRefs,
        ));

        if ($result->success) {
            $this->convertPreview = (array) ($result->metadata['preview'] ?? []);
            $this->convertConfirmationToken = isset($result->metadata['confirmation_token'])
                ? (string) $result->metadata['confirmation_token']
                : null;
        }
    }

    public function convertToContentProject(): void
    {
        if ($this->selectedClusterRefs === []) {
            return;
        }

        $result = $this->dispatchCommand(new CreateContentProjectFromKeywordClustersCommand(
            $this->workspaceRef,
            $this->selectedClusterRefs,
            [],
            false,
            $this->convertConfirmationToken,
        ));

        if ($result->success) {
            $this->convertPreview = null;
            $this->convertConfirmationToken = null;
            $this->selectedClusterRefs = [];
            $this->loadClusters();
        } elseif (isset($result->metadata['preview'])) {
            // CONFIRMATION_REQUIRED / PREVIEW_READY dry-run fallback — surface preview again.
            $this->convertPreview = (array) $result->metadata['preview'];
        }
    }

    public function archiveWorkspace(): void
    {
        $result = $this->dispatchCommand(new ArchiveKeywordWorkspaceCommand($this->workspaceRef));

        if ($result->success) {
            $this->loadOverview();
        }
    }

    private function dispatchCommand(object $command): ContentProjectActionResult
    {
        $siteId = (int) ($this->workspace['site_id'] ?? 0);
        $actor = ActorContext::user(
            auth()->id() !== null ? (int) auth()->id() : null,
            $siteId > 0 ? $siteId : null,
        );

        $result = app(ContentProjectCommandBus::class)->dispatch($command, $actor);
        app(ContentProjectActionResultNotifier::class)->send($result);

        return $result;
    }

    private function resolveWorkspaceModel(): SeoKeywordWorkspace
    {
        try {
            $id = KeywordIntelligencePublicRef::resolveWorkspaceIdStrict($this->workspaceRef);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        $workspace = SeoKeywordWorkspace::query()->find($id);
        if (! $workspace instanceof SeoKeywordWorkspace) {
            abort(404);
        }

        abort_unless(SeoAccessControl::canAccessSite((int) $workspace->site_id), 403);

        return $workspace;
    }

    private function loadOverview(): void
    {
        $workspace = $this->resolveWorkspaceModel();

        $this->workspace = [
            'workspace_ref' => (string) $workspace->public_ref,
            'site_id' => (int) $workspace->site_id,
            'name' => (string) $workspace->name,
            'description' => $workspace->description,
            'status' => $workspace->status instanceof \BackedEnum ? $workspace->status->value : (string) $workspace->status,
            'language' => $workspace->language,
            'country' => $workspace->country,
            'keyword_count' => (int) $workspace->keyword_count,
            'cluster_count' => (int) $workspace->cluster_count,
            'topic_count' => (int) $workspace->topic_count,
            'last_analyzed_at' => $workspace->last_analyzed_at?->toIso8601String(),
            'archived_at' => $workspace->archived_at?->toIso8601String(),
            'summary' => (array) ($workspace->summary ?? []),
            'is_archived' => $workspace->archived_at !== null,
        ];
    }

    private function loadKeywords(): void
    {
        $workspace = $this->resolveWorkspaceModel();

        $this->keywords = SeoKiKeyword::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('priority_score')
            ->limit(300)
            ->get()
            ->map(static fn (SeoKiKeyword $k): array => [
                'keyword_ref' => (string) $k->public_ref,
                'keyword' => (string) $k->keyword,
                'search_intent' => $k->search_intent instanceof \BackedEnum ? $k->search_intent->value : $k->search_intent,
                'funnel_stage' => $k->funnel_stage instanceof \BackedEnum ? $k->funnel_stage->value : $k->funnel_stage,
                'analysis_status' => $k->analysis_status instanceof \BackedEnum ? $k->analysis_status->value : (string) $k->analysis_status,
                'review_status' => $k->review_status instanceof \BackedEnum ? $k->review_status->value : (string) $k->review_status,
                'search_volume' => $k->search_volume,
                'priority_score' => $k->priority_score !== null ? (float) $k->priority_score : null,
                'is_primary' => (bool) $k->is_primary,
                'is_excluded' => (bool) $k->is_excluded,
                'cluster_ref' => $k->cluster_id !== null ? KeywordIntelligencePublicRef::cluster((int) $k->cluster_id) : null,
            ])
            ->all();
    }

    private function loadClusters(): void
    {
        $workspace = $this->resolveWorkspaceModel();

        $this->clusters = SeoKeywordCluster::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('priority_score')
            ->limit(300)
            ->get()
            ->map(static fn (SeoKeywordCluster $c): array => [
                'cluster_ref' => (string) $c->public_ref,
                'name' => (string) $c->name,
                'status' => $c->status instanceof \BackedEnum ? $c->status->value : (string) $c->status,
                'cluster_type' => $c->cluster_type instanceof \BackedEnum ? $c->cluster_type->value : $c->cluster_type,
                'search_intent' => $c->search_intent instanceof \BackedEnum ? $c->search_intent->value : $c->search_intent,
                'keyword_count' => (int) $c->keyword_count,
                'priority_score' => $c->priority_score !== null ? (float) $c->priority_score : null,
                'suggested_content_type' => (string) ($c->suggested_content_type ?: 'write_new'),
                'content_project_ref' => $c->content_project_ref,
            ])
            ->all();
    }

    private function loadTopicalMap(): void
    {
        $workspace = $this->resolveWorkspaceModel();

        $version = SeoTopicalMapVersion::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('version')
            ->first();

        $this->topicalMap = $version instanceof SeoTopicalMapVersion ? [
            'map_version_ref' => (string) $version->public_ref,
            'version' => (int) $version->version,
            'status' => (string) $version->status,
            'mode' => (string) ($version->mode ?? ''),
            'summary' => (array) ($version->summary ?? []),
            'snapshot' => (array) ($version->snapshot ?? []),
            'generated_at' => $version->generated_at?->toIso8601String(),
            'approved_at' => $version->approved_at?->toIso8601String(),
        ] : null;

        if (is_string($version?->mode) && $version->mode !== '') {
            $this->topicalMapMode = (string) $version->mode;
        }

        $this->topics = SeoKiTopic::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('depth')
            ->orderBy('id')
            ->limit(300)
            ->get()
            ->map(static fn (SeoKiTopic $t): array => [
                'topic_ref' => (string) $t->public_ref,
                'name' => (string) $t->name,
                'topic_type' => $t->topic_type instanceof \BackedEnum ? $t->topic_type->value : (string) $t->topic_type,
                'status' => $t->status instanceof \BackedEnum ? $t->status->value : (string) $t->status,
                'depth' => (int) $t->depth,
                'cluster_count' => (int) $t->cluster_count,
                'keyword_count' => (int) $t->keyword_count,
            ])
            ->all();
    }

    /**
     * @return list<string>
     */
    private function parseImportText(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

        return array_values(array_filter(array_map(static fn (string $line): string => trim($line), $lines), static fn (string $line): bool => $line !== ''));
    }
}
