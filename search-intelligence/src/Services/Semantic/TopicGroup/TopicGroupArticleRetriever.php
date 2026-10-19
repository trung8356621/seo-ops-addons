<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Semantic\TopicGroup;

use Omnichannel\Addons\AgentRuntime\Retrieval\TopicGroupArticleSource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\Seo\Models\SeoArticleProfile;
use Throwable;

/**
 * Reads Keyword Group membership as the Topic Group boundary.
 * Does not write groups, keywords, or articles.
 */
final class TopicGroupArticleRetriever implements TopicGroupArticleSource
{
    public function __construct(private readonly TopicGroupRetrievalClient $semantic) {}

    public function retrieve(int $siteId, string $query, int $matchLimit = 3): array
    {
        if ($siteId <= 0 || trim($query) === '') {
            return ['status' => 'empty', 'groups' => []];
        }

        $started = hrtime(true);
        $groups = SeoKeywordGroup::query()
            ->where('site_id', $siteId)
            ->with(['memberships.keyword'])
            ->get();
        $loadMs = (int) round((hrtime(true) - $started) / 1_000_000);
        if ($groups->isEmpty()) {
            return ['status' => 'empty', 'groups' => [], 'timings' => ['load_groups_ms' => $loadMs], 'groups_loaded' => 0];
        }

        $normalizeStarted = hrtime(true);
        $externalCounts = [];
        foreach ($groups as $group) {
            $external = $this->ref($group);
            $externalCounts[$external] = ($externalCounts[$external] ?? 0) + 1;
        }

        $payloadGroups = [];
        $byRef = [];
        foreach ($groups as $group) {
            $examples = $this->examples($group);
            if ($examples === []) {
                continue;
            }
            $external = $this->ref($group);
            $ref = ($externalCounts[$external] ?? 0) > 1
                ? 'keyword-group:'.$group->id
                : $external;
            $byRef[$ref] = $group;
            $payloadGroups[] = [
                'ref' => $ref,
                'label' => (string) $group->name,
                'examples' => $examples,
            ];
        }
        $normalizeMs = (int) round((hrtime(true) - $normalizeStarted) / 1_000_000);
        if ($payloadGroups === []) {
            return [
                'status' => 'empty',
                'groups' => [],
                'timings' => ['load_groups_ms' => $loadMs, 'normalize_refs_ms' => $normalizeMs],
                'groups_loaded' => $groups->count(),
                'groups_sent' => 0,
            ];
        }

        try {
            $matchStarted = hrtime(true);
            $matched = $this->semantic->match(
                'site:'.$siteId,
                $query,
                $payloadGroups,
                null,
                max(1, min(20, $matchLimit)),
            );
            $matchMs = (int) round((hrtime(true) - $matchStarted) / 1_000_000);
        } catch (Throwable $e) {
            return [
                'status' => 'unavailable',
                'groups' => [],
                'error' => $e->getMessage(),
                'timings' => [
                    'load_groups_ms' => $loadMs,
                    'normalize_refs_ms' => $normalizeMs,
                    'topic_match_ms' => (int) round((hrtime(true) - $matchStarted) / 1_000_000),
                ],
                'groups_loaded' => $groups->count(),
                'groups_sent' => count($payloadGroups),
            ];
        }

        $rows = [];
        $hydrateStarted = hrtime(true);
        foreach ($matched->matches as $match) {
            $group = $byRef[$match['ref']] ?? null;
            if (! $group instanceof SeoKeywordGroup || (int) $group->site_id !== $siteId) {
                continue;
            }
            $rows[] = [
                'ref' => $match['ref'],
                'name' => (string) $group->name,
                'score' => $match['score'],
                'keywords' => $this->keywords($group, $siteId),
            ];
        }

        return [
            'status' => 'ok',
            'groups' => $rows,
            'timings' => [
                'load_groups_ms' => $loadMs,
                'normalize_refs_ms' => $normalizeMs,
                'topic_match_ms' => $matchMs,
                'keyword_hydrate_ms' => (int) round((hrtime(true) - $hydrateStarted) / 1_000_000),
            ],
            'groups_loaded' => $groups->count(),
            'groups_sent' => count($payloadGroups),
        ];
    }

    private function ref(SeoKeywordGroup $group): string
    {
        $external = trim((string) ($group->semantic_group_ref ?? ''));

        return $external !== '' ? $external : 'keyword-group:'.$group->id;
    }

    /**
     * @return list<string>
     */
    private function examples(SeoKeywordGroup $group): array
    {
        $examples = [];
        $name = trim((string) $group->name);
        if ($name !== '') {
            $examples[] = $name;
        }
        foreach ($group->memberships as $membership) {
            if ((int) $membership->site_id !== (int) $group->site_id) {
                continue;
            }
            $phrase = trim((string) ($membership->keyword?->phrase ?? ''));
            if ($phrase !== '') {
                $examples[] = $phrase;
            }
            if (count($examples) >= 8) {
                break;
            }
        }

        return array_values(array_unique($examples));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function keywords(SeoKeywordGroup $group, int $siteId): array
    {
        $rows = [];
        foreach ($group->memberships as $membership) {
            if ((int) $membership->site_id !== $siteId || ! $membership->keyword instanceof Keyword) {
                continue;
            }
            $keyword = $membership->keyword;
            $articleId = $keyword->mainArticleIdForSite($siteId);
            $article = null;
            if ($articleId !== null) {
                $model = SeoArticle::query()->whereKey($articleId)->where('site_id', $siteId)->first();
                if ($model instanceof SeoArticle) {
                    $score = SeoArticleProfile::query()->where('article_id', $model->id)->value('seo_score');
                    $article = [
                        'ref' => 'article:'.$model->id,
                        'title' => (string) ($model->title ?? ''),
                        'site_id' => $siteId,
                        'seo_score' => $score === null ? null : (float) $score,
                    ];
                }
            }
            $rows[] = [
                'ref' => 'keyword:'.$keyword->id,
                'phrase' => (string) $keyword->phrase,
                'article' => $article,
            ];
        }

        return $rows;
    }
}
