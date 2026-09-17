<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicKeywordSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;

/**
 * Targeted membership reconcile for one site Topic (manual create / repair).
 *
 * Does not run global recluster. One keyword remains one Topic per site.
 */
final class TopicMembershipReconcileService
{
    public function __construct(
        private readonly TopicMembershipMatcher $matcher,
        private readonly TopicSiteKeywordService $siteKeywords,
        private readonly TopicDnaService $dna,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     error: ?string,
     *     checked: int,
     *     matched: int,
     *     attached: int,
     *     moved: int,
     *     skipped_locked: int,
     *     skipped_seed: int
     * }
     */
    public function reconcile(int $siteId, int $topicId): array
    {
        $empty = static fn (string $error = 'invalid_args'): array => [
            'ok' => false,
            'error' => $error,
            'checked' => 0,
            'matched' => 0,
            'attached' => 0,
            'moved' => 0,
            'skipped_locked' => 0,
            'skipped_seed' => 0,
        ];

        if ($siteId <= 0 || $topicId <= 0 || ! TopicReclusterService::tablesReady()) {
            return $empty($siteId <= 0 || $topicId <= 0 ? 'invalid_args' : 'topic_tables_missing');
        }

        $topic = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('id', $topicId)
            ->first();
        if (! $topic instanceof SeoTopic) {
            return $empty('topic_not_found');
        }

        $topicName = TopicNaming::canonicalName((string) $topic->name) ?: (string) $topic->name;
        $eligible = $this->siteKeywords->loadEligibleSeoKeywords($siteId);

        /** @var array<int, SeoTopicKeyword> $memberships */
        $memberships = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->get()
            ->keyBy(static fn (SeoTopicKeyword $row): int => (int) $row->keyword_id)
            ->all();

        /** @var array<int, true> $lockedTopicIds */
        $lockedTopicIds = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('is_locked', true)
            ->pluck('id')
            ->mapWithKeys(static fn ($id): array => [(int) $id => true])
            ->all();

        $checked = 0;
        $matched = 0;
        $attached = 0;
        $moved = 0;
        $skippedLocked = 0;
        $skippedSeed = 0;
        /** @var array<int, true> $affectedTopicIds */
        $affectedTopicIds = [$topicId => true];

        DB::connection('omi_seo_ai')->transaction(function () use (
            $siteId,
            $topicId,
            $topicName,
            $eligible,
            $memberships,
            $lockedTopicIds,
            &$checked,
            &$matched,
            &$attached,
            &$moved,
            &$skippedLocked,
            &$skippedSeed,
            &$affectedTopicIds,
        ): void {
            foreach ($eligible as $row) {
                $checked++;
                $keywordId = (int) $row['keyword_id'];
                $phrase = (string) $row['phrase'];
                if ($keywordId <= 0 || $phrase === '') {
                    continue;
                }
                if (! $this->matcher->matches($phrase, $topicName)) {
                    continue;
                }
                $matched++;

                $existing = $memberships[$keywordId] ?? null;
                if ($existing instanceof SeoTopicKeyword && (int) $existing->topic_id === $topicId) {
                    continue;
                }

                if ($existing instanceof SeoTopicKeyword) {
                    if ((bool) $existing->is_locked) {
                        $skippedLocked++;

                        continue;
                    }
                    if ((bool) $existing->is_seed) {
                        $skippedSeed++;

                        continue;
                    }
                    $fromTopicId = (int) $existing->topic_id;
                    if (isset($lockedTopicIds[$fromTopicId])) {
                        $skippedLocked++;

                        continue;
                    }

                    $existing->topic_id = $topicId;
                    $existing->source = TopicKeywordSource::RECLUSTER;
                    $existing->is_seed = false;
                    $existing->is_locked = false;
                    $existing->confidence = 0.8;
                    $existing->save();
                    $memberships[$keywordId] = $existing;
                    $moved++;
                    $affectedTopicIds[$fromTopicId] = true;
                    $affectedTopicIds[$topicId] = true;

                    continue;
                }

                $created = SeoTopicKeyword::query()->create([
                    'site_id' => $siteId,
                    'topic_id' => $topicId,
                    'keyword_id' => $keywordId,
                    'source' => TopicKeywordSource::RECLUSTER,
                    'is_seed' => false,
                    'is_locked' => false,
                    'confidence' => 0.8,
                ]);
                $memberships[$keywordId] = $created;
                $attached++;
                $affectedTopicIds[$topicId] = true;
            }
        });

        foreach (array_keys($affectedTopicIds) as $affectedId) {
            $this->rebuildDna($siteId, (int) $affectedId);
        }

        return [
            'ok' => true,
            'error' => null,
            'checked' => $checked,
            'matched' => $matched,
            'attached' => $attached,
            'moved' => $moved,
            'skipped_locked' => $skippedLocked,
            'skipped_seed' => $skippedSeed,
        ];
    }

    private function rebuildDna(int $siteId, int $topicId): void
    {
        $topic = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('id', $topicId)
            ->first();
        if (! $topic instanceof SeoTopic) {
            return;
        }

        $keywordIds = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('topic_id', $topicId)
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->dna->rebuildForTopic($siteId, $topicId, (string) $topic->name, $keywordIds);
    }
}
