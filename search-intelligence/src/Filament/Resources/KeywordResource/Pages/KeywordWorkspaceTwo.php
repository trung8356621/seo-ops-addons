<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Services\TopicClusterBuilderService;
use Omnichannel\Addons\SearchIntelligence\Services\TopicClusterMapService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

final class KeywordWorkspaceTwo extends Page
{
    use HasKeywordWorkspaceNavigation;

    protected static string $resource = KeywordResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.keywords.pages.topic-cluster-workspace';

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'kw')]
    public ?int $selectedKeywordId = null;

    public bool $clusterModalOpen = false;

    public bool $isScanningSuggestions = false;

    /** @var list<array{id: int, phrase: string}> */
    public array $clusterDraft = [];

    public string $modalSearchQuery = '';

    public string $newPillarPhrase = '';

    public bool $showNewPillarInput = false;

    private TopicClusterMapService $clusterMap;

    private TopicClusterBuilderService $clusterBuilder;

    public function boot(
        TopicClusterMapService $clusterMap,
        TopicClusterBuilderService $clusterBuilder,
    ): void {
        $this->clusterMap = $clusterMap;
        $this->clusterBuilder = $clusterBuilder;
    }

    public function mount(): void
    {
        $this->initializeKeywordWorkspaceSiteFilter();
        $this->redirect($this->appendKeywordWorkspaceSiteToUrl(KeywordResource::getUrl('clusters')));
    }

    public static function canAccess(array $parameters = []): bool
    {
        return KeywordResource::canViewAny();
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.keyword.workspace_two_title');
    }

    protected function getActiveKeywordWorkspaceKey(): string
    {
        return 'workspace-2';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getPillarList(): array
    {
        return $this->clusterMap->buildPillarList($this->resolveKeywordWorkspaceSiteId());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getDomainCluster(): array
    {
        if ($this->selectedKeywordId === null || $this->selectedKeywordId <= 0) {
            return [];
        }

        return $this->clusterMap->buildDomainCluster($this->selectedKeywordId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSelectedPillarSummary(): ?array
    {
        if ($this->selectedKeywordId === null || $this->selectedKeywordId <= 0) {
            return null;
        }

        $keyword = Keyword::query()->find($this->selectedKeywordId);
        if (! $keyword instanceof Keyword || $keyword->parent_id !== null) {
            return null;
        }

        return [
            'id' => (int) $keyword->id,
            'phrase' => (string) $keyword->phrase,
        ];
    }

    /**
     * @return list<array{id: int, phrase: string}>
     */
    public function getModalSearchResults(): array
    {
        if ($this->selectedKeywordId === null || $this->selectedKeywordId <= 0) {
            return [];
        }

        $draftIds = collect($this->clusterDraft)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return collect($this->clusterBuilder->searchAttachableKeywords(
            $this->selectedKeywordId,
            $this->modalSearchQuery,
            $this->resolveKeywordWorkspaceSiteId(),
        ))
            ->reject(static fn (array $item): bool => in_array((int) $item['id'], $draftIds, true))
            ->values()
            ->all();
    }

    public function toggleNewPillarInput(): void
    {
        $this->showNewPillarInput = ! $this->showNewPillarInput;
        if (! $this->showNewPillarInput) {
            $this->newPillarPhrase = '';
        }
    }

    public function createNewPillar(): void
    {
        if (! $this->guardMutation()) {
            return;
        }

        $phrase = trim($this->newPillarPhrase);
        if ($phrase === '') {
            $this->notifyError(__('seo-content-ai::filament.keyword.cluster_pillar_phrase_required'));

            return;
        }

        try {
            $keyword = $this->clusterBuilder->createPillar($phrase, $this->resolveKeywordWorkspaceSiteId());
        } catch (\InvalidArgumentException $exception) {
            $this->notifyError($exception->getMessage());

            return;
        }

        $this->newPillarPhrase = '';
        $this->showNewPillarInput = false;

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.cluster_create_pillar_success'))
            ->success()
            ->send();

        $this->openClusterBuilder((int) $keyword->id);
    }

    public function openClusterBuilder(int $pillarId): void
    {
        if ($pillarId <= 0) {
            return;
        }

        $pillar = Keyword::query()->find($pillarId);
        if (! $pillar instanceof Keyword || $pillar->parent_id !== null) {
            return;
        }

        $this->selectedKeywordId = $pillarId;
        $this->clusterModalOpen = true;
        $this->isScanningSuggestions = true;
        $this->clusterDraft = [];
        $this->modalSearchQuery = '';
    }

    public function loadReverseSuggestions(): void
    {
        if (! $this->clusterModalOpen || $this->selectedKeywordId === null || $this->selectedKeywordId <= 0) {
            $this->isScanningSuggestions = false;

            return;
        }

        $this->clusterDraft = $this->clusterBuilder->reverseScanSuggestions(
            $this->selectedKeywordId,
            $this->resolveKeywordWorkspaceSiteId(),
        );
        $this->isScanningSuggestions = false;
    }

    public function closeClusterModal(): void
    {
        $this->clusterModalOpen = false;
        $this->isScanningSuggestions = false;
        $this->modalSearchQuery = '';
    }

    public function addKeywordToDraft(int $keywordId): void
    {
        if ($keywordId <= 0 || $this->selectedKeywordId === null) {
            return;
        }

        if (collect($this->clusterDraft)->contains(static fn (array $item): bool => (int) $item['id'] === $keywordId)) {
            return;
        }

        $keyword = Keyword::query()->find($keywordId);
        if (! $keyword instanceof Keyword) {
            return;
        }

        $this->clusterDraft[] = [
            'id' => (int) $keyword->id,
            'phrase' => (string) $keyword->phrase,
        ];

        $this->modalSearchQuery = '';
    }

    public function saveClusterRelationships(): void
    {
        if (! $this->guardMutation()) {
            return;
        }

        if ($this->selectedKeywordId === null || $this->selectedKeywordId <= 0) {
            return;
        }

        $childIds = collect($this->clusterDraft)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        try {
            $result = $this->clusterBuilder->saveClusterRelationships($this->selectedKeywordId, $childIds);
        } catch (\InvalidArgumentException $exception) {
            $this->notifyError($exception->getMessage());

            return;
        }

        $this->clusterModalOpen = false;
        $this->isScanningSuggestions = false;
        $this->modalSearchQuery = '';

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.cluster_save_relationships_success'))
            ->body(__('seo-content-ai::filament.keyword.cluster_save_relationships_body', [
                'attached' => $result['attached'],
                'detached' => $result['detached'],
            ]))
            ->success()
            ->send();
    }

    public function createPillarDraft(int $siteId, int $keywordId): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return;
        }

        if ($siteId <= 0 || $keywordId <= 0) {
            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.cluster_create_draft_queued'))
            ->body(__('seo-content-ai::filament.keyword.cluster_create_draft_queued_body'))
            ->info()
            ->send();
    }

    private function guardMutation(): bool
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return false;
        }

        return true;
    }

    private function notifyError(string $message): void
    {
        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.cluster_action_failed'))
            ->body($message)
            ->danger()
            ->send();
    }
}
