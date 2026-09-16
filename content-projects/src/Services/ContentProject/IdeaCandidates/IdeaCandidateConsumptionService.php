<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectConsumedIdea;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ConsumeVocabularySuggestCandidateService;
use RuntimeException;
use Throwable;

/**
 * Claim + tombstone Available Ideas (content-projects owned).
 * Tombstone is SSOT for "already used once"; physical source cleanup is best-effort.
 */
final class IdeaCandidateConsumptionService
{
    public const ERROR_SCHEMA_NOT_READY = 'idea_consumption_schema_not_ready';

    public function __construct(
        private readonly ConsumeVocabularySuggestCandidateService $sourceCleanup,
    ) {}

    public function isConsumed(int $siteId, string $sourceType, int $sourceKeywordId): bool
    {
        if ($siteId <= 0 || $sourceKeywordId <= 0 || ! $this->tableReady()) {
            return false;
        }

        return SeoContentProjectConsumedIdea::query()
            ->where('site_id', $siteId)
            ->where('source_type', $sourceType)
            ->where('source_ref', SeoContentProjectConsumedIdea::sourceRefForKeyword($sourceKeywordId))
            ->exists();
    }

    /**
     * @param  list<int>  $keywordIds
     * @return array<int, true> keyword_id => true when consumed
     */
    public function consumedKeywordIdMap(int $siteId, string $sourceType, array $keywordIds): array
    {
        $keywordIds = array_values(array_unique(array_filter(array_map('intval', $keywordIds))));
        if ($siteId <= 0 || $keywordIds === [] || ! $this->tableReady()) {
            return [];
        }

        $refs = array_map(
            static fn (int $id): string => SeoContentProjectConsumedIdea::sourceRefForKeyword($id),
            $keywordIds,
        );

        $rows = SeoContentProjectConsumedIdea::query()
            ->where('site_id', $siteId)
            ->where('source_type', $sourceType)
            ->whereIn('source_ref', $refs)
            ->get(['source_ref', 'source_keyword_id']);

        $out = [];
        foreach ($rows as $row) {
            $kid = (int) ($row->source_keyword_id ?? 0);
            if ($kid <= 0) {
                $kid = (int) ($row->source_ref ?? 0);
            }
            if ($kid > 0) {
                $out[$kid] = true;
            }
        }

        return $out;
    }

    /**
     * Atomically claim a candidate. Returns false when already consumed (idempotent reject).
     * Throws when the tombstone schema is missing — write path must not look like a duplicate.
     *
     * @return array{claimed: bool, consumed_idea_id: int|null}
     */
    public function claim(
        int $siteId,
        string $sourceType,
        int $sourceKeywordId,
        string $phraseSnapshot,
        ?int $sourceArticleId = null,
        ?string $vocabularyGroup = null,
    ): array {
        if ($siteId <= 0 || $sourceKeywordId <= 0) {
            return ['claimed' => false, 'consumed_idea_id' => null];
        }

        if (! $this->tableReady()) {
            Log::error(self::ERROR_SCHEMA_NOT_READY, [
                'site_id' => $siteId,
                'source_type' => trim($sourceType),
                'source_ref' => SeoContentProjectConsumedIdea::sourceRefForKeyword($sourceKeywordId),
            ]);

            throw new RuntimeException(self::ERROR_SCHEMA_NOT_READY);
        }

        $sourceType = trim($sourceType) !== '' ? trim($sourceType) : SeoContentProjectItemOrigin::SOURCE_VOCABULARY_SUGGEST;
        $ref = SeoContentProjectConsumedIdea::sourceRefForKeyword($sourceKeywordId);

        try {
            $row = SeoContentProjectConsumedIdea::query()->create([
                'site_id' => $siteId,
                'source_type' => $sourceType,
                'source_ref' => $ref,
                'source_keyword_id' => $sourceKeywordId,
                'phrase_snapshot' => mb_substr(trim($phraseSnapshot), 0, 500) ?: null,
                'source_article_id' => ($sourceArticleId !== null && $sourceArticleId > 0) ? $sourceArticleId : null,
                'vocabulary_group' => ($vocabularyGroup !== null && trim($vocabularyGroup) !== '')
                    ? trim($vocabularyGroup)
                    : null,
                'project_task_id' => null,
                'consumed_at' => now(),
            ]);

            return [
                'claimed' => true,
                'consumed_idea_id' => (int) $row->getKey(),
            ];
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return ['claimed' => false, 'consumed_idea_id' => null];
            }

            throw $e;
        }
    }

    public function attachTask(int $consumedIdeaId, int $taskId): void
    {
        if ($consumedIdeaId <= 0 || $taskId <= 0 || ! $this->tableReady()) {
            return;
        }

        SeoContentProjectConsumedIdea::query()
            ->whereKey($consumedIdeaId)
            ->whereNull('project_task_id')
            ->update(['project_task_id' => $taskId]);
    }

    /**
     * Best-effort physical cleanup of Vocabulary Suggest source. Tombstone already guarantees exclusion.
     *
     * @return array<string, mixed>|null
     */
    public function cleanupVocabularySuggestSource(int $keywordId, int $siteId): ?array
    {
        if ($keywordId <= 0 || $siteId <= 0) {
            return null;
        }

        try {
            return $this->sourceCleanup->consume($keywordId, $siteId);
        } catch (Throwable $e) {
            Log::warning('idea_candidate_source_cleanup_failed', [
                'keyword_id' => $keywordId,
                'site_id' => $siteId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function tableReady(): bool
    {
        return Schema::connection('omi_seo_ai')->hasTable('seo_content_project_consumed_ideas');
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $message = strtolower($e->getMessage());

        return $code === '23000'
            || $driverCode === 1062
            || str_contains($message, 'duplicate')
            || str_contains($message, 'unique');
    }
}
