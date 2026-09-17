<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSiteKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\HideKeywordFromSeoService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordRuleClassifier;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordSourceNormalizer;

/**
 * Ensure / refresh seo_site_keywords for one site (classification ≠ membership).
 */
final class TopicSiteKeywordService
{
    public function __construct(
        private readonly KeywordNormalizer $normalizer,
        private readonly KeywordRuleClassifier $classifier,
        private readonly KeywordSourceNormalizer $sources,
    ) {}

    public static function tablesReady(): bool
    {
        return Schema::connection('omi_seo_ai')->hasTable('seo_site_keywords');
    }

    /**
     * Classify all dictionary keywords that have site context.
     *
     * @return array{ensured: int, seo_keywords: int}
     */
    public function ensureForSite(int $siteId): array
    {
        if ($siteId <= 0 || ! self::tablesReady()) {
            return ['ensured' => 0, 'seo_keywords' => 0];
        }

        $ensured = 0;
        $seoKeywords = 0;

        Keyword::query()
            ->forSite($siteId)
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use ($siteId, &$ensured, &$seoKeywords): void {
                foreach ($chunk as $keyword) {
                    if (! $keyword instanceof Keyword) {
                        continue;
                    }
                    $row = $this->upsertClassification($siteId, $keyword);
                    $ensured++;
                    if ($row->is_seo_keyword) {
                        $seoKeywords++;
                    }
                }
            });

        return ['ensured' => $ensured, 'seo_keywords' => $seoKeywords];
    }

    public function upsertClassification(int $siteId, Keyword $keyword, ?string $source = null): SeoSiteKeyword
    {
        $raw = (string) $keyword->phrase;
        $norm = $this->normalizer->normalize($raw);
        $sourceKind = $this->sources->normalize(
            is_string($keyword->source ?? null) ? (string) $keyword->source : null,
        );
        $classified = $this->classifier->classify($raw, $norm['normalized_text'], [
            'source_kind' => $sourceKind,
            'occurrence_count' => max(1, (int) ($keyword->link_maps_count ?? 1)),
        ]);

        $payload = [
            'phrase_kind' => $classified['phrase_kind'],
            'seo_intent' => $classified['seo_intent'],
            'is_seo_keyword' => (bool) $classified['is_seo_keyword'],
            'is_anchor_candidate' => (bool) $classified['is_anchor_candidate'],
            'is_ambiguous' => (bool) $classified['is_ambiguous'],
            'keyword_score' => $classified['keyword_score'] ?? null,
            'confidence' => $classified['classification_confidence'] ?? null,
            'review_state' => $classified['review_band'] ?? null,
            'source' => $source ?? $sourceKind,
        ];

        /** @var SeoSiteKeyword $row */
        $row = SeoSiteKeyword::query()->updateOrCreate(
            ['site_id' => $siteId, 'keyword_id' => (int) $keyword->id],
            $payload,
        );

        return $row;
    }

    /**
     * @return list<array{keyword_id: int, phrase: string, is_seo_keyword: bool}>
     */
    public function loadEligibleSeoKeywords(int $siteId): array
    {
        if ($siteId <= 0 || ! self::tablesReady()) {
            return [];
        }

        $rows = SeoSiteKeyword::query()
            ->where('site_id', $siteId)
            ->where('is_seo_keyword', true)
            ->orderBy('keyword_id')
            ->get(['keyword_id']);

        if ($rows->isEmpty()) {
            return [];
        }

        $ids = $rows->pluck('keyword_id')->map(static fn ($id): int => (int) $id)->all();
        $phrases = Keyword::query()
            ->whereIn('id', $ids)
            ->pluck('phrase', 'id');

        $out = [];
        foreach ($ids as $keywordId) {
            $phrase = trim((string) ($phrases[$keywordId] ?? ''));
            if ($phrase === '') {
                continue;
            }
            $out[] = [
                'keyword_id' => $keywordId,
                'phrase' => $phrase,
                'is_seo_keyword' => true,
            ];
        }

        return $out;
    }

    /**
     * Broad Topic membership discovery pool: existing current-site Dictionary keywords.
     *
     * Does not invent Keywords. Does not gate on is_seo_keyword=true.
     * Excludes hard SEO-hidden rows only.
     *
     * @return list<array{keyword_id: int, phrase: string, is_seo_keyword: bool}>
     */
    public function loadTopicCandidateKeywords(int $siteId): array
    {
        if ($siteId <= 0) {
            return [];
        }

        $hide = app(HideKeywordFromSeoService::class);
        /** @var array<int, bool> $seoByKeyword */
        $seoByKeyword = [];
        if (self::tablesReady()) {
            $seoByKeyword = SeoSiteKeyword::query()
                ->where('site_id', $siteId)
                ->get(['keyword_id', 'is_seo_keyword'])
                ->mapWithKeys(static fn (SeoSiteKeyword $row): array => [
                    (int) $row->keyword_id => (bool) $row->is_seo_keyword,
                ])
                ->all();
        }

        $out = [];
        Keyword::query()
            ->forSite($siteId)
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use ($hide, $seoByKeyword, &$out): void {
                foreach ($chunk as $keyword) {
                    if (! $keyword instanceof Keyword) {
                        continue;
                    }
                    $keywordId = (int) $keyword->id;
                    if ($keywordId <= 0 || $hide->isHidden($keywordId)) {
                        continue;
                    }
                    $phrase = trim((string) $keyword->phrase);
                    if ($phrase === '') {
                        continue;
                    }
                    $out[] = [
                        'keyword_id' => $keywordId,
                        'phrase' => $phrase,
                        'is_seo_keyword' => (bool) ($seoByKeyword[$keywordId] ?? false),
                    ];
                }
            });

        return $out;
    }
}
