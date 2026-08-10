<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Services\SideEffect\UnauthorizedWordPressSideEffectException;
use Omnichannel\Addons\WordPress\Services\SideEffect\WordPressExecutionContext;

/**
 * FAQ sync wrapper — requires explicit WordPressExecutionContext (manual or automation).
 */
final class WordPressFaqSyncService
{
    public function syncForArticle(SeoArticle $article, WordPressExecutionContext $sideEffect): bool
    {
        if ($sideEffect->origin() !== 'automation') {
            throw new UnauthorizedWordPressSideEffectException(
                UnauthorizedWordPressSideEffectException::ORIGIN_INVALID,
                'FAQ sync requires AutomationWordPressContext.',
            );
        }

        $result = app(WordPressArticleSyncService::class)->syncForArticle($article, $sideEffect);

        return (bool) ($result['success'] ?? false);
    }
}
