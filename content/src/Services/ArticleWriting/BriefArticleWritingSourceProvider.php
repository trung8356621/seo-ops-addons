<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleWriting;

use Omnichannel\Addons\Content\Contracts\ArticleWritingSourceProvider;
use Omnichannel\Addons\Content\Enums\ArticleWritingSourceType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleGenerationSourceResult;
use Omnichannel\Addons\Content\Support\ArticleWritingInput;

/**
 * Brief: title / keyword / description / free input — field rỗng bỏ hẳn.
 * Caller phải stamp source_type=brief; không suy luận từ thiếu outline.
 */
final class BriefArticleWritingSourceProvider implements ArticleWritingSourceProvider
{
    public function sourceType(): ArticleWritingSourceType
    {
        return ArticleWritingSourceType::Brief;
    }

    public function resolve(
        array $variables,
        ?SeoArticle $article = null,
        ?ArticleGenerationSourceResult $outlineFromWorkflow = null,
    ): ArticleWritingInput {
        unset($outlineFromWorkflow);

        $title = trim((string) ($variables['post_title'] ?? $variables['title'] ?? ''));
        $keyword = trim((string) ($variables['focus_keyword'] ?? $variables['keyword'] ?? ''));
        $description = trim((string) ($variables['secondary_description'] ?? ''));
        if ($description === '' && isset($variables['description'])) {
            $candidate = trim((string) $variables['description']);
            $gallery = trim((string) ($variables['gallery_description'] ?? ''));
            if ($candidate !== '' && $candidate !== $gallery) {
                $description = $candidate;
            }
        }

        $freeInput = trim((string) (
            $variables['user_brief']
            ?? $variables['article_writing_raw_input']
            ?? ''
        ));
        // Không lấy {{input}} đã format làm free input.
        if ($freeInput === '' && empty($variables['article_writing_formatted'])) {
            $freeInput = trim((string) ($variables['brief_free_input'] ?? ''));
        }

        $articleId = $article instanceof SeoArticle
            ? (int) $article->getKey()
            : (isset($variables['article_id']) ? (int) $variables['article_id'] : null);

        $writing = ArticleWritingInput::fromBrief(
            title: $title,
            keyword: $keyword,
            description: $description,
            articleId: $articleId > 0 ? $articleId : null,
            freeInput: $freeInput,
            metadata: [
                'article_length' => $variables['article_length'] ?? null,
            ],
        );

        if (trim($writing->title) === ''
            && trim($writing->keyword) === ''
            && trim($writing->description) === ''
            && trim($writing->input) === ''
        ) {
            throw new \InvalidArgumentException(
                'Brief trống: cần ít nhất tiêu đề, từ khóa, mô tả hoặc nội dung tự do.',
            );
        }

        return $writing;
    }
}
