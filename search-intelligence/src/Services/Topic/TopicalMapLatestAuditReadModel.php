<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Omnichannel\Addons\AiPrompt\Models\SeoPromptResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\TopicalMapAuditHistoryLinker;
use Throwable;

/**
 * Read-only presentation of the latest successful topical_map_audit PromptResult.
 * Does not run AI. Does not duplicate payload into another table.
 */
final class TopicalMapLatestAuditReadModel
{
    /** @var array<string, int> */
    private const SEVERITY_RANK = [
        'high' => 0,
        'medium' => 1,
        'low' => 2,
    ];

    public function __construct(
        private readonly TopicalMapAuditStatusService $status,
        private readonly TopicalMapAuditResultParser $parser,
        private readonly TopicalMapReadModel $topicalMap,
        private readonly TopicalMapAuditHistoryLinker $historyLinker,
    ) {}

    /**
     * @return array{
     *   status: string,
     *   last_prompt_result_id: int|null,
     *   last_run_at: string|null,
     *   summary: string,
     *   findings: list<array<string, mixed>>,
     *   opportunities: list<array<string, mixed>>,
     *   recommended_actions: list<array<string, mixed>>,
     *   findings_by_topic: array<int, list<array<string, mixed>>>,
     *   site_wide_findings: list<array<string, mixed>>,
     *   findings_count: int,
     *   opportunities_count: int,
     *   actions_count: int,
     *   ai_history_url: string|null
     * }
     */
    public function forSite(int $siteId): array
    {
        $empty = $this->emptyPresentation(TopicalMapAuditStatusService::STATUS_NEVER_RUN);

        if ($siteId <= 0) {
            return $empty;
        }

        try {
            $last = $this->status->latestSuccessfulAudit($siteId);
            $lastId = isset($last['prompt_result_id']) ? (int) $last['prompt_result_id'] : null;
            $lastAt = isset($last['finished_at']) && is_string($last['finished_at']) ? $last['finished_at'] : null;
            $historyUrl = $this->historyLinker->resolveAiHistoryUrl();

            $overview = $this->topicalMap->overview($siteId);
            $validTopicIds = [];
            foreach ($overview->topics as $topic) {
                if (! is_array($topic)) {
                    continue;
                }
                $id = (int) ($topic['id'] ?? 0);
                if ($id > 0) {
                    $validTopicIds[$id] = true;
                }
            }

            $status = TopicalMapAuditStatusService::STATUS_NEVER_RUN;
            if ($lastAt !== null && $lastAt !== '') {
                $status = $this->status->isAuditCurrent($lastAt, $overview->sourceUpdatedAt)
                    ? TopicalMapAuditStatusService::STATUS_CURRENT
                    : TopicalMapAuditStatusService::STATUS_STALE;
            }

            if ($lastId === null || $lastId <= 0) {
                return array_merge($empty, [
                    'status' => $status,
                    'last_prompt_result_id' => null,
                    'last_run_at' => $lastAt,
                    'ai_history_url' => $historyUrl,
                ]);
            }

            $payload = $this->loadPayloadSoft($lastId);
            $findings = is_array($payload['findings'] ?? null) ? array_values($payload['findings']) : [];
            $opportunities = is_array($payload['opportunities'] ?? null) ? array_values($payload['opportunities']) : [];
            $actions = is_array($payload['recommended_actions'] ?? null) ? array_values($payload['recommended_actions']) : [];
            $summary = trim((string) ($payload['summary'] ?? ''));
            $mapped = $this->mapFindings($findings, $validTopicIds);

            return [
                'status' => $status,
                'last_prompt_result_id' => $lastId,
                'last_run_at' => $lastAt,
                'summary' => $summary,
                'findings' => $findings,
                'opportunities' => $opportunities,
                'recommended_actions' => $actions,
                'findings_by_topic' => $mapped['by_topic'],
                'site_wide_findings' => $mapped['site_wide'],
                'findings_count' => count($findings),
                'opportunities_count' => count($opportunities),
                'actions_count' => count($actions),
                'ai_history_url' => $historyUrl,
            ];
        } catch (Throwable) {
            return $empty;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @param  array<int, true>  $validTopicIds
     * @return array{
     *   by_topic: array<int, list<array<string, mixed>>>,
     *   site_wide: list<array<string, mixed>>
     * }
     */
    public function mapFindings(array $findings, array $validTopicIds): array
    {
        /** @var array<int, list<array<string, mixed>>> $byTopic */
        $byTopic = [];
        /** @var list<array<string, mixed>> $siteWide */
        $siteWide = [];

        foreach ($findings as $index => $finding) {
            if (! is_array($finding)) {
                continue;
            }
            $withOrder = $finding;
            $withOrder['_order'] = (int) $index;
            $topicId = TopicalMapAuditContracts::topicIdFromRef(
                isset($finding['topic_ref']) ? (string) $finding['topic_ref'] : null,
            );
            if ($topicId !== null && isset($validTopicIds[$topicId])) {
                $byTopic[$topicId][] = $withOrder;
            } else {
                $siteWide[] = $withOrder;
            }
        }

        foreach ($byTopic as $topicId => $rows) {
            $byTopic[$topicId] = $this->sortFindingsBySeverity($rows);
        }
        $siteWide = $this->sortFindingsBySeverity($siteWide);

        return [
            'by_topic' => $byTopic,
            'site_wide' => $siteWide,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @return list<array<string, mixed>>
     */
    public function sortFindingsBySeverity(array $findings): array
    {
        usort($findings, static function (array $a, array $b): int {
            $rankA = self::SEVERITY_RANK[strtolower(trim((string) ($a['severity'] ?? 'medium')))] ?? 1;
            $rankB = self::SEVERITY_RANK[strtolower(trim((string) ($b['severity'] ?? 'medium')))] ?? 1;
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            return ((int) ($a['_order'] ?? 0)) <=> ((int) ($b['_order'] ?? 0));
        });

        return array_values($findings);
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     */
    public function highestSeverity(array $findings): ?string
    {
        if ($findings === []) {
            return null;
        }
        $best = null;
        $bestRank = PHP_INT_MAX;
        foreach ($findings as $finding) {
            if (! is_array($finding)) {
                continue;
            }
            $severity = strtolower(trim((string) ($finding['severity'] ?? '')));
            if (! isset(self::SEVERITY_RANK[$severity])) {
                continue;
            }
            $rank = self::SEVERITY_RANK[$severity];
            if ($rank < $bestRank) {
                $bestRank = $rank;
                $best = $severity;
            }
        }

        return $best;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPayloadSoft(int $promptResultId): array
    {
        try {
            $row = SeoPromptResult::query()
                ->whereKey($promptResultId)
                ->first(['id', 'output_text', 'status']);
            if ($row === null) {
                return [];
            }
            $raw = (string) ($row->output_text ?? '');
            if (trim($raw) === '') {
                return [];
            }
            // Empty allowed refs → keep any syntactically valid topic:N (filter later by live topics).
            $parsed = $this->parser->parse($raw, []);
            if (! ($parsed['ok'] ?? false) || ! is_array($parsed['payload'] ?? null)) {
                return [];
            }

            return $parsed['payload'];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array{
     *   status: string,
     *   last_prompt_result_id: int|null,
     *   last_run_at: string|null,
     *   summary: string,
     *   findings: list<array<string, mixed>>,
     *   opportunities: list<array<string, mixed>>,
     *   recommended_actions: list<array<string, mixed>>,
     *   findings_by_topic: array<int, list<array<string, mixed>>>,
     *   site_wide_findings: list<array<string, mixed>>,
     *   findings_count: int,
     *   opportunities_count: int,
     *   actions_count: int,
     *   ai_history_url: string|null
     * }
     */
    private function emptyPresentation(string $status): array
    {
        return [
            'status' => $status,
            'last_prompt_result_id' => null,
            'last_run_at' => null,
            'summary' => '',
            'findings' => [],
            'opportunities' => [],
            'recommended_actions' => [],
            'findings_by_topic' => [],
            'site_wide_findings' => [],
            'findings_count' => 0,
            'opportunities_count' => 0,
            'actions_count' => 0,
            'ai_history_url' => null,
        ];
    }
}
