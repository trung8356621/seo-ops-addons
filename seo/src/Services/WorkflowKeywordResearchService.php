<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;


use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\SearchFoundation\Services\KeywordMetaRepository;
use Omnichannel\Addons\SearchFoundation\Services\TagPersistenceService;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\Tag;
use Omnichannel\Addons\Seo\Support\CtaKeywordBlacklistFilter;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordFocusAttach;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\VocabularyKeywordIntelligenceIngestionService;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use Omnichannel\Addons\ContentProjects\Support\WorkflowExecutionState;

final class WorkflowKeywordResearchService
{
    public function __construct(
        private readonly CtaKeywordBlacklistFilter $ctaKeywordBlacklistFilter,
        private readonly KeywordPersistenceService $keywordPersistence,
        private readonly TagPersistenceService $tagPersistence,
        private readonly ?VocabularyKeywordIntelligenceIngestionService $vocabularyKiIngestion = null,
    ) {}

    /**
     * Persist AI/vocabulary keyword groups as flat inventory. Grouping SSOT = Cluster.
     *
     * @param  array<string, list<string>>  $keywordGroups
     * @param  array<string, mixed>  $provenance
     * @return array{
     *   focus_keyword_id: int,
     *   focus_phrase: string,
     *   vocabulary_count: int,
     *   suggest_count: int,
     *   tags_count: int,
     *   related_topics: list<string>,
     *   ki_feedback: array<string, mixed>|null
     * }
     */
    public function syncVocabularyKeywords(
        SeoArticle $article,
        array $keywordGroups,
        ?string $focusPhrase = null,
        bool $ingestRelatedTopics = true,
        array $provenance = [],
    ): array {
        [$clusterGroups, $relatedTopics, $holonymyPhrases] = $this->partitionKeywordGroups($keywordGroups);

        if ($clusterGroups === [] && $relatedTopics === [] && $holonymyPhrases === []) {
            throw new \InvalidArgumentException('Không có dữ liệu từ khóa ngữ nghĩa để lưu.');
        }

        $siteId = (int) $article->site_id;
        $kiFeedback = null;
        $suggestCount = 0;

        if ($ingestRelatedTopics && $relatedTopics !== []) {
            $kiFeedback = $this->ingestRelatedTopicsSafe($article, $relatedTopics, $provenance);
            $suggestCount = (int) (($kiFeedback['ingested'] ?? 0) + ($kiFeedback['duplicates'] ?? 0));
        }

        if ($clusterGroups === [] && $holonymyPhrases === []) {
            return [
                'focus_keyword_id' => 0,
                'focus_phrase' => '',
                'vocabulary_count' => 0,
                'suggest_count' => $suggestCount,
                'tags_count' => 0,
                'related_topics' => $relatedTopics,
                'ki_feedback' => $kiFeedback,
            ];
        }

        $focusPhrase = trim((string) ($focusPhrase ?? ''));
        if ($focusPhrase === '') {
            throw new \InvalidArgumentException('Không xác định được từ khóa chính cho cụm chủ đề.');
        }

        if ($this->wordCount($focusPhrase) < 2) {
            throw new \InvalidArgumentException('Từ khóa chính quá rộng, cần ít nhất 2 từ để lưu vocabulary.');
        }

        KeywordFocusAttach::syncMainKeyword(
            $article,
            $siteId,
            (int) (auth()->id() ?? 0),
            $focusPhrase,
        );

        $focusKeyword = Keyword::query()
            ->whereRaw('LOWER(phrase) = ?', [mb_strtolower(Keyword::decodePhrase($focusPhrase))])
            ->first();

        if ($focusKeyword === null) {
            throw new \InvalidArgumentException('Không lưu được từ khóa chính cho vocabulary.');
        }

        $tagsCount = $this->syncHolonymyTags($focusKeyword, $holonymyPhrases, $siteId);

        $vocabularyCount = 0;

        foreach ($clusterGroups as $groupName => $keywordsList) {
            if (! is_array($keywordsList)) {
                continue;
            }

            foreach ($keywordsList as $keywordPhrase) {
                $phrase = trim((string) $keywordPhrase);
                if ($phrase === '') {
                    continue;
                }

                if ($this->wordCount($phrase) < 2
                    || $this->samePhrase($phrase, $focusPhrase)
                    || $this->samePhrase($phrase, (string) $article->title)
                    || $this->ctaKeywordBlacklistFilter->isBlocked($phrase)) {
                    continue;
                }

                $savedKeyword = $this->keywordPersistence->upsert(
                    $phrase,
                    Keyword::TYPE_NORMAL,
                    $siteId,
                    null,
                    ['group' => (string) $groupName],
                );

                if ($savedKeyword === null) {
                    continue;
                }

                $vocabularyCount++;
            }
        }

        return [
            'focus_keyword_id' => (int) $focusKeyword->id,
            'focus_phrase' => $focusPhrase,
            'vocabulary_count' => $vocabularyCount,
            'suggest_count' => $suggestCount,
            'tags_count' => $tagsCount,
            'related_topics' => $relatedTopics,
            'ki_feedback' => $kiFeedback,
        ];
    }

    /**
     * @param  list<string>  $phrases
     * @param  array<string, mixed>  $provenance
     * @return array<string, mixed>
     */
    public function ingestRelatedTopicsSafe(SeoArticle $article, array $phrases, array $provenance = []): array
    {
        return $this->ingestVocabularySuggestGroupsSafe(
            $article,
            ['Related topics' => $phrases],
            $provenance,
        );
    }

