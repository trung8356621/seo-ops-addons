<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support;

/**
 * Chuẩn hoá idempotency key — không log raw key ra ngoài.
 */
final class ContentProjectIdempotencyKeyFactory
{
    public static function filament(int $actorId, string $action, int|string $projectId, string $requestToken): string
    {
        return sprintf(
            'ui:%d:%s:%s:%s',
            $actorId,
            trim($action),
            (string) $projectId,
            trim($requestToken),
        );
    }

    public static function queue(string $jobUuid, string $action, int|string $itemId): string
    {
        return sprintf(
            'queue:%s:%s:%s',
            trim($jobUuid),
            trim($action),
            (string) $itemId,
        );
    }

    public static function scheduler(int|string $itemId, string $scheduledAtIso): string
    {
        return sprintf(
            'scheduler:%s:%s',
            (string) $itemId,
            trim($scheduledAtIso),
        );
    }

    public static function hashForLog(string $rawKey): string
    {
        return substr(hash('sha256', $rawKey), 0, 16);
    }
}
