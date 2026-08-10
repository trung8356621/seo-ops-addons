<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Application\Publishing;

use Illuminate\Support\Str;

/**
 * Neutral attempt/external refs — Application không phụ thuộc WordPress class.
 */
final class PublishAttemptRefs
{
    public static function forArticle(int $articleId): string
    {
        return 'omi_seo_article_'.$articleId;
    }

    public static function newAttemptRef(): string
    {
        return 'cpa_'.Str::lower((string) Str::ulid());
    }
}