    /**
     * Ingest Suggest-eligible Vocabulary groups into keywords business table.
     * prompt_result_id in provenance is optional audit only.
     *
     * @param  array<string, list<string>|mixed>  $keywordGroups
     * @param  array<string, mixed>  $provenance
     * @return array<string, mixed>
     */
    public function ingestVocabularySuggestGroupsSafe(SeoArticle $article, array $keywordGroups, array $provenance = []): array
    {
        $service = $this->vocabularyKiIngestion
            ?? (app()->bound(VocabularyKeywordIntelligenceIngestionService::class)
                ? app(VocabularyKeywordIntelligenceIngestionService::class)
                : null);

        if (! $service instanceof VocabularyKeywordIntelligenceIngestionService) {
            return [
                'discovered' => 0,
                'ingested' => 0,
                'classified' => 0,
                'filtered' => 0,
                'duplicates' => 0,
                'source_preserved' => 0,
                'groups' => [],
                'errors' => ['ingestion_service_unavailable'],
            ];
        }

        return $service->ingestFromVocabularyGroups($article, $keywordGroups, $provenance);
    }

    /**
     * Tách Related topics (gợi ý bài mới) và Holonymy (tags) khỏi vocabulary groups.
     *
     * @param  array<string, list<string>>  $groups
     * @return array{0: array<string, list<string>>, 1: list<string>, 2: list<string>}
     */
    public function partitionKeywordGroups(array $groups): array
    {
        $clusterGroups = [];
        $relatedTopics = [];
        $holonymyPhrases = [];

        foreach ($groups as $groupName => $keywordsList) {
            if ($this->isRelatedTopicsGroup((string) $groupName)) {
                if (is_array($keywordsList)) {
                    foreach ($keywordsList as $phrase) {
                        $relatedTopics[] = (string) $phrase;
                    }
                }

                continue;
            }

            if ($this->isHolonymyGroup((string) $groupName)) {
                if (is_array($keywordsList)) {
                    foreach ($keywordsList as $phrase) {
                        $holonymyPhrases[] = (string) $phrase;
                    }
                }

                continue;
            }

            $clusterGroups[$groupName] = $keywordsList;
        }

        return [$clusterGroups, $relatedTopics, $holonymyPhrases];
    }

    /**
     * @deprecated Use VocabularyKeywordIntelligenceIngestionService via ingestRelatedTopicsSafe().
     *
     * @param  list<string>  $phrases
     */
    private function syncRelatedTopicSuggestions(SeoArticle $article, array $phrases, int $siteId): int
    {
        unset($siteId);
        $feedback = $this->ingestRelatedTopicsSafe($article, $phrases);

        return (int) (($feedback['ingested'] ?? 0) + ($feedback['duplicates'] ?? 0));
    }

    /**
     * @param  list<string>  $phrases
     */
    private function syncHolonymyTags(Keyword $focusKeyword, array $phrases, int $siteId): int
    {
        $tagIds = [];

        foreach ($phrases as $keywordPhrase) {
            $name = trim((string) $keywordPhrase);
            if ($name === '' || $this->ctaKeywordBlacklistFilter->isBlocked($name)) {
                continue;
            }

            $tag = $this->findOrCreateTag($name);
            if ($tag === null) {
                continue;
            }

            $tagIds[] = (int) $tag->id;
        }

        $tagIds = array_values(array_unique($tagIds));
        if ($tagIds === []) {
            return 0;
        }

        app(KeywordMetaRepository::class)->mergeTagIds((int) $focusKeyword->id, $tagIds);

        return count($tagIds);
    }

    private function findOrCreateTag(string $name): ?Tag
    {
        $normalized = $this->tagPersistence->normalizeName($name);
        if ($normalized === '') {
            return null;
        }

        return $this->tagPersistence->findOrCreate($normalized);
    }

    private function isRelatedTopicsGroup(string $groupName): bool
    {
        return $this->normalizeVocabularyGroupName($groupName) === 'related topics';
    }

    private function isHolonymyGroup(string $groupName): bool
    {
        return $this->normalizeVocabularyGroupName($groupName) === 'holonymy';
    }

    private function normalizeVocabularyGroupName(string $groupName): string
    {
        $name = trim($groupName);
        $name = preg_replace('/^#+\s*/u', '', $name) ?? $name;
        $name = trim(str_replace(['**', '*'], '', $name));

        return mb_strtolower($name);
    }

    public function resolveFocusPhrase(SeoArticle $article, TaskTestContext $context): string
    {
        $article->loadMissing('articleMetas');

        $fromMeta = $article->articleMetas->firstWhere('meta_key', 'seo_focus_keyword')?->meta_value;
        if (is_string($fromMeta) && trim($fromMeta) !== '') {
            return trim($fromMeta);
        }

        $fromContext = trim((string) ($context->variables['focus_keyword'] ?? ''));
        if ($fromContext !== '') {
            return $fromContext;
        }

        return '';
    }

    private function samePhrase(string $left, string $right): bool
    {
        $left = mb_strtolower(trim($left));
        $right = mb_strtolower(trim($right));

        return $left !== '' && $right !== '' && $left === $right;
    }

    private function wordCount(string $phrase): int
    {
        $phrase = trim((string) preg_replace('/\s+/u', ' ', $phrase));
        if ($phrase === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $phrase) ?: []);
    }

    /**
     * @return array<string, list<string>>
     */
    public function keywordGroupsFromState(WorkflowExecutionState $state): array
    {
        $groups = $state->meta['seo_article_keywords'] ?? [];

        return is_array($groups) ? $groups : [];
    }

    public function shouldSyncKeywords(string $actionType, WorkflowExecutionState $state): bool
    {
        if ($actionType === 'save_vocabulary_research') {
            return true;
        }

        return $this->keywordGroupsFromState($state) !== [];
    }
}
