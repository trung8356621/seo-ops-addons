<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use Omnichannel\Addons\Media\Models\SeoMedia;

/**
 * Ngữ cảnh gắn seo_media khi lưu kết quả prompt (test / workflow).
 */
final class PromptMediaPersistContext
{
    public static ?int $siteId = null;

    public static ?int $articleId = null;

    public static ?int $promptId = null;

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function using(?int $siteId, ?int $articleId, ?int $promptId, callable $callback): mixed
    {
        $previousSite = self::$siteId;
        $previousArticle = self::$articleId;
        $previousPrompt = self::$promptId;

        self::$siteId = $siteId > 0 ? $siteId : null;
        self::$articleId = $articleId > 0 ? $articleId : null;
        self::$promptId = $promptId > 0 ? $promptId : null;

        try {
            return $callback();
        } finally {
            self::$siteId = $previousSite;
            self::$articleId = $previousArticle;
            self::$promptId = $previousPrompt;
        }
    }

    /**
     * @return array<string, int|list<int>|null>
     */
    public static function attributesForNewRecord(): array
    {
        return [
            'site_id' => self::$siteId,
            'article_id' => self::$articleId !== null ? [self::$articleId] : null,
            'prompt_id' => self::$promptId,
        ];
    }

    /**
     * @return array<string, int>
     */
    public static function fillMissingOnMedia(SeoMedia $media): array
    {
        $updates = [];

        if (self::$siteId > 0 && (int) ($media->site_id ?? 0) <= 0) {
            $updates['site_id'] = self::$siteId;
        }

        if (self::$articleId > 0) {
            $existing = SeoMedia::normalizeArticleIds($media->article_id);
            if (! in_array(self::$articleId, $existing, true)) {
                $existing[] = self::$articleId;
                $updates['article_id'] = array_values(array_unique($existing));
            }
        }

        if (self::$promptId > 0 && (int) ($media->prompt_id ?? 0) <= 0) {
            $updates['prompt_id'] = self::$promptId;
        }

        return $updates;
    }
}
