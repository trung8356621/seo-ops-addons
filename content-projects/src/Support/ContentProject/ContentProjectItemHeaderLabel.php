<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;

/**
 * Reactive Content Project item repeater header label.
 *
 * Rewrite: [Rewrite] ({keyword}) {source title}
 * Create:  [Post]|[Product] ({keyword}) {title}
 */
final class ContentProjectItemHeaderLabel
{
    /**
     * @param  array<string, mixed>  $state
     */
    public static function fromState(array $state): string
    {
        $type = SeoProjectTask::normalizeType($state['type'] ?? SeoProjectTask::TYPE_CREATE);
        $keyword = trim((string) ($state['keyword'] ?? ''));
        $title = trim((string) ($state['title'] ?? ''));
        $sourceTitle = trim((string) ($state['source_content'] ?? ''));

        if ($type === SeoProjectTask::TYPE_IMPROVE) {
            return self::compose('[Improve]', $keyword, $sourceTitle !== '' ? $sourceTitle : $title);
        }

        if ($type === SeoProjectTask::TYPE_REWRITE) {
            return self::compose('[Rewrite]', $keyword, $sourceTitle !== '' ? $sourceTitle : $title);
        }

        $postType = SeoProjectTask::normalizePostType($state['post_type'] ?? null);
        $prefix = match ($postType) {
            SeoProjectTask::POST_TYPE_PRODUCT => '[Product]',
            SeoProjectTask::POST_TYPE_CATEGORY => '[Category]',
            SeoProjectTask::POST_TYPE_PRODUCT_CATEGORY => '[Product Category]',
            default => '[Post]',
        };

        return self::compose($prefix, $keyword, $title);
    }

    private static function compose(string $prefix, string $keyword, string $title): string
    {
        $keywordPart = $keyword !== '' ? '('.$keyword.')' : '';
        $parts = array_values(array_filter([$prefix, $keywordPart, $title], static fn (string $p): bool => $p !== ''));

        return implode(' ', $parts);
    }
}
