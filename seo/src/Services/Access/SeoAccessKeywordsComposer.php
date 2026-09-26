<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Access;

use InvalidArgumentException;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\KeywordLandscapeTopic;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicLinkedArticleCounter;
use Omnichannel\Addons\Seo\Services\Context\Support\KeywordRelationshipSectionFilter;
use Omnichannel\Addons\Seo\Services\KeywordLandscape\KeywordLandscapeGateway;
use Omnichannel\Addons\Seo\Services\KeywordRelationship\KeywordRelationshipGateway;

/**
 * Keywords resource for SEO Access — landscape (GET), topic detail, relationship (POST read).
 */
class SeoAccessKeywordsComposer
{
    public const SCHEMA_LANDSCAPE = 'seo.access.keywords.v2';

    public const SCHEMA_TOPIC = 'seo.access.keywords.topic.v1';

    public const SCHEMA_RELATIONSHIP = 'seo.access.keywords.relationship.v1';

    public const DEFAULT_PER_PAGE = 50;

    public const MAX_PER_PAGE = 100;

    /** @var list<string> */
    public const SORT_ALLOWLIST = ['mcp', 'name', 'article_count', 'dna_count'];

    public function __construct(
        private readonly KeywordLandscapeGateway $landscape,
        private readonly KeywordRelationshipGateway $relationship,
        private readonly TopicLinkedArticleCounter $articleCounter,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function landscape(int $siteId, array $query = [], ?string $accessToken = null): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['per_page'] ?? self::DEFAULT_PER_PAGE);
        if ($perPage < 1) {
            $perPage = self::DEFAULT_PER_PAGE;
        }
        $perPage = min($perPage, self::MAX_PER_PAGE);

        $sort = strtolower(trim((string) ($query['sort'] ?? 'mcp')));
        if (! in_array($sort, self::SORT_ALLOWLIST, true)) {
            throw new InvalidArgumentException(
                'Invalid sort. Allowed: '.implode(', ', self::SORT_ALLOWLIST)
            );
        }

