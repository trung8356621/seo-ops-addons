<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicSource;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicStatus;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;

/**
 * Create / reuse a site-scoped manual Topic without synthesizing a Keyword row.
 */
final class TopicManualCreateService
{
    public function __construct(
        private readonly TopicMembershipReconcileService $reconcile,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     error: ?string,
     *     topic_id: int|null,
     *     topic_name: string|null,
     *     reused: bool,
     *     reconcile: array{
     *         checked: int,
     *         matched: int,
     *         attached: int,
     *         moved: int,
     *         skipped_locked: int,
     *         skipped_seed: int
     *     }|null
     * }
     */
    public function create(int $siteId, string $phrase): array
    {
        $name = TopicNaming::canonicalName($phrase);
        if ($siteId <= 0 || $name === '') {
            return $this->fail('invalid_args');
        }
        if (! TopicReclusterService::tablesReady()) {
            return $this->fail('topic_tables_missing');
        }

        $created = DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $name): array {
            $existingByName = SeoTopic::query()
                ->where('site_id', $siteId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();
            if ($existingByName instanceof SeoTopic) {
                if (! $existingByName->isManual()) {
                    $existingByName->source = TopicSource::MANUAL;
                    $existingByName->save();
                }

                return [
                    'ok' => true,
                    'error' => null,
                    'topic_id' => (int) $existingByName->id,
                    'topic_name' => (string) $existingByName->name,
                    'reused' => true,
                ];
            }

            $topic = SeoTopic::query()->create([
                'site_id' => $siteId,
                'name' => $name,
                'source' => TopicSource::MANUAL,
                'status' => TopicStatus::ACTIVE,
                'is_locked' => false,
            ]);

            return [
                'ok' => true,
                'error' => null,
                'topic_id' => (int) $topic->id,
                'topic_name' => (string) $topic->name,
                'reused' => false,
            ];
        });

        if (! ($created['ok'] ?? false) || (int) ($created['topic_id'] ?? 0) <= 0) {
            return array_merge($this->fail((string) ($created['error'] ?? 'create_failed')), [
                'reconcile' => null,
            ]);
        }

        $metrics = $this->reconcile->reconcile($siteId, (int) $created['topic_id']);

        return [
            'ok' => true,
            'error' => null,
            'topic_id' => (int) $created['topic_id'],
            'topic_name' => (string) ($created['topic_name'] ?? $name),
            'reused' => (bool) ($created['reused'] ?? false),
            'reconcile' => [
                'checked' => (int) ($metrics['checked'] ?? 0),
                'matched' => (int) ($metrics['matched'] ?? 0),
                'attached' => (int) ($metrics['attached'] ?? 0),
                'moved' => (int) ($metrics['moved'] ?? 0),
                'skipped_locked' => (int) ($metrics['skipped_locked'] ?? 0),
                'skipped_seed' => (int) ($metrics['skipped_seed'] ?? 0),
            ],
        ];
    }

    /**
     * @return array{
     *     ok: bool,
     *     error: ?string,
     *     topic_id: int|null,
     *     topic_name: string|null,
     *     reused: bool,
     *     reconcile: null
     * }
     */
    private function fail(string $error): array
    {
        return [
            'ok' => false,
            'error' => $error,
            'topic_id' => null,
            'topic_name' => null,
            'reused' => false,
            'reconcile' => null,
        ];
    }
}
