<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleWriting;

use Omnichannel\Addons\Content\Contracts\ArticleWritingSourceProvider;
use Omnichannel\Addons\Content\Enums\ArticleWritingSourceType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;
use Omnichannel\Addons\Content\Support\ArticleGenerationSourceResult;
use Omnichannel\Addons\Content\Support\ArticleWritingInput;

/**
 * Chỉ lấy outline artifact hợp lệ (marker + section) — không lấy article body / SEO meta.
 */
final class OutlineArticleWritingSourceProvider implements ArticleWritingSourceProvider
{
    public function __construct(
        private readonly ArticleGenerationInputResolver $outlineResolver,
    ) {}

    public function sourceType(): ArticleWritingSourceType
    {
        return ArticleWritingSourceType::Outline;
    }

    public function resolve(
        array $variables,
        ?SeoArticle $article = null,
        ?ArticleGenerationSourceResult $outlineFromWorkflow = null,
    ): ArticleWritingInput {
        $title = trim((string) ($variables['post_title'] ?? $variables['title'] ?? ''));
        $keyword = trim((string) ($variables['focus_keyword'] ?? $variables['keyword'] ?? ''));
        $description = $this->secondaryDescription($variables);
        $articleId = $article instanceof SeoArticle
            ? (int) $article->getKey()
            : (isset($variables['article_id']) ? (int) $variables['article_id'] : null);

        $source = $this->resolveArtifact($variables, $article, $outlineFromWorkflow);

        return ArticleWritingInput::fromOutlineArtifact(
            rawArtifact: $source->rawArtifact,
            title: $title,
            keyword: $keyword,
            description: $description,
            articleId: $articleId > 0 ? $articleId : null,
            runId: $source->sourceRunId,
            runItemId: $source->sourceRunItemId,
            sourcePromptResultId: $source->sourcePromptResultId,
            metadata: [
                'article_generation_source' => $source->sourceType,
                'artifact_version' => $source->artifactVersion,
                'artifact_hash' => hash('sha256', $source->rawArtifact),
                'outline_section' => $source->outlineSection,
                'writing_instructions_section' => $source->writingInstructionsSection,
                'outline_marker_found' => $source->outlineMarkerFound,
                'writing_instructions_marker_found' => $source->writingInstructionsMarkerFound,
                'article_length' => $variables['article_length'] ?? null,
                ...$source->toDebugVariables(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function resolveArtifact(
        array $variables,
        ?SeoArticle $article,
        ?ArticleGenerationSourceResult $outlineFromWorkflow,
    ): ArticleGenerationSourceResult {
        if ($outlineFromWorkflow instanceof ArticleGenerationSourceResult) {
            $this->assertValid($outlineFromWorkflow->rawArtifact);

            return $outlineFromWorkflow;
        }

        if (! empty($variables['article_writing_formatted'])) {
            $raw = trim((string) ($variables['article_writing_raw_input'] ?? ''));
            if ($raw !== '' && $this->outlineResolver->isValidArtifact($raw)) {
                $parsed = $this->outlineResolver->tryResolveFromRawArtifact(
                    $raw,
                    (string) ($variables['article_generation_source'] ?? ArticleGenerationSourceResult::SOURCE_RAW_ARTIFACT),
                    isset($variables['source_run_id']) ? (int) $variables['source_run_id'] : null,
                    isset($variables['source_run_item_id']) ? (int) $variables['source_run_item_id'] : null,
                    isset($variables['source_prompt_result_id']) ? (int) $variables['source_prompt_result_id'] : null,
                );
                if ($parsed instanceof ArticleGenerationSourceResult) {
                    return $parsed;
                }
            }
        }

        $fromVars = $this->outlineResolver->tryResolveFromRawArtifact(
            trim((string) ($variables['input'] ?? '')),
            (string) ($variables['article_generation_source'] ?? ArticleGenerationSourceResult::SOURCE_RAW_ARTIFACT),
            isset($variables['source_run_id']) ? (int) $variables['source_run_id'] : null,
            isset($variables['source_run_item_id']) ? (int) $variables['source_run_item_id'] : null,
            isset($variables['source_prompt_result_id']) ? (int) $variables['source_prompt_result_id'] : null,
        );
        if ($fromVars instanceof ArticleGenerationSourceResult) {
            return $fromVars;
        }

        if ($article instanceof SeoArticle) {
            $fromArticle = $this->outlineResolver->resolveForArticle($article);
            $this->assertValid($fromArticle->rawArtifact);

            return $fromArticle;
        }

        throw new \InvalidArgumentException(ArticleGenerationInputResolver::REJECT_MESSAGE);
    }

    private function assertValid(string $raw): void
    {
        if (! $this->outlineResolver->isValidArtifact($raw)) {
            throw new \InvalidArgumentException(ArticleGenerationInputResolver::REJECT_MESSAGE);
        }

        if ($this->outlineResolver->looksLikeArticleContent($raw)) {
            throw new \InvalidArgumentException(ArticleGenerationInputResolver::REJECT_MESSAGE);
        }
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function secondaryDescription(array $variables): string
    {
        $description = trim((string) ($variables['secondary_description'] ?? ''));
        if ($description !== '') {
            return $description;
        }

        if (! isset($variables['description'])) {
            return '';
        }

        $candidate = trim((string) $variables['description']);
        $gallery = trim((string) ($variables['gallery_description'] ?? ''));
        if ($candidate !== '' && $candidate !== $gallery) {
            return $candidate;
        }

        return '';
    }
}
