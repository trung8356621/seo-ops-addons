<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Enums;

/**
 * Business lifecycle phase của Content Project Item (UI / guard).
 */
enum ContentProjectLifecyclePhase: string
{
    case Draft = 'draft';
    case Generating = 'generating';
    case Review = 'review';
    case Approved = 'approved';
    case WaitingPublish = 'waiting_publish';
    case Published = 'published';
    case Failed = 'failed';
    case Archived = 'archived';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Generating, self::Failed, self::Archived],
            self::Generating => [self::Review, self::Failed, self::Draft, self::Archived],
            self::Review => [self::Approved, self::Generating, self::Failed, self::Archived],
            self::Approved => [self::WaitingPublish, self::Published, self::Archived],
            self::WaitingPublish => [self::Published, self::Failed, self::Approved, self::Archived],
            self::Published => [self::Archived],
            self::Failed => [self::Draft, self::Generating, self::WaitingPublish, self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }
}
