<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTaskEvent;

/**
 * Marker: this task must stay UNLINKED until the user explicitly links or creates.
 * Auto-reconcile / title / slug / run-item / WP id fallbacks must not reattach.
 */
final class ContentProjectManualArticleResolution
{
    public const EVENT = 'legacy.article_association_corrupted';

    public const REASON = 'legacy_article_association_corrupted';

    public const MANUAL_ACTION = 'article_link_requires_manual_resolution';

    /** @var array<int, bool> */
    private static array $cache = [];

    public static function requiresManualResolution(SeoProjectTask $task): bool
    {
        $taskId = (int) $task->getKey();
        if ($taskId <= 0) {
            return false;
        }
        if (array_key_exists($taskId, self::$cache)) {
            return self::$cache[$taskId];
        }

        try {
            self::$cache[$taskId] = SeoProjectTaskEvent::query()
                ->where('task_id', $taskId)
                ->whereIn('event', [self::EVENT, 'legacy.recovery.detach'])
                ->exists();
        } catch (\Throwable) {
            self::$cache[$taskId] = false;
        }

        return self::$cache[$taskId];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function mark(int $taskId, array $payload = []): void
    {
        if ($taskId <= 0) {
            return;
        }

        SeoProjectTaskEvent::query()->create([
            'task_id' => $taskId,
            'event' => self::EVENT,
            'payload' => array_merge([
                'reason' => self::REASON,
                'manual_action' => self::MANUAL_ACTION,
                'auto_reconcile' => false,
                'create_article' => false,
            ], $payload),
            'created_at' => now(),
        ]);
        self::$cache[$taskId] = true;
    }

    public static function forgetCache(): void
    {
        self::$cache = [];
    }
}
