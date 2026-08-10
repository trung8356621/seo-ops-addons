<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Enums;

/**
 * remote_update reserved — chỉ dùng khi runtime update không đổi WP publish status.
 */
enum PublishIntent: string
{
    case ManualPublish = 'manual_publish';
    case ScheduledPublish = 'scheduled_publish';
    case Republish = 'republish';
    case RemoteUpdate = 'remote_update';

    public function allowsArticlePublishAction(): bool
    {
        return match ($this) {
            self::ManualPublish, self::ScheduledPublish, self::Republish => true,
            self::RemoteUpdate => false,
        };
    }
}
