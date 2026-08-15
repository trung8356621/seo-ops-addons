<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Concerns;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Services\AiKeywordDiscoveryService;
use Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\Seo\Support\CtaKeywordBlacklistFilter;
use Omnichannel\Addons\SearchFoundation\Support\InternalAnchorKeywordFilter;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Notifications\Notification;
use Livewire\Attributes\Url;

trait InteractsWithAiKeywordDiscovery
{
    #[Url(as: 'seed')]
    public string $seedKeyword = '';

    #[Url(as: 'intent')]
    public string $searchIntent = 'any';

    #[Url(as: 'region')]
    public string $targetRegion = 'vietnam';

    /** @var list<array<string, mixed>> */
    public array $suggestions = [];

    /** @var list<string> */
    public array $selectedSuggestionIds = [];

    private AiKeywordDiscoveryService $discovery;

    private KeywordPersistenceService $keywordPersistence;

    private CreateArticlesFromTaskService $articleCreator;

    protected function bootAiKeywordDiscovery(
        AiKeywordDiscoveryService $discovery,
        KeywordPersistenceService $keywordPersistence,
        CreateArticlesFromTaskService $articleCreator,
    ): void {
        $this->discovery = $discovery;
        $this->keywordPersistence = $keywordPersistence;
        $this->articleCreator = $articleCreator;
    }

    protected function mountAiKeywordDiscoveryFilters(): void
    {
        if (! in_array($this->searchIntent, ['any', 'informational', 'commercial', 'transactional'], true)) {
            $this->searchIntent = 'any';
        }

        if (! in_array($this->targetRegion, ['vietnam', 'global', 'us', 'uk', 'sea'], true)) {
            $this->targetRegion = 'vietnam';
        }
    }

