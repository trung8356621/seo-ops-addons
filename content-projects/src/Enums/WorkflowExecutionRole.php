<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Enums;

/**
 * Semantic execution role trên workflow node (node.data.execution_role).
 * SoT cho runtime lookup — không heuristic title.
 */
enum WorkflowExecutionRole: string
{
    case ArticleOutlineGenerate = 'article.outline.generate';
    case ArticleContentGenerate = 'article.content.generate';
    case ArticleContentImprove = 'article.content.improve';
    case ArticleImageGenerate = 'article.image.generate';

    public function labelVi(): string
    {
        return match ($this) {
            self::ArticleOutlineGenerate => 'Tạo dàn ý',
            self::ArticleContentGenerate => 'Viết bài',
            self::ArticleContentImprove => 'Cải thiện bài viết',
            self::ArticleImageGenerate => 'Tạo hình ảnh',
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::ArticleOutlineGenerate => 'Generate outline',
            self::ArticleContentGenerate => 'Generate article content',
            self::ArticleContentImprove => 'Improve article content',
            self::ArticleImageGenerate => 'Generate image',
        };
    }

    /**
     * Catalog kind tương thích bước rerun cũ.
     */
    public function catalogKind(): string
    {
        return match ($this) {
            self::ArticleOutlineGenerate => 'outline',
            self::ArticleContentGenerate => 'content',
            self::ArticleContentImprove => 'improve',
            self::ArticleImageGenerate => 'image',
        };
    }

    public static function tryFromMixed(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        $raw = trim((string) $value);

        return $raw === '' ? null : self::tryFrom($raw);
    }
}
