<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Run-level semantics for «Chạy lại với từ khóa» — isolated generation input.
 */
final class ContentProjectFreshKeywordRestart
{
    public const MODE = 'fresh_keyword_restart';

    public const SETTING_MODE = 'generation_mode';

    public const SETTING_KEYWORD = 'generation_keyword_override';

    /**
     * @param  array<string, mixed>  $variables
     */
    public static function isActive(array $variables): bool
    {
        return strtolower(trim((string) ($variables[self::SETTING_MODE] ?? ''))) === self::MODE;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function isRunSettings(array $settings): bool
    {
        return strtolower(trim((string) ($settings[self::SETTING_MODE] ?? ''))) === self::MODE;
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    public static function shouldInheritPreviousGeneration(array $variables): bool
    {
        if (self::isActive($variables)) {
            return false;
        }

        $inherit = strtolower(trim((string) ($variables['inherit_previous_generation'] ?? '')));

        return ! in_array($inherit, ['0', 'false', 'no'], true);
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    public static function shouldUseExistingOutline(array $variables): bool
    {
        if (self::isActive($variables)) {
            return false;
        }

        $flag = strtolower(trim((string) ($variables['use_existing_outline'] ?? '')));

        return ! in_array($flag, ['0', 'false', 'no'], true);
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    public static function shouldUseRewriteSourceContent(array $variables): bool
    {
        if (self::isActive($variables)) {
            return false;
        }

        $flag = strtolower(trim((string) ($variables['use_rewrite_source_content'] ?? '')));

        return ! in_array($flag, ['0', 'false', 'no'], true);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public static function stampVariables(array $variables, string $keywordOverride): array
    {
        $keyword = trim($keywordOverride);
        $variables[self::SETTING_MODE] = self::MODE;
        $variables[self::SETTING_KEYWORD] = $keyword;
        $variables['inherit_previous_generation'] = 'false';
        $variables['use_existing_article_content'] = 'false';
        $variables['use_existing_outline'] = 'false';
        $variables['use_rewrite_source_content'] = 'false';
        $variables['rerun_scope'] = 'full';
        $variables['force_ai_regenerate'] = 'true';
        $variables['article_writing_source_type'] = 'outline';
        $variables['source_type'] = 'outline';

        if ($keyword !== '') {
            $variables['focus_keyword'] = $keyword;
            $variables['keyword'] = $keyword;
            $variables['topic'] = $keyword;
            $variables['post_title'] = $keyword;
            $variables['title'] = $keyword;
        }

        unset(
            $variables['article_writing_raw_input'],
            $variables['article_writing_formatted'],
            $variables['article_generation_source'],
            $variables['outline_id'],
            $variables['outline_version'],
            $variables['outline_source'],
            $variables['outline_marker_found'],
            $variables['writing_instructions_marker_found'],
            $variables['artifact_version'],
            $variables['artifact_hash'],
            $variables['rewrite_instruction'],
            $variables['rewrite_notes'],
            $variables['input'],
            $variables['post_content'],
            $variables['legacy_rewrite_adapter'],
        );

        return $variables;
    }

    /**
     * Persist generation keyword after successful fresh-keyword restart.
     * Preserves original task.keyword; stores override + article seo_focus_keyword.
     */
    public static function commitCanonicalKeyword(
        \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask $task,
        string $keyword,
        ?int $articleId = null,
    ): void {
        $keyword = ContentProjectGenerationKeyword::normalize($keyword);
        if ($keyword === '') {
            return;
        }

        $original = ContentProjectGenerationKeyword::originalKeyword($task);
        if ($original !== '' && $keyword === $original) {
            $task->generation_keyword_override = null;
        } else {
            $task->generation_keyword_override = $keyword;
        }
        $task->save();

        $resolvedArticleId = $articleId !== null && $articleId > 0
            ? $articleId
            : (int) ($task->article_id ?? 0);
        if ($resolvedArticleId <= 0) {
            return;
        }

        $article = \Omnichannel\Addons\Content\Models\SeoArticle::query()->find($resolvedArticleId);
        if (! $article instanceof \Omnichannel\Addons\Content\Models\SeoArticle) {
            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'seo_focus_keyword'],
            ['meta_value' => $keyword],
        );
    }
}