    public function generateAiKeywords(): void
    {
        $this->selectedSuggestionIds = [];

        try {
            $siteId = (int) ($this->resolveAiDiscoverySiteId() ?? 0);
            $context = '';
            $existing = [];
            if ($siteId > 0) {
                $classification = app(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClassificationService::class);
                $builder = app(\Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordGenerationContextBuilder::class);
                $landscape = $classification->landscape($siteId);
                $packed = $builder->build($landscape, ['site' => (string) $siteId, 'max_topics' => 50, 'max_exclusions' => 150]);
                $context = $builder->toPromptBlock($packed);
                foreach ($classification->classificationRows($siteId) as $row) {
                    if (! ($row['is_seo_keyword'] ?? false)) {
                        continue;
                    }
                    $existing[] = [
                        'normalized_text' => (string) ($row['normalized_text'] ?? ''),
                        'folded_text' => (string) ($row['folded_text'] ?? ''),
                        'cluster_key' => (string) ($row['cluster_key'] ?? ''),
                        'seo_intent' => (string) ($row['seo_intent'] ?? ''),
                    ];
                }
            }

            $this->suggestions = $this->discovery->discover(
                $this->seedKeyword,
                $this->searchIntent,
                $this->targetRegion,
                $context,
                20,
            );

            if ($existing !== []) {
                $guard = new \Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordAiCandidateGuard();
                $phrases = array_map(static fn (array $row): string => (string) ($row['keyword'] ?? ''), $this->suggestions);
                $evaluated = $guard->evaluate($phrases, $existing);
                $kept = [];
                foreach ($this->suggestions as $i => $item) {
                    $decision = $evaluated[$i]['decision'] ?? 'accept';
                    if ($decision !== 'accept') {
                        continue;
                    }
                    $item['cluster_key'] = $evaluated[$i]['cluster_key'] ?? null;
                    $kept[] = $item;
                }
                $this->suggestions = $kept;
            }
        } catch (\InvalidArgumentException|\Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.discovery_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.discovery_success', ['count' => count($this->suggestions)]))
            ->success()
            ->send();
    }

    public function toggleSuggestion(string $suggestionId): void
    {
        if ($suggestionId === '') {
            return;
        }

        if (in_array($suggestionId, $this->selectedSuggestionIds, true)) {
            $this->selectedSuggestionIds = array_values(array_filter(
                $this->selectedSuggestionIds,
                static fn (string $id): bool => $id !== $suggestionId,
            ));

            return;
        }

        $this->selectedSuggestionIds[] = $suggestionId;
    }

    public function toggleSelectAllSuggestions(): void
    {
        $allIds = collect($this->suggestions)
            ->pluck('id')
            ->filter(static fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();

        if ($allIds !== [] && count($this->selectedSuggestionIds) === count($allIds)) {
            $this->selectedSuggestionIds = [];

            return;
        }

        $this->selectedSuggestionIds = $allIds;
    }

    public function toggleSelectAll(): void
    {
        $this->toggleSelectAllSuggestions();
    }

    public function isAllSuggestionsSelected(): bool
    {
        $total = count($this->suggestions);

        return $total > 0 && count($this->selectedSuggestionIds) === $total;
    }

    public function isAllSelected(): bool
    {
        return $this->isAllSuggestionsSelected();
    }

    public function getSelectedSuggestionCount(): int
    {
        return count($this->selectedSuggestionIds);
    }

    public function batchImportSuggestions(): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return;
        }

        $siteId = (int) ($this->resolveAiDiscoverySiteId() ?? 0);
        if ($siteId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.discovery_no_domain'))
                ->warning()
                ->send();

            return;
        }

        $selected = $this->resolveSelectedSuggestions();
        if ($selected === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.discovery_none_selected'))
                ->warning()
                ->send();

            return;
        }

        $created = 0;
        $skipped = 0;

        foreach ($selected as $item) {
            $phrase = Keyword::decodePhrase((string) ($item['keyword'] ?? ''));
            if ($phrase === '' || ! InternalAnchorKeywordFilter::isUsableAnchorPhrase($phrase)) {
                $skipped++;

                continue;
            }

            if (app(CtaKeywordBlacklistFilter::class)->isBlocked($phrase)) {
                $skipped++;

                continue;
            }

            $metrics = [
                'discovery_intent' => (string) ($item['intent'] ?? ''),
                'discovery_difficulty' => (string) ($item['difficulty'] ?? ''),
                'discovery_title_idea' => (string) ($item['title_idea'] ?? ''),
                'discovery_reason' => (string) ($item['relevancy_reason'] ?? ''),
                'discovery_seed' => trim($this->seedKeyword),
                'discovery_region' => $this->targetRegion,
            ];

            $existing = Keyword::query()
                ->whereRaw('phrase COLLATE utf8mb4_unicode_ci = ?', [$phrase])
                ->exists();

            $this->keywordPersistence->upsert(
                phrase: $phrase,
                type: Keyword::TYPE_SUGGEST,
                siteId: $siteId,
                metrics: $metrics,
            );

            if (! $existing) {
                $created++;
            }
        }

        if ($created > 0) {
            app(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordIntelligenceScheduler::class)
                ->onImportBatch($siteId, $created);
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.discovery_import_success', ['count' => $created]))
            ->body($skipped > 0
                ? __('seo-content-ai::filament.keyword.discovery_import_skipped', ['count' => $skipped])
                : null)
            ->success()
            ->send();
    }

    public function batchImport(): void
    {
        $this->batchImportSuggestions();
    }

    public function createDraftArticles(): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return;
        }

        $siteId = (int) ($this->resolveAiDiscoverySiteId() ?? 0);
        if ($siteId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.discovery_no_domain'))
                ->warning()
                ->send();

            return;
        }

        $selected = $this->resolveSelectedSuggestions();
        if ($selected === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.discovery_none_selected'))
                ->warning()
                ->send();

            return;
        }

        $keywordsRaw = collect($selected)
            ->pluck('keyword')
            ->filter(static fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->implode("\n");

        try {
            $result = $this->articleCreator->runFromKeywordsForSite($keywordsRaw, $siteId);
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.discovery_draft_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.discovery_draft_success', [
                'created' => (int) ($result['created'] ?? 0),
                'failed' => (int) ($result['failed'] ?? 0),
            ]))
            ->success()
            ->send();
    }

    public function copyKeyword(string $suggestionId): void
    {
        $keyword = collect($this->suggestions)
            ->first(static fn (array $item): bool => ($item['id'] ?? '') === $suggestionId);

        if (! is_array($keyword)) {
            return;
        }

        $phrase = trim((string) ($keyword['keyword'] ?? ''));
        if ($phrase === '') {
            return;
        }

        $this->dispatch('discovery-copy-keyword', phrase: $phrase);

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.discovery_copied'))
            ->success()
            ->send();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function resolveSelectedSuggestions(): array
    {
        if ($this->selectedSuggestionIds === []) {
            return [];
        }

        return collect($this->suggestions)
            ->filter(fn (array $item): bool => in_array((string) ($item['id'] ?? ''), $this->selectedSuggestionIds, true))
            ->values()
            ->all();
    }

    abstract protected function resolveAiDiscoverySiteId(): ?int;
}
