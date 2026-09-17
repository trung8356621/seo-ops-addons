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
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     error: ?string,
     *     topic_id: int|null,
     *     topic_name: string|null,
     *     reused: bool
     * }
     */
    public function create(int $siteId, string $phrase): array
    {
        $name = TopicNaming::canonicalName($phrase);
        if ($siteId <= 0 || $name === '') {
            return ['ok' => false, 'error' => 'invalid_args', 'topic_id' => null, 'topic_name' => null, 'reused' => false];
        }
        if (! TopicReclusterService::tablesReady()) {
            return ['ok' => false, 'error' => 'topic_tables_missing', 'topic_id' => null, 'topic_name' => null, 'reused' => false];
        }

        $keyword = $this->keywords->upsert($name, 'internal', $siteId, null);
        if (! $keyword instanceof Keyword) {
            return ['ok' => false, 'error' => 'keyword_upsert_failed', 'topic_id' => null, 'topic_name' => null, 'reused' => false];
        }
        $this->siteKeywords->upsertClassification($siteId, $keyword, TopicKeywordSource::MANUAL);
        $keywordId = (int) $keyword->id;

        return DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $name, $keywordId): array {
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
