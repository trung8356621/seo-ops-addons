<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Services\Publishing;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;

final class ContentPublishingStrategyResolver
{
    public const UPDATE_EXISTING_TYPES = [
        SeoProjectTask::TYPE_REWRITE,
        SeoProjectTask::TYPE_IMPROVE,
    ];

    public function resolve(SeoProjectTask $task, ?SeoArticle $article = null): ContentPublishingStrategy
    {
        $type = SeoProjectTask::normalizeType($task->type ?? null);
        if (! in_array($type, self::UPDATE_EXISTING_TYPES, true)) {
            return new ContentPublishingStrategy(ContentPublishingStrategy::SCHEDULED_CREATE);
        }

        $article = $article ?? ($task->relationLoaded('article') ? $task->article : null);
        $remotePostId = (int) ($article?->wordpressLink?->wp_post_id ?? 0);
        if ($remotePostId <= 0) {
            return new ContentPublishingStrategy(
                ContentPublishingStrategy::FAILED_MISSING_REMOTE,
                null,
                'Khong tim thay bai WordPress goc de cap nhat.',
            );
        }

        return new ContentPublishingStrategy(ContentPublishingStrategy::IMMEDIATE_UPDATE, $remotePostId);
    }
}
