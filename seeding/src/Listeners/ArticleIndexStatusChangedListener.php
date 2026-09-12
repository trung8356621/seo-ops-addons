<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Listeners;

use App\Core\Event\ArticleIndexStatusChanged;
use App\Core\Event\Contracts\DomainEvent;
use App\Core\Event\Contracts\EventListener;
use Omnichannel\Addons\Seeding\Services\WebsiteShareJobService;
use Throwable;

final class ArticleIndexStatusChangedListener implements EventListener
{
    public function __construct(
        private readonly WebsiteShareJobService $websiteShare,
    ) {}

    public function handle(DomainEvent $event): void
    {
        if (! $event instanceof ArticleIndexStatusChanged) {
            return;
        }

        try {
            $this->websiteShare->handleIndexStatusChanged($event);
        } catch (Throwable) {
            // Never break Content index mark path.
        }
    }
}
