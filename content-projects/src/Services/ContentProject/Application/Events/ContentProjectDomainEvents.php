<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events;

use Illuminate\Support\Facades\DB;

/**
 * Dispatch domain events after DB commit khi có transaction; nếu không thì ngay.
 */
final class ContentProjectDomainEvents
{
    public function dispatchAfterCommit(object $event): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(function () use ($event): void {
                event($event);
            });

            return;
        }

        event($event);
    }
}
