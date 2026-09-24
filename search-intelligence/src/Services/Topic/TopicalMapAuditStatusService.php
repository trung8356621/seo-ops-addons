<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResult;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultTopicalMapAuditPromptInstaller;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\TopicalMapAuditHistoryLinker;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordTopicAssignmentStats;
use Throwable;

/**
 * Lightweight current/stale detection for manual AI Audit & Tags.
 * Does not dispatch AI.
 */
final class TopicalMapAuditStatusService
{
    public const STATUS_NEVER_RUN = 'never_run';

    public const STATUS_CURRENT = 'current';

    public const STATUS_STALE = 'stale';

    public function __construct(
        private readonly TopicalMapReadModel $topicalMap,
        private readonly TopicUserTagService $tags,
        private readonly KeywordTopicAssignmentStats $assignmentStats,
        private readonly TopicalMapAuditHistoryLinker $historyLinker,
    ) {}

    /**
     * @return array{
     *   status: string,
     *   can_run: bool,
     *   site_id: int,
     *   site_domain: string,
     *   topic_count: int,
     *   assigned_keywords: int,
     *   unassigned_keywords: int,
     *   existing_tags: int,
     *   topics_with_tags: int,
     *   untagged_topics: int,
     *   source_updated_at: string|null,
     *   last_ai_run_at: string|null,
     *   last_prompt_result_id: int|null,
     *   ai_history_url: string|null
     * }
     */
    public function snapshot(int $siteId, string $siteDomain = ''): array
    {
        $overview = $this->topicalMap->overview($siteId);
        $topicCount = $overview->topicCount;
        $sourceUpdatedAt = $overview->sourceUpdatedAt;

        $assigned = 0;
        $unassigned = 0;
        try {
            $stats = $this->assignmentStats->forSite($siteId);
            $assigned = (int) ($stats['assigned'] ?? 0);
            $unassigned = (int) ($stats['unassigned'] ?? 0);
        } catch (Throwable) {
            $assigned = (int) $overview->totalKeywords;
            $unassigned = 0;
        }

        $tagList = $this->tags->listForSite($siteId);
        $existingTags = count($tagList);
        $topicsWithTags = 0;
        if ($topicCount > 0 && TopicUserTagService::tablesReady()) {
            $topicIds = array_map(static fn (array $t): int => (int) ($t['id'] ?? 0), $overview->topics);
            $map = $this->tags->mapForTopics($siteId, $topicIds);
            foreach ($map as $tags) {
                if ($tags !== []) {
                    $topicsWithTags++;
                }
            }
        }
        $untaggedTopics = max(0, $topicCount - $topicsWithTags);

        $last = $this->latestSuccessfulAudit($siteId);
        $lastAt = $last['finished_at'] ?? null;
        $status = self::STATUS_NEVER_RUN;
        if ($lastAt !== null && $lastAt !== '') {
            $status = $this->isAuditCurrent($lastAt, $sourceUpdatedAt)
                ? self::STATUS_CURRENT
                : self::STATUS_STALE;
        }

        return [
            'status' => $status,
            'can_run' => $topicCount > 0,
            'site_id' => $siteId,
            'site_domain' => $siteDomain,
            'topic_count' => $topicCount,
            'assigned_keywords' => $assigned,
            'unassigned_keywords' => $unassigned,
            'existing_tags' => $existingTags,
            'topics_with_tags' => $topicsWithTags,
            'untagged_topics' => $untaggedTopics,
            'source_updated_at' => $sourceUpdatedAt,
            'last_ai_run_at' => $lastAt,
            'last_prompt_result_id' => $last['prompt_result_id'] ?? null,
            'ai_history_url' => $this->historyLinker->resolveAiHistoryUrl(),
        ];
    }

    public function isAuditCurrent(string $lastAiRunAt, ?string $sourceUpdatedAt): bool
    {
        if ($sourceUpdatedAt === null || trim($sourceUpdatedAt) === '') {
            return true;
        }
        $lastTs = strtotime($lastAiRunAt);
        $sourceTs = strtotime($sourceUpdatedAt);
        if ($lastTs === false) {
            return false;
        }
        if ($sourceTs === false) {
            return true;
        }

        return $lastTs >= $sourceTs;
    }

