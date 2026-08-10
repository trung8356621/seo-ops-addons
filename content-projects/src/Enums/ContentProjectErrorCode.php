<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Enums;

enum ContentProjectErrorCode: string
{
    case TaskNotFound = 'CONTENT_PROJECT_TASK_NOT_FOUND';
    case TaskDeleted = 'CONTENT_PROJECT_TASK_DELETED';
    case TaskArchived = 'CONTENT_PROJECT_TASK_ARCHIVED';
    case TaskAlreadyArchived = 'CONTENT_PROJECT_TASK_ALREADY_ARCHIVED';
    case TaskNotArchived = 'CONTENT_PROJECT_TASK_NOT_ARCHIVED';
    case TaskCancelled = 'CONTENT_PROJECT_TASK_CANCELLED';
    case OperationAlreadyProcessing = 'CONTENT_PROJECT_OPERATION_ALREADY_PROCESSING';
    case OperationAlreadyProcessed = 'CONTENT_PROJECT_OPERATION_ALREADY_PROCESSED';
    case ArticleRelationMissing = 'CONTENT_PROJECT_ARTICLE_RELATION_MISSING';
    case ArticleRelationConflict = 'CONTENT_PROJECT_ARTICLE_RELATION_CONFLICT';
    case ArticleAlreadyLinked = 'CONTENT_PROJECT_ARTICLE_ALREADY_LINKED';
    case RunItemNotFound = 'CONTENT_PROJECT_RUN_ITEM_NOT_FOUND';
    case ExternalWorkflowFailed = 'CONTENT_PROJECT_EXTERNAL_WORKFLOW_FAILED';
    case ArchiveMirrorFailed = 'CONTENT_PROJECT_ARCHIVE_MIRROR_FAILED';
    case ArchiveStateMismatch = 'CONTENT_PROJECT_ARCHIVE_STATE_MISMATCH';
    case ArchiveTaskAmbiguous = 'CONTENT_PROJECT_ARCHIVE_TASK_AMBIGUOUS';
    case SyncTaskNotFound = 'CONTENT_PROJECT_SYNC_TASK_NOT_FOUND';
    case SyncTaskDeleted = 'CONTENT_PROJECT_SYNC_TASK_DELETED';
    case SyncTaskArchived = 'CONTENT_PROJECT_SYNC_TASK_ARCHIVED';
    case SyncTaskProjectMismatch = 'CONTENT_PROJECT_SYNC_TASK_PROJECT_MISMATCH';
    case SyncDuplicateIdentity = 'CONTENT_PROJECT_SYNC_DUPLICATE_IDENTITY';
    case SyncDuplicateInput = 'CONTENT_PROJECT_SYNC_DUPLICATE_INPUT';
    case SyncArticleIdentityConflict = 'CONTENT_PROJECT_SYNC_ARTICLE_IDENTITY_CONFLICT';
    case TaskSourceKeyConflict = 'CONTENT_PROJECT_TASK_SOURCE_KEY_CONFLICT';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $code): string => $code->value, self::cases());
    }
}
