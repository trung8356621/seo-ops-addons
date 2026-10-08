<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\Seo\Services\Linking\InternalLinkV2Guard;
use Throwable;

/**
 * Separate Internal Link V2 path. The legacy suggestion pipeline stays in place.
 */
final class InternalLinkV2Suggester
{
    public function __construct(private readonly InternalLinkV2Guard $guard = new InternalLinkV2Guard()) {}

    /**
     * @param  list<array<string, mixed>>  $existingInternal
     * @return array{status: string, suggestions: list<array<string, mixed>>, metrics: array<string, mixed>, reason?: string}
     */
    public function suggest(SeoArticle $article, string $content, array $existingInternal = []): array
    {
        if (! (bool) config('semantic.enabled', false)) {
            return ['status' => 'unavailable', 'suggestions' => [], 'metrics' => []];
        }
        $plain = trim(strip_tags($content));
        if ($plain === '') {
            return ['status' => 'empty', 'suggestions' => [], 'metrics' => [], 'reason' => 'content_unavailable'];
        }
        $maxInternal = max(1, (int) config('seo-content-ai.link_suggestions.max_internal_links', 10));
        if (count($existingInternal) >= $maxInternal) {
            return ['status' => 'empty', 'suggestions' => [], 'metrics' => [], 'reason' => 'link_quota'];
        }

        $siteId = (int) ($article->site_id ?? 0);
        $candidates = $this->candidates($article, $siteId, $plain, $existingInternal);
        $filtered = $this->guard->filter('article:'.$article->id, $candidates);
        if ($filtered['accepted'] === []) {
            return ['status' => 'empty', 'suggestions' => [], 'metrics' => [], 'rejected' => $filtered['rejected']];
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
            return ['status' => 'unavailable', 'suggestions' => [], 'metrics' => [], 'error' => $e->getMessage()];
        }

        if (! $response->successful()) {
            return ['status' => 'unavailable', 'suggestions' => [], 'metrics' => []];
        }

        $body = $response->json();
        $byRef = [];
        foreach ($filtered['accepted'] as $row) {
            $byRef[$row['ref']] = $row;
        }
        $suggestions = [];
        foreach ((array) ($body['suggestions'] ?? []) as $ranked) {
            if (! is_array($ranked)) {
                continue;
            }
            $local = $byRef[(string) ($ranked['ref'] ?? '')] ?? null;
            if ($local === null) {
                continue;
            }
            $anchor = (string) $local['anchor'];
            if ($anchor === '' || mb_stripos($plain, $anchor) === false) {
                continue;
            }
            $suggestions[] = [
                'text' => $anchor,
                'keyword_id' => $local['keyword_id'],
                'href' => $local['url'],
                'target_url' => $local['url'],
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

        return [
            'status' => 'ok',
            'suggestions' => $suggestions,
            'metrics' => is_array($body['metrics'] ?? null) ? $body['metrics'] : [],
            'rejected' => $filtered['rejected'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $existingInternal
     * @return list<array<string, mixed>>
     */
    private function candidates(SeoArticle $article, int $siteId, string $plain, array $existingInternal): array
    {
        $existingUrls = [];
        foreach ($existingInternal as $row) {
            $href = trim((string) ($row['href'] ?? $row['target_url'] ?? ''));
            if ($href !== '') {
                $existingUrls[$href] = true;
            }
        }

        $groups = SeoKeywordGroup::query()->where('site_id', $siteId)->with(['memberships.keyword'])->get();
        $rows = [];
        $seenArticles = [];
        foreach ($groups as $group) {
            foreach ($group->memberships as $membership) {
                $keyword = $membership->keyword;
                if (! $keyword instanceof Keyword || (int) $membership->site_id !== $siteId) {
                    continue;
                }
                $phrase = trim((string) $keyword->phrase);
                if ($phrase === '' || mb_stripos($plain, $phrase) === false) {
                    continue;
                }
                $articleId = $keyword->mainArticleIdForSite($siteId);
                if ($articleId === null || $articleId === (int) $article->id || isset($seenArticles[$articleId])) {
                    continue;
                }
                $target = SeoArticle::query()->whereKey($articleId)->where('site_id', $siteId)->first();
                if (! $target instanceof SeoArticle || ! $this->isPublished($target)) {
                    continue;
                }
                $url = $this->permalink($target);
                if ($url === '' || isset($existingUrls[$url])) {
                    continue;
                }
                $seenArticles[$articleId] = true;
                $rows[] = [
                    'ref' => 'article:'.$target->id,
                    'topic_group_ref' => trim((string) ($group->semantic_group_ref ?? '')) !== ''
                        ? (string) $group->semantic_group_ref
                        : 'keyword-group:'.$group->id,
                    'url' => $url,
                    'eligible' => true,
                    'inbound_count' => $this->inboundCount((int) $target->id),
                    'outbound_count' => 0,
                    'representation' => trim((string) ($target->title ?? '').' '.$phrase),
                    'anchor' => $phrase,
                    'keyword_id' => (int) $keyword->id,
                    'article_id' => (int) $target->id,
                    'same_as_source' => false,
                    'already_linked_from_source' => false,
                ];
            }
        }

        return $rows;
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