    /**
     * @return array{finished_at: string|null, prompt_result_id: int|null}
     */
    public function latestSuccessfulAudit(int $siteId): array
    {
        if ($siteId <= 0) {
            return ['finished_at' => null, 'prompt_result_id' => null];
        }

        try {
            $hook = DefaultTopicalMapAuditPromptInstaller::HOOK_KEY;
            $promptIds = SeoPrompt::query()
                ->where('hook_key', $hook)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            // 1) Canonical: PromptResult.site_id matches current site.
            $row = $this->querySuccessfulHookResults($hook, $promptIds)
                ->where('site_id', $siteId)
                ->orderByDesc('finished_at')
                ->orderByDesc('id')
                ->first(['id', 'finished_at', 'created_at']);

            if ($row !== null) {
                return $this->formatAuditPointer($row);
            }

            // 2) History linker / planner run (site_id on PromptResult may be 0 from legacy PromptRunner).
            $linkedId = $this->historyLinker->latestAuditPromptResultId($siteId);
            if ($linkedId !== null && $linkedId > 0) {
                $linked = SeoPromptResult::query()
                    ->whereKey($linkedId)
                    ->whereIn('status', ['completed', 'success', 'succeeded'])
                    ->first(['id', 'finished_at', 'created_at']);
                if ($linked !== null) {
                    return $this->formatAuditPointer($linked);
                }
            }

            // 3) Legacy PromptRunner rows: site_id=0 but variables.site_domain matches.
            $domain = $this->resolveSiteDomain($siteId);
            if ($domain !== '') {
                $candidates = $this->querySuccessfulHookResults($hook, $promptIds)
                    ->where(function ($q): void {
                        $q->where('site_id', 0)->orWhereNull('site_id');
                    })
                    ->orderByDesc('finished_at')
                    ->orderByDesc('id')
                    ->limit(25)
                    ->get(['id', 'finished_at', 'created_at', 'input_snapshot']);

                foreach ($candidates as $candidate) {
                    $snap = is_array($candidate->input_snapshot ?? null) ? $candidate->input_snapshot : [];
                    $variables = is_array($snap['variables'] ?? null) ? $snap['variables'] : [];
                    $snapDomain = trim((string) ($variables['site_domain'] ?? $snap['site_domain'] ?? ''));
                    if ($snapDomain !== '' && strcasecmp($snapDomain, $domain) === 0) {
                        return $this->formatAuditPointer($candidate);
                    }
                }
            }

            return ['finished_at' => null, 'prompt_result_id' => null];
        } catch (Throwable) {
            return ['finished_at' => null, 'prompt_result_id' => null];
        }
    }

    /**
     * @param  list<int>  $promptIds
     */
    private function querySuccessfulHookResults(string $hook, array $promptIds)
    {
        $query = SeoPromptResult::query()
            ->whereIn('status', ['completed', 'success', 'succeeded']);

        $query->where(function ($q) use ($hook, $promptIds): void {
            $q->where('canonical_prompt_key', $hook);
            if ($promptIds !== []) {
                $q->orWhereIn('prompt_id', $promptIds);
            }
        });

        return $query;
    }

    /**
     * @param  object{id: mixed, finished_at?: mixed, created_at?: mixed}  $row
     * @return array{finished_at: string|null, prompt_result_id: int|null}
     */
    private function formatAuditPointer(object $row): array
    {
        $finished = $row->finished_at ?? $row->created_at ?? null;
        $iso = $finished !== null
            ? (is_string($finished) ? $finished : $finished->toIso8601String())
            : null;

        return [
            'finished_at' => $iso,
            'prompt_result_id' => (int) $row->id,
        ];
    }

    private function resolveSiteDomain(int $siteId): string
    {
        try {
            $site = \App\Models\Site::query()->find($siteId);
            if ($site === null) {
                return '';
            }

            return trim((string) ($site->domain ?? ''));
        } catch (Throwable) {
            return '';
        }
    }
}
