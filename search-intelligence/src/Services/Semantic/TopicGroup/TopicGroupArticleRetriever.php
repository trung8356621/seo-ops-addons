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

        $groups = SeoKeywordGroup::query()
            ->where('site_id', $siteId)
            ->with(['memberships.keyword'])
            ->get();
        if ($groups->isEmpty()) {
            return ['status' => 'empty', 'groups' => []];
        }

        $payloadGroups = [];
        $byRef = [];
        foreach ($groups as $group) {
            $examples = $this->examples($group);
            if ($examples === []) {
                continue;
            }
            $ref = $this->ref($group);
            $byRef[$ref] = $group;
            $payloadGroups[] = [
                'ref' => $ref,
                'label' => (string) $group->name,
                'examples' => $examples,
            ];
        }
        if ($payloadGroups === []) {
            return ['status' => 'empty', 'groups' => []];
        }

        try {
            $matched = $this->semantic->match(
                'site:'.$siteId,
                $query,
                $payloadGroups,
                null,
                max(1, min(20, $matchLimit)),
            );
        } catch (Throwable $e) {
            return ['status' => 'unavailable', 'groups' => [], 'error' => $e->getMessage()];
        }

        $rows = [];
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

        return ['status' => 'ok', 'groups' => $rows];
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