        $direction = strtolower(trim((string) ($query['direction'] ?? 'asc')));
        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Invalid direction. Allowed: asc, desc');
        }

        $coverage = $this->optionalStringFilter($query, 'coverage');
        $status = $this->optionalStringFilter($query, 'status');
        $hasFocus = $this->optionalBoolFilter($query, 'has_focus_article');

        // Landscape list stays compact — DNA belongs to Topic Detail.
        $dto = $this->landscape->forSite($siteId, false);
        $topics = $dto->topics;

        if ($coverage !== null) {
            $topics = array_values(array_filter(
                $topics,
                static fn (KeywordLandscapeTopic $t): bool => $t->coverage === $coverage,
            ));
        }
        if ($status !== null) {
            $topics = array_values(array_filter(
                $topics,
                static fn (KeywordLandscapeTopic $t): bool => $t->status === $status,
            ));
        }
        if ($hasFocus !== null) {
            $topics = array_values(array_filter(
                $topics,
                static fn (KeywordLandscapeTopic $t): bool => $t->hasFocusArticle === $hasFocus,
            ));
        }

        $topics = $this->sortTopics($topics, $sort, $direction);
        $total = count($topics);
        $totalPages = $total === 0 ? 0 : (int) ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;
        $pageTopics = array_slice($topics, $offset, $perPage);

        $tokenPath = $accessToken !== null && $accessToken !== ''
            ? '/api/v1/access/'.$accessToken
            : null;

        $rows = [];
        foreach ($pageTopics as $topic) {
            $rows[] = $this->landscapeRow($topic, $tokenPath);
        }

        return [
            'schema' => self::SCHEMA_LANDSCAPE,
            'site_ref' => 'site:'.$siteId,
            'generated_at' => now()->toIso8601String(),
            'source_updated_at' => $dto->sourceUpdatedAt,
            'summary' => [
                'topic_count' => $dto->topicCount(),
            ],
            'topics' => $rows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null  null when topic missing / cross-site
     */
    public function topicDetail(int $siteId, string $topicRef, ?string $accessToken = null): ?array
    {
        $topicId = $this->resolveTopicId($topicRef);
        if ($topicId <= 0) {
            return null;
        }

        $topic = $this->landscape->findTopic($siteId, $topicId, true);
        if (! $topic instanceof KeywordLandscapeTopic) {
            return null;
        }

        $tokenPath = $accessToken !== null && $accessToken !== ''
            ? '/api/v1/access/'.$accessToken
            : null;

        $row = $this->landscapeRow($topic, $tokenPath);
        $row['dna'] = array_values(array_map(
            static function (array $dna): array {
                return [
                    'phrase' => (string) ($dna['phrase'] ?? ''),
                    'weight' => (int) ($dna['weight'] ?? 0),
                ];
            },
            $topic->dna,
        ));
        $row['focus_articles'] = $this->loadFocusArticles($siteId, $topicId);

        return [
            'schema' => self::SCHEMA_TOPIC,
            'site_ref' => 'site:'.$siteId,
            'generated_at' => now()->toIso8601String(),
            'topic' => $row,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function relationship(int $siteId, array $input): array
    {
        $keywordId = $this->relationship->resolveKeywordId($input);
        if ($keywordId <= 0) {
            throw new InvalidArgumentException('keyword_ref or keyword_id is required.');
        }

        $sections = null;
        if (array_key_exists('sections', $input)) {
            if (! is_array($input['sections'])) {
                throw new InvalidArgumentException('sections must be an array of allowlisted section names.');
            }
            /** @var list<string> $sections */
            $sections = array_values(array_filter(
                $input['sections'],
                static fn (mixed $s): bool => is_string($s) && trim($s) !== '',
            ));
        }

        $dto = $this->relationship->forKeyword($siteId, $keywordId);
        if ($dto === null) {
            return [
                'schema' => self::SCHEMA_RELATIONSHIP,
                'site_ref' => 'site:'.$siteId,
                'keyword_ref' => 'keyword:'.$keywordId,
                'available' => false,
                'relationship' => [],
                'generated_at' => now()->toIso8601String(),
            ];
        }

        $data = KeywordRelationshipSectionFilter::apply($dto->toArray(), $sections);

        return [
            'schema' => self::SCHEMA_RELATIONSHIP,
            'site_ref' => 'site:'.$siteId,
            'keyword_ref' => 'keyword:'.$keywordId,
            'available' => true,
            'source_updated_at' => $dto->sourceUpdatedAt,
            'generated_at' => $dto->generatedAt,
            'relationship' => $data,
        ];
    }

    public function resolveTopicId(string $topicRef): int
    {
        $ref = trim($topicRef);
        if ($ref === '') {
            return 0;
        }
        if (preg_match('/^topic:(\d+)$/i', $ref, $m) === 1) {
            return max(0, (int) $m[1]);
        }
        if (ctype_digit($ref)) {
            return max(0, (int) $ref);
        }

        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function landscapeRow(KeywordLandscapeTopic $topic, ?string $tokenPath): array
    {
        $topicRef = 'topic:'.$topic->id;
        $detailHref = $tokenPath !== null
            ? $tokenPath.'/keywords/topics/'.$topicRef
            : null;

        return [
            'topic_ref' => $topicRef,
            'id' => $topic->id,
            'name' => $topic->name,
            'mcp' => $topic->mcp,
            'mcp_percent' => (int) round($topic->mcp),
            'dna_count' => $topic->dnaCount,
            'article_count' => $topic->articleCount,
            'has_focus_article' => $topic->hasFocusArticle,
            'coverage' => $topic->coverage,
            'status' => $topic->status,
            'detail_href' => $detailHref,
        ];
    }

    /**
     * @param  list<KeywordLandscapeTopic>  $topics
     * @return list<KeywordLandscapeTopic>
     */
    private function sortTopics(array $topics, string $sort, string $direction): array
    {
        $sign = $direction === 'desc' ? -1 : 1;

        usort($topics, static function (KeywordLandscapeTopic $a, KeywordLandscapeTopic $b) use ($sort, $sign): int {
            $cmp = match ($sort) {
                'name' => strcmp(mb_strtolower($a->name, 'UTF-8'), mb_strtolower($b->name, 'UTF-8')),
                'article_count' => $a->articleCount <=> $b->articleCount,
                'dna_count' => $a->dnaCount <=> $b->dnaCount,
                default => $a->mcp <=> $b->mcp,
            };
            if ($cmp !== 0) {
                return $cmp * $sign;
            }
            $byName = strcmp(mb_strtolower($a->name, 'UTF-8'), mb_strtolower($b->name, 'UTF-8'));
            if ($byName !== 0) {
                return $byName;
            }

            return $a->id <=> $b->id;
        });

        return array_values($topics);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadFocusArticles(int $siteId, int $topicId): array
    {
        $bindings = $this->articleCounter->listFocusArticleBindingsForTopic($siteId, $topicId);
        if ($bindings === []) {
            return [];
        }

        $articleIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['article_id'],
            $bindings,
        )));
        $keywordIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['keyword_id'],
            $bindings,
        )));

        $articles = SeoArticle::query()
            ->where('site_id', $siteId)
            ->whereIn('id', $articleIds)
            ->get(['id', 'title', 'status', 'slug']);

        /** @var array<int, SeoArticle> $byId */
        $byId = [];
        foreach ($articles as $article) {
            $byId[(int) $article->id] = $article;
        }

        $phrases = Keyword::query()
            ->whereIn('id', $keywordIds)
            ->pluck('phrase', 'id')
            ->all();

        $out = [];
        foreach ($bindings as $binding) {
            $articleId = (int) $binding['article_id'];
            $keywordId = (int) $binding['keyword_id'];
            $article = $byId[$articleId] ?? null;
            if (! $article instanceof SeoArticle) {
                continue;
            }
            $slug = trim((string) ($article->slug ?? ''));
            $focusKeyword = trim((string) ($phrases[$keywordId] ?? ''));
            $out[] = [
                'article_ref' => 'article:'.$articleId,
                'title' => (string) ($article->title ?? ''),
                'slug' => $slug !== '' ? $slug : null,
                'status' => trim((string) ($article->status ?? '')) !== ''
                    ? (string) $article->status
                    : null,
                'focus_keyword' => $focusKeyword !== '' ? $focusKeyword : null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function optionalStringFilter(array $query, string $key): ?string
    {
        if (! array_key_exists($key, $query) || $query[$key] === null || $query[$key] === '') {
            return null;
        }
        if (! is_string($query[$key]) && ! is_numeric($query[$key])) {
            throw new InvalidArgumentException($key.' must be a string.');
        }

        return trim((string) $query[$key]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function optionalBoolFilter(array $query, string $key): ?bool
    {
        if (! array_key_exists($key, $query) || $query[$key] === null || $query[$key] === '') {
            return null;
        }
        $raw = $query[$key];
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            $normalized = strtolower(trim($raw));
            if (in_array($normalized, ['1', 'true', 'yes'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no'], true)) {
                return false;
            }
        }
        if (is_int($raw)) {
            return $raw === 1;
        }

        throw new InvalidArgumentException($key.' must be true or false.');
    }
}
