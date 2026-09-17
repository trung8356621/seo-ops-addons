<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicKeywordSource;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicStatus;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;

/**
 * Create / reuse a site-scoped manual Topic with a surviving seed membership.
 */
final class TopicManualCreateService
{
    public function __construct(
        private readonly KeywordPersistenceService $keywords,
        private readonly TopicSiteKeywordService $siteKeywords,
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

        $keyword = $this->keywords->upsert($name, 'internal', $siteId, null);
        if (! $keyword instanceof Keyword) {
            return $this->fail('keyword_upsert_failed');
        }
        $this->siteKeywords->upsertClassification($siteId, $keyword, TopicKeywordSource::MANUAL);
        $keywordId = (int) $keyword->id;

        $created = DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $name, $keywordId): array {
            $existingSeed = SeoTopicKeyword::query()
                ->where('site_id', $siteId)
                ->where('keyword_id', $keywordId)
                ->where('is_seed', true)
                ->first();
            if ($existingSeed instanceof SeoTopicKeyword) {
                $topic = SeoTopic::query()
                    ->where('site_id', $siteId)
                    ->where('id', (int) $existingSeed->topic_id)
                    ->first();
                if ($topic instanceof SeoTopic) {
                    return [
                        'ok' => true,
                        'error' => null,
                        'topic_id' => (int) $topic->id,
                        'topic_name' => (string) $topic->name,
                        'reused' => true,
                    ];
                }
            }

            $existingByName = SeoTopic::query()
                ->where('site_id', $siteId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();
            if ($existingByName instanceof SeoTopic) {
                $this->ensureManualSeed($siteId, (int) $existingByName->id, $keywordId);

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
                'status' => TopicStatus::ACTIVE,
                'is_locked' => false,
            ]);
            $topicId = (int) $topic->id;
            $this->ensureManualSeed($siteId, $topicId, $keywordId);

            return [
                'ok' => true,
                'error' => null,
                'topic_id' => $topicId,
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

    private function ensureManualSeed(int $siteId, int $topicId, int $keywordId): void
    {
        SeoTopicKeyword::query()->updateOrCreate(
            [
                'site_id' => $siteId,
                'topic_id' => $topicId,
                'keyword_id' => $keywordId,
            ],
            [
                'source' => TopicKeywordSource::MANUAL,
                'is_seed' => true,
                'is_locked' => false,
                'confidence' => 1.0,
            ],
        );
    }
}
