<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupKeyword;
use Omnichannel\Addons\Seo\Services\Linking\InternalLinkV2Guard;
use Throwable;

/**
 * Active Internal Link engine. Legacy matching stays on disk and is not called.
 */
final class InternalLinkV2Suggester
{
    /** @var list<string> */
    private const GENERIC_ANCHORS = [
        'doanh nghiệp',
        'chất liệu',
        'liên hệ ngay',
        'sản phẩm',
        'khách hàng',
        'liên hệ',
        'xem thêm',
        'tại đây',
        'công ty',
        'dịch vụ',
    ];

    /** @var list<string> */
    private const STOP_TOKENS = [
        'và', 'của', 'cho', 'với', 'các', 'những', 'một', 'này', 'trong', 'để', 'là', 'có',
        'được', 'khi', 'từ', 'theo', 'về', 'trên', 'dưới', 'ngay', 'hơn', 'rất', 'the', 'and', 'for',
    ];

    public function __construct(private readonly InternalLinkV2Guard $guard = new InternalLinkV2Guard()) {}

    /**
     * @param  list<array<string, mixed>>  $existingInternal
     * @return array{status: string, suggestions: list<array<string, mixed>>, metrics: array<string, mixed>, reason?: string, rejected?: list<array<string, mixed>>}
     */
    public function suggest(SeoArticle $article, string $content, array $existingInternal = []): array
    {
        if (! (bool) config('semantic.enabled', false)) {
            return ['status' => 'unavailable', 'suggestions' => [], 'metrics' => [], 'reason' => 'semantic_unavailable'];
        }
        $plain = trim(strip_tags($content));
        if ($plain === '') {
            return ['status' => 'empty', 'suggestions' => [], 'metrics' => [], 'reason' => 'content_unavailable'];
        }
        $siteId = (int) ($article->site_id ?? 0);
        if ($siteId <= 0) {
            return ['status' => 'empty', 'suggestions' => [], 'metrics' => [], 'reason' => 'site_unresolved'];
        }
        $maxInternal = max(1, (int) config('seo-content-ai.link_suggestions.max_internal_links', 10));
        if ($this->insertedLinkCount($existingInternal) >= $maxInternal) {
            return ['status' => 'empty', 'suggestions' => [], 'metrics' => [], 'reason' => 'link_quota'];
        }

        $built = $this->candidates($article, $siteId, $plain, $existingInternal);
        $filtered = $this->guard->filter('article:'.$article->id, $built['rows']);
        $stages = $built['stages'];
        $stages['guard_accepted'] = count($filtered['accepted']);
        $stages['guard_rejected'] = count($filtered['rejected']);
        if ($filtered['accepted'] === []) {
            return [
                'status' => 'empty',
                'suggestions' => [],
                'metrics' => ['stages' => $stages],
                'reason' => $stages['topic_groups'] === 0 ? 'no_topic_group' : 'no_eligible_target',
                'rejected' => $filtered['rejected'],
            ];
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('semantic.url'), '/'))
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('semantic.timeout', 30))
                ->post('/v1/internal-links/v2/rank', [
                    'source_ref' => 'article:'.$article->id,
                    'source_text' => mb_substr($plain, 0, 4000),
                    'candidate_boundary' => 'topic_group',
                    'candidates' => array_map(static function (array $row): array {
                        return [
                            'ref' => $row['ref'],
                            'topic_group_ref' => $row['topic_group_ref'],
                            'url' => $row['url'],
                            'eligible' => true,
                            'inbound_count' => (int) $row['inbound_count'],
                            'outbound_count' => (int) ($row['outbound_count'] ?? 0),
                            'representation' => $row['representation'],
                            'same_as_source' => false,
                            'already_linked_from_source' => false,
                        ];
                    }, $filtered['accepted']),
                ]);
        } catch (Throwable $e) {
            return [
                'status' => 'unavailable',
                'suggestions' => [],
                'metrics' => ['stages' => $stages],
                'reason' => 'semantic_unavailable',
                'error' => $e->getMessage(),
            ];
        }

        if (! $response->successful()) {
            return [
                'status' => 'unavailable',
                'suggestions' => [],
                'metrics' => ['stages' => $stages],
                'reason' => 'semantic_unavailable',
            ];
        }

        $body = $response->json();
        $byRef = [];
        foreach ($filtered['accepted'] as $row) {
            $byRef[$row['ref']] = $row;
        }
        $suggestions = [];
        $usedAnchors = [];
        $usedUrls = [];
        $anchorRejected = 0;
        foreach ((array) ($body['suggestions'] ?? []) as $ranked) {
            if (! is_array($ranked)) {
                continue;
            }
            $local = $byRef[(string) ($ranked['ref'] ?? '')] ?? null;
            if ($local === null) {
                continue;
            }
            $url = (string) $local['url'];
            if (isset($usedUrls[$url])) {
                $anchorRejected++;
                continue;
            }
            $anchor = $this->chooseAnchor($plain, (string) $local['title'], (string) $local['phrase'], $usedAnchors);
            if ($anchor === null) {
                $anchorRejected++;
                continue;
            }
            $usedAnchors[] = $anchor;
            $usedUrls[$url] = true;
            $suggestions[] = [
                'text' => $anchor,
                'keyword_id' => $local['keyword_id'],
                'href' => $url,
                'target_url' => $url,
                'target_article_id' => $local['article_id'],
                'destination_resolved' => true,
                'can_insert' => true,
                'is_suggestion' => true,
                'score' => $ranked['score'] ?? null,
                'match_reason' => 'internal_link_v2',
                'source' => 'internal_link_v2',
                'candidate_source' => 'topic_group',
                'bucket' => 'internal',
                'components' => $ranked['components'] ?? [],
            ];
        }
        $stages['ranked'] = count((array) ($body['suggestions'] ?? []));
        $stages['anchor_rejected'] = $anchorRejected;
        $stages['suggested'] = count($suggestions);

        return [
            'status' => $suggestions === [] ? 'empty' : 'ok',
            'suggestions' => $suggestions,
            'metrics' => array_merge(
                is_array($body['metrics'] ?? null) ? $body['metrics'] : [],
                ['stages' => $stages],
            ),
            'reason' => $suggestions === [] ? 'anchor_quality' : null,
            'rejected' => $filtered['rejected'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $existingInternal
     * @return array{rows: list<array<string, mixed>>, stages: array<string, int>}
     */
    private function candidates(SeoArticle $article, int $siteId, string $plain, array $existingInternal): array
    {
        $existingUrls = [];
        foreach ($existingInternal as $row) {
            if (! is_array($row)) {
                continue;
            }
            $href = trim((string) ($row['href'] ?? $row['target_url'] ?? ''));
            if ($href !== '') {
                $existingUrls[$href] = true;
            }
        }

        $groups = $this->groupsForArticle($article, $siteId);
        $rows = [];
        $seenArticles = [];
        $keywords = 0;
        $published = 0;
        foreach ($groups as $group) {
            foreach ($group->memberships as $membership) {
                $keyword = $membership->keyword;
                if (! $keyword instanceof Keyword || (int) $membership->site_id !== $siteId) {
                    continue;
                }
                $keywords++;
                $phrase = trim((string) $keyword->phrase);
                $articleId = $keyword->mainArticleIdForSite($siteId);
                if ($articleId === null || $articleId === (int) $article->id || isset($seenArticles[$articleId])) {
                    continue;
                }
                $target = SeoArticle::query()->whereKey($articleId)->where('site_id', $siteId)->first();
                if (! $target instanceof SeoArticle || ! $this->isPublished($target)) {
                    continue;
                }
                $published++;
                $url = $this->permalink($target);
                if ($url === '' || isset($existingUrls[$url])) {
                    continue;
                }
                $seenArticles[$articleId] = true;
                $title = trim((string) ($target->title ?? ''));
                $rows[] = [
                    'ref' => 'article:'.$target->id,
                    'topic_group_ref' => trim((string) ($group->semantic_group_ref ?? '')) !== ''
                        ? (string) $group->semantic_group_ref
                        : 'keyword-group:'.$group->id,
                    'url' => $url,
                    'eligible' => true,
                    'inbound_count' => $this->inboundCount((int) $target->id),
                    'outbound_count' => 0,
                    'representation' => trim($title.' '.$phrase),
                    'phrase' => $phrase,
                    'title' => $title,
                    'keyword_id' => (int) $keyword->id,
                    'article_id' => (int) $target->id,
                    'same_as_source' => false,
                    'already_linked_from_source' => false,
                ];
            }
        }

        return [
            'rows' => $rows,
            'stages' => [
                'topic_groups' => $groups->count(),
                'group_keywords' => $keywords,
                'published_targets' => $published,
                'eligible_before_guard' => count($rows),
                'source_chars' => mb_strlen($plain),
            ],
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, SeoKeywordGroup>
     */
    private function groupsForArticle(SeoArticle $article, int $siteId)
    {
        $keywordIds = DB::connection('omi_seo_ai')->table('keyword_meta')
            ->where('meta_key', KeywordMetaKey::siteMainArticleId($siteId))
            ->where('meta_value', (string) $article->id)
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $focus = trim((string) DB::connection('omi_seo_ai')->table('article_meta')
            ->where('article_id', (int) $article->id)
            ->where('meta_key', 'seo_focus_keyword')
            ->value('meta_value'));
        if ($focus !== '') {
            $focusIds = Keyword::query()
                ->whereRaw('LOWER(phrase) = ?', [mb_strtolower($focus)])
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $keywordIds = array_values(array_unique([...$keywordIds, ...$focusIds]));
        }

        if ($keywordIds === []) {
            return collect();
        }

        $groupIds = SeoKeywordGroupKeyword::query()
            ->where('site_id', $siteId)
            ->whereIn('keyword_id', $keywordIds)
            ->pluck('group_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        if ($groupIds === []) {
            return collect();
        }

        return SeoKeywordGroup::query()
            ->where('site_id', $siteId)
            ->whereIn('id', $groupIds)
            ->with(['memberships.keyword'])
            ->get();
    }

    /**
     * @param  list<string>  $usedAnchors
     */
    private function chooseAnchor(string $plain, string $title, string $phrase, array $usedAnchors): ?string
    {
        $phraseSpan = $this->verbatimSpan($plain, $phrase);
        if ($phraseSpan !== null && ! $this->isGenericAnchor($phraseSpan) && ! $this->anchorUsed($phraseSpan, $usedAnchors)) {
            return $phraseSpan;
        }

        $span = $this->contextualSpan($plain, trim($title.' '.$phrase));
        if ($span === null || $this->isGenericAnchor($span) || $this->anchorUsed($span, $usedAnchors)) {
            return null;
        }

        return $span;
    }

    private function contextualSpan(string $plain, string $targetText): ?string
    {
        $words = preg_split('/\s+/u', trim($targetText)) ?: [];
        $words = array_values(array_filter($words, static fn (string $word): bool => $word !== ''));
        $max = min(6, count($words));
        for ($length = $max; $length >= 2; $length--) {
            for ($index = 0; $index <= count($words) - $length; $index++) {
                $gram = implode(' ', array_slice($words, $index, $length));
                $span = $this->verbatimSpan($plain, $gram);
                if ($span !== null && ! $this->isGenericAnchor($span)) {
                    return $span;
                }
            }
        }

        return null;
    }

    private function verbatimSpan(string $plain, string $needle): ?string
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }
        $pos = mb_stripos($plain, $needle);
        if ($pos === false) {
            return null;
        }

        return mb_substr($plain, $pos, mb_strlen($needle));
    }

    private function isGenericAnchor(string $anchor): bool
    {
        $normalized = mb_strtolower(trim($anchor));
        if ($normalized === '' || in_array($normalized, self::GENERIC_ANCHORS, true)) {
            return true;
        }
        $tokens = $this->distinctiveTokens($normalized);
        if (count($tokens) >= 2) {
            return false;
        }
        if (count($tokens) === 1 && mb_strlen($tokens[0]) >= 10) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function distinctiveTokens(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            if ($part === '' || mb_strlen($part) < 3 || in_array($part, self::STOP_TOKENS, true)) {
                continue;
            }
            if (in_array($part, self::GENERIC_ANCHORS, true)) {
                continue;
            }
            $tokens[] = $part;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param  list<string>  $usedAnchors
     */
    private function anchorUsed(string $anchor, array $usedAnchors): bool
    {
        $left = mb_strtolower(trim($anchor));
        foreach ($usedAnchors as $used) {
            $right = mb_strtolower(trim($used));
            if ($left === $right || str_contains($left, $right) || str_contains($right, $left)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $existingInternal
     */
    private function insertedLinkCount(array $existingInternal): int
    {
        $count = 0;
        foreach ($existingInternal as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (($row['is_suggestion'] ?? false) === true || (string) ($row['source'] ?? '') === 'internal_link_v2') {
                continue;
            }
            $href = trim((string) ($row['href'] ?? $row['target_url'] ?? ''));
            if ($href === '' || $href === '#' || str_starts_with($href, '#')) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    private function isPublished(SeoArticle $article): bool
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('publishing_article_states')) {
            return false;
        }
        $state = $article->publishingState()->first(['publication_status', 'published_at']);
        if ($state === null) {
            return false;
        }
        $status = strtolower((string) $state->publication_status);
        if ($status === 'published') {
            return $state->published_at !== null;
        }

        return $status === 'publish';
    }

    private function permalink(SeoArticle $article): string
    {
        $meta = $article->articleMetas()->where('meta_key', 'wp_permalink')->value('meta_value');

        return is_string($meta) ? trim($meta) : '';
    }

    private function inboundCount(int $articleId): int
    {
        return (int) DB::connection('omi_seo_ai')
            ->table('seo_link_maps')
            ->where('target_article_id', $articleId)
            ->where('link_type', 'internal')
            ->count();
    }
}
