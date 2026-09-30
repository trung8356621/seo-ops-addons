<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use InvalidArgumentException;

/**
 * Stable Service API / capability adapter for Topic mutations.
 * Serves as the cross-addon boundary between external tools and internal Topic operations.
 */
class TopicServiceApiWriteService
{
    public const SCOPE = 'topics:write';

    public function __construct(
        private readonly TopicManualCreateService $manualCreateService,
    ) {}

    /**
     * Create or reuse a site-scoped manual topic without synthesizing a Keyword.
     *
     * @return array{
     *     ok: bool,
     *     error: ?string,
     *     data?: array{
     *         topic_ref: string,
     *         topic_id: int,
     *         topic_name: string,
     *         reused: bool,
     *         reconcile: array{
     *             checked: int,
     *             matched: int,
     *             attached: int,
     *             moved: int,
     *             skipped_locked: int,
     *             skipped_seed: int
     *         }|null
     *     }
     * }
     */
    public function create(int $siteId, string $name): array
    {
        $cleanName = trim($name);
        if ($cleanName === '' || mb_strlen($cleanName) > 255) {
            throw new InvalidArgumentException('Topic name must be a non-empty string under 255 characters.');
        }

        if ($siteId <= 0) {
            throw new InvalidArgumentException('Valid site_id is required.');
        }

        $res = $this->manualCreateService->create($siteId, $cleanName);

        if (! ($res['ok'] ?? false)) {
            $errorCode = match ($res['error'] ?? null) {
                'invalid_args' => 'invalid_topic_name',
                'topic_locked' => 'topic_locked',
                'topic_tables_missing' => 'topic_create_failed',
                default => 'topic_create_failed',
            };

            return [
                'ok' => false,
                'error' => $errorCode,
            ];
        }

        $topicId = (int) $res['topic_id'];
        $topicName = (string) ($res['topic_name'] ?? $cleanName);
        $reused = (bool) ($res['reused'] ?? false);
        $reconcile = $res['reconcile'] ?? null;

        return [
            'ok' => true,
            'error' => null,
            'data' => [
                'topic_ref' => 'topic:'.$topicId,
                'topic_id' => $topicId,
                'topic_name' => $topicName,
                'reused' => $reused,
                'reconcile' => $reconcile,
            ],
        ];
    }
}
