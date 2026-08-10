<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordArticleMappingType;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordArticleMapping;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiKeyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;

/**
 * Map keyword workspace tới SeoArticle hiện có (cùng site) qua title/slug chứa
 * token của normalized keyword. Không ghi đè mapping đã được đánh dấu is_manual.
 */
final class KeywordExistingContentMapper
{
    /**
     * @return list<SeoKeywordArticleMapping>
     */
    public function mapWorkspace(SeoKeywordWorkspace $workspace): array
    {
        $siteId = (int) $workspace->site_id;
        if ($siteId <= 0) {
            return [];
        }

        $keywords = SeoKiKeyword::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_excluded', false)
            ->where('is_duplicate', false)
            ->get();

        if ($keywords->isEmpty()) {
            return [];
        }

        $articles = SeoArticle::query()
            ->where('site_id', $siteId)
            ->whereNotNull('title')
            ->get(['id', 'title', 'slug']);

        if ($articles->isEmpty()) {
            return [];
        }

        $manualKeywordIds = SeoKeywordArticleMapping::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_manual', true)
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->flip()
            ->all();

        $existingPairs = SeoKeywordArticleMapping::query()
            ->where('workspace_id', $workspace->id)
            ->get(['keyword_id', 'article_id'])
            ->map(static fn (SeoKeywordArticleMapping $m): string => $m->keyword_id.':'.$m->article_id)
            ->flip()
            ->all();

        $created = [];

        foreach ($keywords as $keyword) {
            if (isset($manualKeywordIds[(int) $keyword->id])) {
                continue;
            }

            $tokens = $this->tokens((string) $keyword->normalized_keyword);
            if ($tokens === []) {
                continue;
            }

            foreach ($articles as $article) {
                $pairKey = $keyword->id.':'.$article->id;
                if (isset($existingPairs[$pairKey])) {
                    continue;
                }

                $confidence = $this->matchConfidence($tokens, (string) $article->title, (string) $article->slug);
                if ($confidence === null) {
                    continue;
                }

                $mapping = new SeoKeywordArticleMapping([
                    'public_ref' => 'pending',
                    'workspace_id' => $workspace->id,
                    'tenant_id' => $workspace->tenant_id,
                    'site_id' => $siteId,
                    'keyword_id' => $keyword->id,
                    'article_id' => $article->id,
                    'article_ref' => ContentProjectPublicRef::article((int) $article->id),
                    'mapping_type' => KeywordArticleMappingType::CurrentContent->value,
                    'confidence' => $confidence,
                    'is_primary' => false,
                    'status' => 'active',
                    'is_manual' => false,
                    'metadata' => ['matched_tokens' => $tokens],
                ]);
                $mapping->save();
                $mapping->public_ref = KeywordIntelligencePublicRef::articleMapping((int) $mapping->id);
                $mapping->save();

                $existingPairs[$pairKey] = true;
                $created[] = $mapping;
            }
        }

        return $created;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $normalized): array
    {
        $tokens = preg_split('/\s+/u', trim($normalized)) ?: [];

        return array_values(array_filter(
            $tokens,
            static fn (string $t): bool => mb_strlen($t, 'UTF-8') >= 2,
        ));
    }

    /**
     * @param  list<string>  $tokens
     */
    private function matchConfidence(array $tokens, string $title, string $slug): ?string
    {
        $haystack = mb_strtolower(trim($title.' '.$slug), 'UTF-8');
        if ($haystack === '' || $tokens === []) {
            return null;
        }

        $matched = 0;
        foreach ($tokens as $token) {
            if (mb_strpos($haystack, $token, 0, 'UTF-8') !== false) {
                $matched++;
            }
        }

        if ($matched === 0) {
            return null;
        }

        $ratio = $matched / count($tokens);

        return match (true) {
            $ratio >= 0.9 => 'high',
            $ratio >= 0.5 => 'medium',
            default => 'low',
        };
    }
}
