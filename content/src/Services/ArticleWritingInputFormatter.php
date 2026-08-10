<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Enums\ArticleWritingSourceType;
use Omnichannel\Addons\Content\Support\ArticleWritingInput;

/**
 * Formatter duy nhất cho {{input}} của article.content.generate.
 * Field rỗng → bỏ cả label; không placeholder trống; không trộn Gallery Description.
 */
final class ArticleWritingInputFormatter
{
    public function format(ArticleWritingInput $writing): string
    {
        $blocks = [];

        $blocks[] = 'Loại đầu vào: '.$writing->sourceType->labelVi();
        $blocks[] = 'Quy tắc nguồn: '.$writing->sourceType->sourceRule();

        $title = trim($writing->title);
        if ($title !== '') {
            $blocks[] = 'Tiêu đề: '.$title;
        }

        $keyword = trim($writing->keyword);
        if ($keyword !== '') {
            $blocks[] = 'Từ khóa chính: '.$keyword;
        }

        $description = trim($writing->description);
        if ($description !== '') {
            $blocks[] = 'Mô tả bổ sung: '.$description;
        }

        $body = trim($writing->input);
        if ($body !== '' || $writing->sourceType !== ArticleWritingSourceType::Brief) {
            if ($body !== '') {
                $blocks[] = "Nội dung đầu vào:\n".$body;
            }
        }

        return implode("\n\n", $blocks);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function applyToVariables(ArticleWritingInput $writing, array $variables): array
    {
        $formatted = $this->format($writing);
        $meta = $writing->historyMetadata();

        $variables['input'] = $formatted;
        $variables['article_writing_raw_input'] = $writing->input;
        $variables['article_writing_source_type'] = $writing->sourceType->value;
        $variables['source_type'] = $writing->sourceType->value;
        $variables['source_hash'] = $meta['source_hash'];
        $variables['source_run_id'] = $meta['source_run_id'];
        $variables['source_run_item_id'] = $meta['source_run_item_id'];
        $variables['source_prompt_result_id'] = $meta['source_prompt_result_id'];
        $variables['description_present'] = $meta['description_present'];
        $variables['article_writing_formatted'] = true;

        if ($writing->title !== '') {
            $variables['post_title'] = $writing->title;
            $variables['title'] = $writing->title;
        }
        if ($writing->keyword !== '') {
            $variables['focus_keyword'] = $writing->keyword;
            $variables['keyword'] = $writing->keyword;
        }
        if ($writing->description !== '') {
            $variables['secondary_description'] = $writing->description;
            // description phụ bài — không ghi đè gallery_description
            $variables['description'] = $writing->description;
        }

        unset($variables['post_content'], $variables['existing_body'], $variables['article_content']);

        return $variables;
    }
}
