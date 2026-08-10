<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Enums;

/**
 * Core action codes. Handler resolve qua AutomationActionRegistry, không lưu class trong DB.
 */
enum AutomationActionCode: string
{
    case WordpressArticleSync = 'wordpress.article.sync';
    case WordpressCommentReviewPublish = 'wordpress.comment_review.publish';
    case ProductReviewCreate = 'product-review.create';
    case ProductReviewSyncWp = 'product-review.sync-wp';
    case ArticleProductReviewsQueuePending = 'article.product_reviews.queue_pending';
    case ArticleProductReviewsScheduleGenerated = 'article.product_reviews.schedule_generated';
    case ArticleGenerateContent = 'article.generate_content';
    case ArticleRunSeoAnalysis = 'article.run_seo_analysis';
    case KeywordDomainLinkListSync = 'keyword.domain_link_list.sync';
    case WebhookSend = 'webhook.send';
    case NotificationSend = 'notification.send';
    case Delay = 'delay';
    case AutomationDispatchEvent = 'automation.dispatch_event';
}
