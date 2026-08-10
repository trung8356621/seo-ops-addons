<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Enums;

/**
 * Operation codes ghi vào seo_project_run_items.action.
 * Không lẫn với event đã xảy ra (xem SeoProjectTaskEventType).
 */
enum SeoProjectRunAction: string
{
    case ArticleCreate = 'article.create';
    case ArticleUpdate = 'article.update';
    case ArticleRewrite = 'article.rewrite';
    case ArticleArchive = 'article.archive';
    case ArticleRestore = 'article.restore';
    case TaskRetry = 'task.retry';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $action): string => $action->value, self::cases());
    }

    /**
     * Map legacy task type / run item type sang action (skeleton — chưa dùng write path).
     */
    public static function fromLegacyTaskType(?string $type): self
    {
        return match (trim((string) $type)) {
            'create' => self::ArticleCreate,
            'rewrite' => self::ArticleRewrite,
            'improve' => self::ArticleRewrite,
            'new_keyword', 'new_title' => self::ArticleCreate,
            default => self::ArticleCreate,
        };
    }
}
