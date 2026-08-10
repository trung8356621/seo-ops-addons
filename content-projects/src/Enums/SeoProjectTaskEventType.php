<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Enums;

/**
 * Event codes ghi vào seo_project_task_events.event (audit only).
 */
enum SeoProjectTaskEventType: string
{
    case TaskCreated = 'task.created';
    case TaskUpdated = 'task.updated';
    case TaskProcessing = 'task.processing';
    case TaskCompleted = 'task.completed';
    case TaskFailed = 'task.failed';
    case TaskArchived = 'task.archived';
    case TaskRestored = 'task.restored';
    case TaskDeleted = 'task.deleted';
    case TaskMerged = 'task.merged';
    case TaskCancelled = 'task.cancelled';
    case TaskReactivated = 'task.reactivated';

    case ArticleCreated = 'article.created';
    case ArticleLinked = 'article.linked';
    case ArticleRelationMissing = 'article.relation_missing';
    case ArticleArchive = 'article.archive';
    case ArticleRestore = 'article.restore';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $event): string => $event->value, self::cases());
    }
}
