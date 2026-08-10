<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Enums;

/**
 * Core business event names. Module registry có thể đăng ký thêm ngoài enum này.
 */
enum BusinessEventName: string
{
    case ArticleCreated = 'article.created';
    case ArticleContentUpdated = 'article.content_updated';
    case ArticleCompleted = 'article.completed';
    case ArticleArchived = 'article.archived';
    case ArticleRestored = 'article.restored';
    case ArticleDeleted = 'article.deleted';
    case ArticlePublishRequested = 'article.publish_requested';
    case ArticleProductReviewsGenerated = 'article.product_reviews_generated';
    case ArticleProductReviewPublishRequested = 'article.product_review_publish_requested';

    case ContentProjectTaskCreated = 'content_project.task.created';
    case ContentProjectTaskUpdated = 'content_project.task.updated';
    case ContentProjectTaskCompleted = 'content_project.task.completed';
    case ContentProjectTaskFailed = 'content_project.task.failed';
    case ContentProjectTaskArchived = 'content_project.task.archived';

    case ContentProjectRunStarted = 'content_project.run.started';
    case ContentProjectRunCompleted = 'content_project.run.completed';
    case ContentProjectRunFailed = 'content_project.run.failed';

    case WordpressSyncRequested = 'wordpress.sync_requested';
    case WordpressSyncStarted = 'wordpress.sync_started';
    case WordpressSynced = 'wordpress.synced';
    case WordpressSyncFailed = 'wordpress.sync_failed';
    case WordpressPostDeleted = 'wordpress.post_deleted';
    case WordpressCommentReviewPublished = 'wordpress.comment_review_published';
    case WordpressCommentReviewPublishFailed = 'wordpress.comment_review_publish_failed';

    case MediaUploaded = 'media.uploaded';
    case MediaProcessed = 'media.processed';
    case MediaFailed = 'media.failed';

    case SeoAnalysisStarted = 'seo.analysis_started';
    case SeoAnalysisCompleted = 'seo.analysis_completed';
    case SeoAnalysisFailed = 'seo.analysis_failed';

    case NotificationRequested = 'notification.requested';

    case KeywordSaved = 'keyword.saved';
    case ArticleApproved = 'article.approved';
    case ArticleSeoMetaUpdated = 'article.seo_meta_updated';

    case ScheduleTriggered = 'automation.schedule.triggered';
    case ManualActionRequested = 'automation.manual_action_requested';
}
