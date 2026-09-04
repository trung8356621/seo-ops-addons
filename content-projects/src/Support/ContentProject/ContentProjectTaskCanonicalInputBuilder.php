<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;

/**
 * Builds the canonical Task {{input}} subject for prompts (Outline/Vocabulary/…).
 * Prompt templates stay type-agnostic; this layer owns rewrite vs create semantics.
 */
final class ContentProjectTaskCanonicalInputBuilder
{
    /**
     * @param  array<string, mixed>  $variables
     */
    public static function forRewrite(array $variables): string
    {
        $keyword = ContentProjectItemIdentity::normalize(
            isset($variables['focus_keyword'])
                ? (string) $variables['focus_keyword']
                : (isset($variables['keyword']) ? (string) $variables['keyword'] : null),
        );
        if ($keyword !== '') {
            return $keyword;
        }

        return ContentProjectItemIdentity::normalize(
            isset($variables['post_title'])
                ? (string) $variables['post_title']
                : (isset($variables['title']) ? (string) $variables['title'] : null),
        );
    }

    /**
     * Structured Planning context for CREATE. Empty fields are omitted (no empty labels).
     *
     * @param  array<string, mixed>  $variables
     */
    public static function forCreate(array $variables, ?SeoProjectTask $task = null): string
    {
        $lines = [];

        $idea = ContentProjectItemIdentity::normalize(
            isset($variables['post_title'])
                ? (string) $variables['post_title']
                : (isset($variables['title']) ? (string) $variables['title'] : null),
        );
        if ($idea === '' && $task !== null) {
            $idea = ContentProjectItemIdentity::normalize(
                isset($task->title) ? (string) $task->title : null,
            );
        }
        if ($idea !== '') {
            $lines[] = 'Ý tưởng: '.$idea;
        }

        $keyword = ContentProjectItemIdentity::normalize(
            isset($variables['focus_keyword'])
                ? (string) $variables['focus_keyword']
                : (isset($variables['keyword']) ? (string) $variables['keyword'] : null),
        );
        if ($keyword === '' && $task !== null) {
            $keyword = ContentProjectGenerationKeyword::effective($task);
        }
        if ($keyword !== '') {
            $lines[] = 'Từ khóa chính: '.$keyword;
        }

        $brief = trim((string) ($variables['secondary_description'] ?? $variables['description'] ?? ''));
        if ($brief === '' && $task !== null) {
            $brief = trim((string) ($task->secondary_description ?? ''));
        }
        if ($brief !== '') {
            $lines[] = 'Mô tả: '.$brief;
        }

        $productDescription = trim((string) ($variables['gallery_description'] ?? ''));
        if ($productDescription === '' && $task !== null) {
            $postType = SeoProjectTask::normalizePostType($task->post_type);
            if ($postType === SeoProjectTask::POST_TYPE_PRODUCT) {
                $productDescription = trim((string) ($task->description ?? ''));
            }
        }
        if ($productDescription !== '') {
            $lines[] = 'Mô tả sản phẩm: '.$productDescription;
        }

        $topic = ContentProjectItemIdentity::normalize(
            isset($variables['topic']) ? (string) $variables['topic'] : null,
        );
        if ($topic === '') {
            $topic = ContentProjectItemIdentity::effectiveSubject($idea !== '' ? $idea : null, $keyword);
        }
        if ($topic !== '' && $topic !== $idea && $topic !== $keyword) {
            $lines[] = 'Chủ đề: '.$topic;
        }

        $loaiSanPham = trim((string) ($variables['loai_san_pham'] ?? $variables['LOAI_SAN_PHAM'] ?? ''));
        if ($loaiSanPham === '' && $task !== null) {
            $loaiSanPham = trim((string) ($task->loai_san_pham ?? ''));
        }
        if ($loaiSanPham !== '') {
            $lines[] = 'Loại sản phẩm: '.$loaiSanPham;
        }

        return implode("\n", $lines);
    }

    /**
     * Stamp variables['input'] for project-task contexts (does not touch Improve body input).
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public static function stamp(array $variables, string $projectTaskType, ?SeoProjectTask $task = null): array
    {
        $type = SeoProjectTask::normalizeType($projectTaskType);

        if ($type === SeoProjectTask::TYPE_IMPROVE) {
            return $variables;
        }

        if ($type === SeoProjectTask::TYPE_REWRITE) {
            $input = self::forRewrite($variables);
        } else {
            $input = self::forCreate($variables, $task);
        }

        if ($input !== '') {
            $variables['input'] = $input;
        }

        return $variables;
    }
}
