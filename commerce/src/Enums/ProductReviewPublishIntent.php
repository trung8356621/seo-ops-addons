<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Enums;

enum ProductReviewPublishIntent: string
{
    case GeneratedReview = 'generated_review';
    case ManualPublish = 'manual_publish';
    case RetryFailed = 'retry_failed';
    case PublishAfterArticle = 'publish_after_article';
}
