<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;


use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use Omnichannel\Addons\Content\Enums\ArticleWritingSourceType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleWriting\BriefArticleWritingSourceProvider;
use Omnichannel\Addons\Content\Services\ArticleWriting\ExistingArticleWritingSourceProvider;
use Omnichannel\Addons\Content\Services\ArticleWriting\OutlineArticleWritingSourceProvider;
use Omnichannel\Addons\Content\Support\ArticleGenerationSourceResult;
use Omnichannel\Addons\Content\Support\ArticleWritingInput;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;

/**
 * Assembler: chọn source provider → ArticleWritingInput → format {{input}}.
 * Capability runtime: article.content.generate.
 */
class ArticleWritingAssembler
{
    public function __construct(
        private readonly ArticleGenerationInputResolver $outlineResolver,
        private readonly ArticleWritingInputFormatter $formatter,
        private readonly WordPressArticleContentService $wordPressContent,
        private readonly WorkflowParserService $workflowParser,
        private readonly ?OutlineArticleWritingSourceProvider $outlineProvider = null,
        private readonly ?ExistingArticleWritingSourceProvider $existingProvider = null,
        private readonly ?BriefArticleWritingSourceProvider $briefProvider = null,
    ) {}

    private function outlines(): OutlineArticleWritingSourceProvider
    {
        return $this->outlineProvider
            ?? new OutlineArticleWritingSourceProvider($this->outlineResolver);
    }

    private function existing(): ExistingArticleWritingSourceProvider
    {
        return $this->existingProvider
            ?? new ExistingArticleWritingSourceProvider(
                $this->wordPressContent,
                $this->workflowParser,
                $this->outlineResolver,
            );
    }

    private function briefs(): BriefArticleWritingSourceProvider
    {
        return $this->briefProvider ?? new BriefArticleWritingSourceProvider;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array{writing: ArticleWritingInput, variables: array<string, mixed>}|null
     */
    public function assembleForPrompt(
        array $variables,
        ?TaskTestContext $context = null,
        ?ArticleGenerationSourceResult $outlineFromWorkflow = null,
    ): ?array {
        $sourceType = ArticleWritingSourceType::tryFromMixed(
            $variables['article_writing_source_type'] ?? $variables['source_type'] ?? null,
        );

        if ($sourceType === null && $outlineFromWorkflow instanceof ArticleGenerationSourceResult) {
            $sourceType = ArticleWritingSourceType::Outline;
        }

        if ($sourceType === null
            && (bool) ($variables['legacy_rewrite_adapter'] ?? false)
        ) {
            $sourceType = ArticleWritingSourceType::ExistingArticle;
        }

        if ($sourceType === null) {
            // Default generate path (publish first-run): outline — không default brief.
            $sourceType = ArticleWritingSourceType::Outline;
        }

        $article = $context?->article;

        try {
            $writing = match ($sourceType) {
                ArticleWritingSourceType::Outline => $this->outlines()->resolve(
                    $variables,
                    $article,
                    $outlineFromWorkflow,
                ),
                ArticleWritingSourceType::ExistingArticle => $this->existing()->resolve(
                    $variables,
                    $article,
                ),
                ArticleWritingSourceType::Brief => $this->briefs()->resolve(
                    $variables,
                    $article,
                ),
            };
        } catch (\InvalidArgumentException) {
            return null;
        }

        $merged = $this->formatter->applyToVariables($writing, $variables);
        if (isset($variables['article_length'])) {
            $merged['article_length'] = $variables['article_length'];
        }
        $merged['article_writing_raw_input'] = $writing->input;

        return [
            'writing' => $writing,
            'variables' => $merged,
        ];
    }

    /**
     * Editor: Viết lại toàn bộ bài hiện có.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function applyExistingArticleFromArticle(
        SeoArticle $article,
        array $variables,
        string $title = '',
        string $keyword = '',
        string $description = '',
    ): array {
        if ($title !== '') {
            $variables['post_title'] = $title;
        }
        if ($keyword !== '') {
            $variables['focus_keyword'] = $keyword;
        }
        if ($description !== '') {
            $variables['secondary_description'] = $description;
        }

        $writing = $this->existing()->resolve($variables, $article);

        return $this->formatter->applyToVariables($writing, $variables);
    }

    /**
     * Content Project: Tạo lại bài từ dàn ý.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function applyOutlineFromArticle(SeoArticle $article, array $variables): array
    {
        $writing = $this->outlines()->resolve($variables, $article);
        $merged = $this->formatter->applyToVariables($writing, $variables);
        $meta = $writing->metadata;

        return array_merge($merged, [
            'outline_id' => $writing->runItemId !== null
                ? 'run_item:'.$writing->runItemId.':outline'
                : 'article:'.(int) $article->getKey().':'.ArticleOutlineResolver::META_KEY,
            'outline_version' => $meta['artifact_version'] ?? null,
            'outline_source' => $meta['article_generation_source'] ?? null,
            'outline_marker_found' => $meta['outline_marker_found'] ?? null,
            'writing_instructions_marker_found' => $meta['writing_instructions_marker_found'] ?? null,
            'artifact_version' => $meta['artifact_version'] ?? null,
            'artifact_hash' => $meta['artifact_hash'] ?? null,
        ]);
    }
}
