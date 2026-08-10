<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Observers;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;

/**
 * Observer chỉ invariant — không business notify/WP/AI.
 * Notification đi qua Automation Engine (notification.send).
 */
final class SeoProjectObserver
{
    public bool $afterCommit = true;

    public function created(SeoProject $project): void
    {
        // No automatic business side effects.
    }

    public function updated(SeoProject $project): void
    {
        // No automatic business side effects.
    }
}
