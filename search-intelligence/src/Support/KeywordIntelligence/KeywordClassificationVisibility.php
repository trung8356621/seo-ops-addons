<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordUiInventoryQuery;

final class KeywordClassificationVisibility
{
    public const UNCLASSIFIED = 'unclassified';

    /**
     * @return list<string>
     */
    public static function kinds(): array
    {
        return [
            KeywordRuleClassifier::KIND_KEYWORD_PHRASE,
            KeywordRuleClassifier::KIND_QUERY,
            KeywordRuleClassifier::KIND_SENTENCE,
            KeywordRuleClassifier::KIND_DESCRIPTIVE_PHRASE,
            KeywordRuleClassifier::KIND_BRAND_ENTITY,
            KeywordRuleClassifier::KIND_URL_DOMAIN,
            KeywordRuleClassifier::KIND_NOISE,
            self::UNCLASSIFIED,
        ];
    }

    public static function tableReady(): bool
    {
        return Schema::connection('omi_seo_ai')->hasTable('seo_keyword_classifications');
    }

    public static function hasSeoKeywordColumn(): bool
    {
        return self::tableReady()
            && Schema::connection('omi_seo_ai')->hasColumn('seo_keyword_classifications', 'is_seo_keyword');
    }

    public static function hasAmbiguousColumn(): bool
    {
        return self::tableReady()
            && Schema::connection('omi_seo_ai')->hasColumn('seo_keyword_classifications', 'is_ambiguous');
    }

    public static function resolveKind(?SeoKeywordClassification $row): string
    {
        $kind = trim((string) ($row?->phrase_kind ?? ''));

        return $kind !== '' ? $kind : self::UNCLASSIFIED;
    }

