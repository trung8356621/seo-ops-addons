<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;

/**
 * Lazy vocabulary groups for article editor sidebar.
 *
 * Prefer `seo_article_keywords`. When missing (common for outline→content runs),
 * fall back to the TASK_2 vocabulary section inside `seo_article_outline`.
 */
final class ArticleEditorVocabularyPayloadService
{
    /**
     * Groups that belong to other artifacts / are not useful in the Vocabulary widget.
     * Matched case-insensitively against the normalized group title.
     *
     * @var list<string>
     */
    private const EXCLUDED_GROUP_PATTERNS = [
        '/^unigrams?$/iu',
        '/^từ\s*đơn$/iu',
        '/^faq$/iu',
        '/^faqs$/iu',
        '/^questions?$/iu',
        '/câu\s*hỏi/iu',
        '/frequently\s+asked/iu',
    ];

    public function __construct(
        private readonly WorkflowParserService $workflowParser,
    ) {}

    /**
     * @return array{
     *     groups: array<string, list<string>>,
     *     group_count: int,
     *     item_count: int,
     *     planning: array{
     *         project_options: array<int, string>,
     *         selected_project_id: int|null,
     *         site_id: int
     *     }
     * }
     */
    public function forArticle(SeoArticle $article): array
    {
        $article->loadMissing('articleMetas');
        $groups = $this->resolveGroups($article);
        $siteId = (int) (ArticleResource::resolveArticleSiteId($article) ?? $article->site_id ?? 0);
        $selectedProjectId = ArticleResource::articleAssignedContentProjectId($article);
        $projectOptions = ArticleResource::contentProjectOptionsForVocabularyPlanning(
            $siteId > 0 ? $siteId : null,
        );

        if (
            $selectedProjectId !== null
            && $selectedProjectId > 0
            && ! array_key_exists($selectedProjectId, $projectOptions)
        ) {
            // Soft-full / edge cases still keep the article's project selectable.
            $projectOptions = ArticleResource::contentProjectOptionsForVocabularyPlanning(
                $siteId > 0 ? $siteId : null,
                includeSelectedProjectId: $selectedProjectId,
            );
        }

        return [
            'groups' => $groups,
            'group_count' => count($groups),
            'item_count' => (int) array_sum(array_map(static fn (array $items): int => count($items), $groups)),
            'planning' => [
                'project_options' => $projectOptions,
                'selected_project_id' => $selectedProjectId,
                'site_id' => $siteId,
            ],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function resolveGroups(SeoArticle $article): array
    {
        $rawKeywords = $article->articleMetas->firstWhere('meta_key', 'seo_article_keywords')?->meta_value;
        $fromKeywords = $this->parseGroups($rawKeywords);
        if ($fromKeywords !== []) {
            return $fromKeywords;
        }

        $outline = trim((string) (
            $article->articleMetas->firstWhere('meta_key', ArticleOutlineResolver::META_KEY)?->meta_value ?? ''
        ));
        if ($outline === '') {
            return [];
        }

        return $this->parseGroups($this->vocabularyMarkdownFromOutline($outline));
    }

    private function vocabularyMarkdownFromOutline(string $outline): string
    {
        $start = ArticleGenerationInputResolver::VOCABULARY_START;
        $end = ArticleGenerationInputResolver::VOCABULARY_END;
        $pattern = '/'.preg_quote($start, '/').'(.*?)'.preg_quote($end, '/').'/s';
        if (preg_match($pattern, $outline, $matches) === 1) {
            $section = trim((string) ($matches[1] ?? ''));
            if ($section !== '') {
                return $section;
            }
        }

        // Older outlines may embed ### Holonymy blocks without markers.
        return $outline;
    }

    /**
     * @return array<string, list<string>>
     */
    private function parseGroups(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (is_array($raw)) {
            return $this->normalizeGroupsArray($raw);
        }

        $string = trim((string) $raw);
        if ($string === '') {
            return [];
        }

        $decoded = json_decode($string, true);
        if (is_array($decoded)) {
            return $this->normalizeGroupsArray($decoded);
        }

        return $this->normalizeGroupsArray($this->workflowParser->parseKeywords($string));
    }

    /**
     * @param  array<mixed, mixed>  $data
     * @return array<string, list<string>>
     */
    private function normalizeGroupsArray(array $data): array
    {
        $result = [];

        foreach ($data as $group => $items) {
            $groupName = trim((string) $group);
            if ($groupName === '' || ! $this->isAllowedVocabularyGroup($groupName)) {
                continue;
            }

            $list = [];
            foreach (is_array($items) ? $items : [] as $item) {
                $phrase = trim(is_string($item) ? $item : (string) ($item['keyword'] ?? $item['phrase'] ?? $item['title'] ?? ''));
                if ($phrase !== '') {
                    $list[] = $phrase;
                }
            }

            if ($list !== []) {
                $result[$groupName] = array_values(array_unique($list));
            }
        }

        return $result;
    }

    public function isAllowedVocabularyGroup(string $groupName): bool
    {
        $name = trim($groupName);
        if ($name === '') {
            return false;
        }

        foreach (self::EXCLUDED_GROUP_PATTERNS as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return false;
            }
        }

        return true;
    }
}
