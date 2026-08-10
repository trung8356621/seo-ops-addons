<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events;

use Omnichannel\Addons\Agent\Extension\ExtensionEventBus;
use Omnichannel\Addons\Agent\Extension\ExtensionEventEnvelope;
use Omnichannel\Addons\Agent\Extension\ExtensionEvents;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Dispatch domain events after DB commit khi có transaction; nếu không thì ngay.
 * Bridge ExtensionEventBus — envelope versioned/compact, không serialize Eloquent.
 */
final class ContentProjectDomainEvents
{
    public function __construct(
        private readonly ExtensionEventBus $extensionEvents,
    ) {}

    public function dispatchAfterCommit(object $event): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(function () use ($event): void {
                $this->dispatchNow($event);
            });

            return;
        }

        $this->dispatchNow($event);
    }

    private function dispatchNow(object $event): void
    {
        event($event);
        $this->bridgeToExtensionBus($event);
    }

    private function bridgeToExtensionBus(object $event): void
    {
        try {
            $envelope = $this->buildEnvelope($event);
            if ($envelope === null) {
                return;
            }

            $this->extensionEvents->dispatch((string) $envelope['event_name'], $envelope);
        } catch (Throwable) {
            // Extension bus never breaks domain path
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildEnvelope(object $event): ?array
    {
        return match (true) {
            $event instanceof ContentProjectCreated => ExtensionEventEnvelope::make(
                ExtensionEvents::PROJECT_CREATED,
                payload: [
                    'site_id' => $event->siteId,
                ],
                siteRef: $event->siteId > 0 ? 'site:'.$event->siteId : null,
                projectRef: ContentProjectPublicRef::project($event->projectId),
                actorType: $event->actorId !== null ? 'user' : 'system',
            ),
            $event instanceof ContentProjectGenerationRequested => ExtensionEventEnvelope::make(
                ExtensionEvents::ITEMS_GENERATED,
                payload: [
                    'item_count' => count($event->itemIds ?? []),
                ],
                projectRef: ContentProjectPublicRef::project($event->projectId),
            ),
            $event instanceof ArticlePublished => ExtensionEventEnvelope::make(
                ExtensionEvents::PUBLISHED,
                payload: [
                    'has_external_id' => $event->wpPostId > 0,
                ],
                projectRef: ContentProjectPublicRef::project($event->projectId),
                itemRef: ContentProjectPublicRef::item($event->itemId),
            ),
            $event instanceof ContentProjectArchived => ExtensionEventEnvelope::make(
                ExtensionEvents::ARCHIVED,
                payload: [
                    'archive_id' => $event->archiveId,
                ],
                projectRef: ContentProjectPublicRef::project($event->projectId),
                actorType: $event->actorId !== null ? 'user' : 'system',
            ),
            default => null,
        };
    }
}
