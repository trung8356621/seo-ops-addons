<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Services\KeywordCannibalizationService;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

final class KeywordCannibalizationWorkspace extends Page
{
    use HasKeywordWorkspaceNavigation;

    protected static string $resource = KeywordResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.keywords.pages.keyword-cannibalization-workspace';

    protected static bool $shouldRegisterNavigation = false;

    private KeywordCannibalizationService $cannibalizationService;

    public function boot(KeywordCannibalizationService $cannibalizationService): void
    {
        $this->cannibalizationService = $cannibalizationService;
    }

    public function mount(): void
    {
        $this->initializeKeywordWorkspaceSiteFilter();
        $this->dispatchKeywordWorkspaceLanguageContext();
    }

    public static function canAccess(array $parameters = []): bool
    {
        return KeywordResource::canViewAny();
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.keyword.cannibalization_title');
    }

    public function onKeywordWorkspaceSiteFilterChanged(): void
    {
    }

    protected function getActiveKeywordWorkspaceKey(): string
    {
        return 'cannibalization';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCannibalizationRowsProperty(): array
    {
        $rows = $this->cannibalizationService->detect($this->resolveKeywordWorkspaceSiteId());

        return $this->filterCannibalizationRowsByLanguage($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function filterCannibalizationRowsByLanguage(array $rows): array
    {
        $variants = $this->resolveKeywordLanguageFilterVariants();
        if ($variants === null || $rows === []) {
            return $rows;
        }

        $articleIds = collect($rows)
            ->flatMap(static fn (array $row): array => collect($row['articles'] ?? [])
                ->map(static fn (array $article): int => (int) ($article['id'] ?? 0))
                ->all())
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($articleIds === []) {
            return [];
        }

        $allowedIds = SeoArticle::query()
            ->whereIn('id', $articleIds)
            ->whereIn('language', $variants)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $allowedLookup = array_fill_keys($allowedIds, true);

        return collect($rows)
            ->map(static function (array $row) use ($allowedLookup): ?array {
                $articles = array_values(array_filter(
                    $row['articles'] ?? [],
                    static fn (array $article): bool => isset($allowedLookup[(int) ($article['id'] ?? 0)]),
                ));

                if (count($articles) < 2) {
                    return null;
                }

                return [
                    'phrase' => (string) ($row['phrase'] ?? ''),
                    'article_count' => count($articles),
                    'articles' => $articles,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