    public static function label(string $kind): string
    {
        return match ($kind) {
            KeywordRuleClassifier::KIND_KEYWORD_PHRASE => __('seo-content-ai::filament.keyword.classification_keyword_phrase'),
            KeywordRuleClassifier::KIND_QUERY => __('seo-content-ai::filament.keyword.classification_query'),
            KeywordRuleClassifier::KIND_SENTENCE => __('seo-content-ai::filament.keyword.classification_sentence'),
            KeywordRuleClassifier::KIND_DESCRIPTIVE_PHRASE => __('seo-content-ai::filament.keyword.classification_descriptive_phrase'),
            KeywordRuleClassifier::KIND_BRAND_ENTITY => __('seo-content-ai::filament.keyword.classification_brand_entity'),
            KeywordRuleClassifier::KIND_URL_DOMAIN => __('seo-content-ai::filament.keyword.classification_url_domain'),
            KeywordRuleClassifier::KIND_NOISE => __('seo-content-ai::filament.keyword.classification_noise'),
            default => __('seo-content-ai::filament.keyword.classification_unclassified'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function filterOptions(): array
    {
        $options = [];
        foreach (self::kinds() as $kind) {
            $options[$kind] = self::label($kind);
        }

        return $options;
    }

    public static function badgeColor(string $kind): string
    {
        return match ($kind) {
            KeywordRuleClassifier::KIND_KEYWORD_PHRASE => 'success',
            KeywordRuleClassifier::KIND_QUERY => 'primary',
            KeywordRuleClassifier::KIND_BRAND_ENTITY => 'info',
            KeywordRuleClassifier::KIND_DESCRIPTIVE_PHRASE, KeywordRuleClassifier::KIND_SENTENCE => 'warning',
            KeywordRuleClassifier::KIND_NOISE => 'danger',
            default => 'gray',
        };
    }

    public static function confidencePercent(?SeoKeywordClassification $row): ?int
    {
        if (! $row instanceof SeoKeywordClassification || $row->classification_confidence === null) {
            return null;
        }

        $raw = (float) $row->classification_confidence;
        if ($raw <= 1) {
            $raw *= 100;
        }

        return (int) round($raw);
    }

    public static function confidenceTooltip(?SeoKeywordClassification $row): ?string
    {
        if ($row instanceof SeoKeywordClassification && self::hasAmbiguousColumn() && (bool) $row->is_ambiguous) {
            return __('seo-content-ai::filament.keyword.classification_band_ambiguous');
        }

        $percent = self::confidencePercent($row);
        if ($percent === null) {
            return null;
        }

        return match (true) {
            $percent >= 90 => __('seo-content-ai::filament.keyword.classification_band_auto'),
            $percent >= 65 => __('seo-content-ai::filament.keyword.classification_band_review'),
            default => __('seo-content-ai::filament.keyword.classification_band_ambiguous'),
        };
    }

    public static function isSeoKeyword(?SeoKeywordClassification $row): bool
    {
        if (! $row instanceof SeoKeywordClassification || self::resolveKind($row) === self::UNCLASSIFIED) {
            return false;
        }

        if (self::hasSeoKeywordColumn() && $row->is_seo_keyword !== null) {
            return (bool) $row->is_seo_keyword;
        }

        return in_array(self::resolveKind($row), [
            KeywordRuleClassifier::KIND_KEYWORD_PHRASE,
            KeywordRuleClassifier::KIND_QUERY,
            KeywordRuleClassifier::KIND_BRAND_ENTITY,
        ], true);
    }

    public static function isAnchorCandidate(?SeoKeywordClassification $row): bool
    {
        if (! $row instanceof SeoKeywordClassification || self::resolveKind($row) === self::UNCLASSIFIED) {
            return false;
        }

        return (bool) ($row->is_anchor_candidate ?? false);
    }

    /**
     * @param  Builder<Keyword>  $query
     * @param  list<mixed>  $kinds
     * @return Builder<Keyword>
     */
    public static function applyKindFilter(Builder $query, array $kinds): Builder
    {
        if (! self::tableReady()) {
            return $query;
        }

        $selected = collect($kinds)
            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
            ->filter(static fn (string $value): bool => in_array($value, self::kinds(), true))
            ->values()
            ->all();

        if ($selected === []) {
            return $query;
        }

        $includeUnclassified = in_array(self::UNCLASSIFIED, $selected, true);
        $concrete = array_values(array_filter(
            $selected,
            static fn (string $kind): bool => $kind !== self::UNCLASSIFIED,
        ));

        return $query->where(function (Builder $outer) use ($includeUnclassified, $concrete): void {
            if ($includeUnclassified) {
                $outer->whereDoesntHave('seoClassification')
                    ->orWhereHas(
                        'seoClassification',
                        static fn (Builder $classification): Builder => $classification
                            ->where(function (Builder $empty): void {
                                $empty->whereNull('phrase_kind')->orWhere('phrase_kind', '');
                            }),
                    );
            }

            if ($concrete !== []) {
                $outer->orWhereHas(
                    'seoClassification',
                    static fn (Builder $classification): Builder => $classification->whereIn('phrase_kind', $concrete),
                );
            }
        });
    }

    /**
     * @param  Builder<Keyword>  $query
     * @return Builder<Keyword>
     */
    public static function applySeoUsableFilter(Builder $query, mixed $value): Builder
    {
        if (! self::tableReady() || $value === null || $value === '') {
            return $query;
        }

        $wanted = self::booleanFilterValue($value);
        $seoKinds = [
            KeywordRuleClassifier::KIND_KEYWORD_PHRASE,
            KeywordRuleClassifier::KIND_QUERY,
            KeywordRuleClassifier::KIND_BRAND_ENTITY,
        ];

        if ($wanted) {
            return $query->whereHas(
                'seoClassification',
                static function (Builder $classification) use ($seoKinds): void {
                    if (self::hasSeoKeywordColumn()) {
                        $classification->where('is_seo_keyword', true);
                    } else {
                        $classification->whereIn('phrase_kind', $seoKinds);
                    }
                },
            );
        }

        return $query->where(function (Builder $outer) use ($seoKinds): void {
            $outer->whereDoesntHave('seoClassification')
                ->orWhereHas(
                    'seoClassification',
                    static function (Builder $classification) use ($seoKinds): void {
                        if (self::hasSeoKeywordColumn()) {
                            $classification->where(function (Builder $flag): void {
                                $flag->where('is_seo_keyword', false)->orWhereNull('is_seo_keyword');
                            });
                        } else {
                            $classification->where(function (Builder $kind): void {
                                $kind->whereNotIn('phrase_kind', $seoKinds)->orWhereNull('phrase_kind');
                            });
                        }
                    },
                );
        });
    }

    /**
     * @param  Builder<Keyword>  $query
     * @return Builder<Keyword>
     */
    public static function applyAnchorCandidateFilter(Builder $query, mixed $value): Builder
    {
        return self::applyBooleanClassificationFilter($query, 'is_anchor_candidate', $value);
    }

    /**
     * @param  Builder<Keyword>  $query
     * @return Builder<Keyword>
     */
    public static function applyBooleanClassificationFilter(Builder $query, string $column, mixed $value): Builder
    {
        if (! self::tableReady() || $value === null || $value === '') {
            return $query;
        }

        $wanted = self::booleanFilterValue($value);

        if ($wanted) {
            return $query->whereHas(
                'seoClassification',
                static function (Builder $classification) use ($column): void {
                    $classification->where($column, true);
                },
            );
        }

        return $query->where(function (Builder $outer) use ($column): void {
            $outer->whereDoesntHave('seoClassification')
                ->orWhereHas(
                    'seoClassification',
                    static fn (Builder $classification): Builder => $classification
                        ->where($column, false)
                        ->orWhereNull($column),
                );
        });
    }

    private static function booleanFilterValue(mixed $value): bool
    {
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? ((string) $value === '1');
    }

    /**
     * @param  Builder<Keyword>  $query
     * @return Builder<Keyword>
     */
    public static function applyReviewStateFilter(Builder $query, mixed $value): Builder
    {
        if (! self::tableReady() || ! is_string($value) || $value === '') {
            return $query;
        }

        return match ($value) {
            'auto' => $query->whereHas(
                'seoClassification',
                static function (Builder $classification): void {
                    $classification->where('classification_confidence', '>=', 0.90);
                    if (self::hasAmbiguousColumn()) {
                        $classification->where(function (Builder $ambiguous): void {
                            $ambiguous->where('is_ambiguous', false)->orWhereNull('is_ambiguous');
                        });
                    }
                },
            ),
            'review' => $query->whereHas(
                'seoClassification',
                static fn (Builder $classification): Builder => $classification
                    ->where('classification_confidence', '>=', 0.65)
                    ->where('classification_confidence', '<', 0.90),
            ),
            'ambiguous' => $query->whereHas(
                'seoClassification',
                static function (Builder $classification): void {
                    if (self::hasAmbiguousColumn()) {
                        $classification->where('is_ambiguous', true);
                    } else {
                        $classification->where('classification_confidence', '<', 0.65);
                    }
                },
            ),
            default => $query,
        };
    }

    /**
     * @param  list<string>|null  $languageVariants
     * @return array{
     *     table_ready: bool,
     *     total_raw: int,
     *     classified: int,
     *     unclassified: int,
     *     seo_usable: int,
     *     excluded: int,
     *     focus: int,
     *     error: int,
     *     seo_excluded: int,
     *     kinds: array<string, int>
     * }
     */
    public static function summarize(?int $siteId, ?array $languageVariants = null): array
    {
        $ids = app(KeywordUiInventoryQuery::class)->keywordIds($siteId, $languageVariants);

        return self::summarizeForKeywordIds($ids);
    }

    /**
     * User-facing classification strip for the current Dictionary filtered set.
     *
     * @param  list<int>  $keywordIds
     * @return array{
     *     table_ready: bool,
     *     total_raw: int,
     *     classified: int,
     *     unclassified: int,
     *     seo_usable: int,
     *     excluded: int,
     *     focus: int,
     *     error: int,
     *     seo_excluded: int,
     *     kinds: array<string, int>
     * }
     */
    public static function summarizeForKeywordIds(array $keywordIds): array
    {
        $emptyKinds = [
            KeywordRuleClassifier::KIND_KEYWORD_PHRASE => 0,
            KeywordRuleClassifier::KIND_QUERY => 0,
            KeywordRuleClassifier::KIND_BRAND_ENTITY => 0,
            KeywordRuleClassifier::KIND_DESCRIPTIVE_PHRASE => 0,
            KeywordRuleClassifier::KIND_SENTENCE => 0,
            KeywordRuleClassifier::KIND_URL_DOMAIN => 0,
            KeywordRuleClassifier::KIND_NOISE => 0,
        ];

        if (! self::tableReady()) {
            return [
                'table_ready' => false,
                'total_raw' => 0,
                'classified' => 0,
                'unclassified' => 0,
                'seo_usable' => 0,
                'excluded' => 0,
                'focus' => 0,
                'error' => 0,
                'seo_excluded' => 0,
                'kinds' => $emptyKinds,
            ];
        }

        $ids = array_values(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $keywordIds),
            static fn (int $id): bool => $id > 0,
        ));
        $total = count($ids);
        if ($total === 0) {
            return [
                'table_ready' => true,
                'total_raw' => 0,
                'classified' => 0,
                'unclassified' => 0,
                'seo_usable' => 0,
                'excluded' => 0,
                'focus' => 0,
                'error' => 0,
                'seo_excluded' => 0,
                'kinds' => $emptyKinds,
            ];
        }

        $classQuery = SeoKeywordClassification::query()->whereIn('keyword_id', $ids);
        $classColumns = ['keyword_id', 'phrase_kind', 'is_seo_keyword'];
        if (self::hasAmbiguousColumn()) {
            $classColumns[] = 'is_ambiguous';
        }
        $classifiedRows = (clone $classQuery)
            ->whereNotNull('phrase_kind')
            ->where('phrase_kind', '!=', '')
            ->get($classColumns);

        $kinds = $emptyKinds;
        $seoUsable = 0;
        foreach ($classifiedRows as $row) {
            $kind = self::resolveKind($row);
            if (isset($kinds[$kind])) {
                $kinds[$kind]++;
            }
            if (self::isSeoKeyword($row)) {
                $seoUsable++;
            }
        }

        $classified = $classifiedRows->count();
        $unclassified = max(0, $total - $classified);
        $seoExcludedManual = Keyword::query()
            ->whereIn('id', $ids)
            ->whereHas(
                'metas',
                static function (Builder $meta): void {
                    $meta
                        ->where('meta_key', KeywordMetaKey::SeoHidden->value)
                        ->where('meta_value', '1');
                },
            )
            ->count();

        // Focus chip = keywords with main article / provider focus relation — not unclassified default.
        $focusWithMainArticle = Keyword::query()
            ->whereIn('id', $ids)
            ->whereHas('mainArticles')
            ->count();

        return [
            'table_ready' => true,
            'total_raw' => $total,
            'classified' => $classified,
            'unclassified' => $unclassified,
            'seo_usable' => $seoUsable,
            'excluded' => max(0, $classified - $seoUsable),
            'focus' => $focusWithMainArticle,
            'error' => Keyword::query()
                ->whereIn('id', $ids)
                ->whereIn('review_status', ['danger', 'warning'])
                ->count(),
            // Manual "Exclude from SEO" (seo_hidden meta) — not classifier non-SEO kinds.
            'seo_excluded' => $seoExcludedManual,
            'kinds' => $kinds,
        ];
    }
}
