<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Services\AiKeywordDiscoveryService;
use Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\Seo\Support\CtaKeywordBlacklistFilter;
use Omnichannel\Addons\SearchFoundation\Support\InternalAnchorKeywordFilter;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

final class KeywordWorkspaceThree extends Page
{
    use HasKeywordWorkspaceNavigation;

    protected static string $resource = KeywordResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.keywords.pages.ai-keyword-discovery-workspace';

    protected static bool $shouldRegisterNavigation = false;

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

    public function boot(
        AiKeywordDiscoveryService $discovery,
        KeywordPersistenceService $keywordPersistence,
        CreateArticlesFromTaskService $articleCreator,
    ): void {
        $this->discovery = $discovery;
        $this->keywordPersistence = $keywordPersistence;
        $this->articleCreator = $articleCreator;
    }

    public function mount(): void
    {
        $this->initializeKeywordWorkspaceSiteFilter();

        if (! in_array($this->searchIntent, ['any', 'informational', 'commercial', 'transactional'], true)) {
            $this->searchIntent = 'any';
        }

        if (! in_array($this->targetRegion, ['vietnam', 'global', 'us', 'uk', 'sea'], true)) {
            $this->targetRegion = 'vietnam';
        }
    }

    public static function canAccess(array $parameters = []): bool
    {
        return KeywordResource::canViewAny();
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.keyword.workspace_three_title');
    }

    protected function getActiveKeywordWorkspaceKey(): string
    {
        return 'workspace-3';
    }

    public function generate(): void
    {
        $this->selectedSuggestionIds = [];

        try {
            $this->suggestions = $this->discovery->discover(
                $this->seedKeyword,
                $this->searchIntent,
                $this->targetRegion,
            );
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

    public function toggleSelectAll(): void
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

    public function batchImport(): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return;
        }

        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
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

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.discovery_import_success', ['count' => $created]))
            ->body($skipped > 0
                ? __('seo-content-ai::filament.keyword.discovery_import_skipped', ['count' => $skipped])
                : null)
            ->success()
            ->send();
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

        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
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

    public function getSelectedCount(): int
    {
        return count($this->selectedSuggestionIds);
    }

    public function isAllSelected(): bool
    {
        $total = count($this->suggestions);

        return $total > 0 && count($this->selectedSuggestionIds) === $total;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveSelectedSuggestions(): array
    {
        if ($this->selectedSuggestionIds === []) {
            return [];
        }

        return collect($this->suggestions)
            ->filter(fn (array $item): bool => in_array((string) ($item['id'] ?? ''), $this->selectedSuggestionIds, true))
            ->values()
            ->all();
    }
}
