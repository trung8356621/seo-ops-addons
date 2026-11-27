<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager;

use App\Models\Site;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\DiscoverNewTopicsDuplicateFilter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\TopicGroup\TopicGroupRetrievalClient;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Throwable;

/**
 * Distribute selected Idea Manager note_items into the destination site Idea workspace.
 *
 * Reusable Idea contract (copy, never consume):
 * - Destination = seo_content_project_idea_workspaces (target site_id)
 * - Source warehouse Ideas are long-lived; Confirm never deletes/hides/marks-used source rows
 * - Distribution = COPY into destination workspace only (never move)
 * - Destination edits stay on destination; never write back to source
 * - Destination uniqueness: IdeaWorkspaceItemOps::findDuplicate (same ref OR normalized name)
 *   → intentional re-submit to same dest = already_present (idempotent); not infinite clones
 * - Semantic Keyword check is destination-advisory only (ideas + draft planning + published titles + Topic Core)
 * - Semantic/exact warnings NEVER block Confirm; Unknown = no fabricated score
 * - Does NOT Generate Ideas / create Project Items / Publish / mutate source workspace
 * - Active Month = intended planning context only (not stamped on tasks)
 */
final class IdeaManagerDistributionService
{
    public const STATUS_DISTRIBUTED = 'distributed';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ALREADY = 'already_present';

    public const CHECK_ANALYZED = 'analyzed';

    public const CHECK_UNAVAILABLE = 'unavailable';

    public const CHECK_PENDING = 'pending';

    public function __construct(
        private readonly IdeaWorkspacePersistenceService $workspaces,
        private readonly DiscoverNewTopicsDuplicateFilter $topicDupFilter,
        private readonly ?TopicGroupRetrievalClient $topicGroupMatches = null,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $selectedItems
     * @return array<string, mixed>
     */
    public function preview(int $targetSiteId, array $selectedItems, string $activeMonth): array
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        abort_unless($targetSiteId > 0 && SeoAccessControl::canAccessSite($targetSiteId), 403);

        $month = ContentProjectMonthContext::normalize($activeMonth);
        $domain = (string) (Site::query()->whereKey($targetSiteId)->value('domain') ?? ('#'.$targetSiteId));
        $destItems = $this->workspaces->load($targetSiteId)['note_items'] ?? [];
        $selected = $this->normalizeSelected($selectedItems);
        $topicKeys = $this->topicDuplicateMap(
            $targetSiteId,
            array_map(static fn (array $i): string => (string) ($i['cluster_name_snapshot'] ?? ''), $selected),
        );
        $inventory = $this->withDestinationContentInventory(
            $targetSiteId,
            $this->buildSemanticInventory($destItems, $topicKeys),
        );

        $rows = [];
        $exactCount = 0;
        $warnCount = 0;
        foreach ($selected as $item) {
            $row = $this->analyzeItem($item, $destItems, $topicKeys, $inventory, $targetSiteId);
            if ($row['exact_match']) {
                $exactCount++;
            }
            if (! empty($row['warning'])) {
                $warnCount++;
            }
            $rows[] = $row;
        }

        return [
            'target_site_id' => $targetSiteId,
            'target_domain' => $domain,
            'active_month' => $month,
            'eligible_count' => count($rows),
            'duplicate_count' => $exactCount,
            'warning_count' => $warnCount,
            // Confirm eligibility is input/auth based — warnings never disable.
            'can_confirm' => count($rows) > 0,
            'items' => $rows,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $selectedItems
     * @return array<string, mixed>
     */
    public function confirm(
        int $sourceSiteId,
        int $targetSiteId,
        array $selectedItems,
        string $activeMonth,
        ?int $actorId = null,
    ): array {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        abort_unless($targetSiteId > 0 && SeoAccessControl::canAccessSite($targetSiteId), 403);
        abort_unless($sourceSiteId > 0 && SeoAccessControl::canAccessSite($sourceSiteId), 403);

        $month = ContentProjectMonthContext::normalize($activeMonth);
        $domain = (string) (Site::query()->whereKey($targetSiteId)->value('domain') ?? ('#'.$targetSiteId));
        $empty = static fn (string $message, int $failed = 0): array => [
            'ok' => false,
            'target_site_id' => $targetSiteId,
            'target_domain' => $domain,
            'active_month' => $month,
            'distributed' => 0,
            'duplicate' => 0,
            'failed' => $failed,
            'already_present' => 0,
            'results' => [],
            'message' => $message,
        ];

        if ($sourceSiteId === $targetSiteId) {
            return $empty('source_equals_target', count($selectedItems));
        }

        $selected = $this->normalizeSelected($selectedItems);
        if ($selected === []) {
            return $empty('empty_selection');
        }

        // Exact collisions become already_present per-Idea — never abort the whole confirm.
        return $this->mergeIntoDestination($targetSiteId, $domain, $month, $selected, $actorId);
    }

    /**
     * @param  list<array<string, mixed>>  $selected
     * @return array<string, mixed>
     */
    private function mergeIntoDestination(
        int $targetSiteId,
        string $domain,
        string $month,
        array $selected,
        ?int $actorId,
    ): array {
        $attempt = function (array $destItems, int $revision) use ($selected): array {
            $merged = $destItems;
            $results = [];
            $toAdd = [];
            foreach ($selected as $item) {
                $ref = (string) $item['cluster_ref'];
                $name = (string) $item['cluster_name_snapshot'];
                $existing = IdeaWorkspaceItemOps::findDuplicate($merged, $ref, $name);
                if ($existing !== null) {
                    $results[] = [
                        'cluster_ref' => $ref,
                        'name' => $name,
                        'status' => self::STATUS_ALREADY,
                        'reason' => 'already_in_destination_workspace',
                        'matched' => (string) ($existing['cluster_name_snapshot'] ?? $existing['cluster_ref'] ?? ''),
                    ];
                    continue;
                }
                $normalized = AuditNoteDnaNormalizer::normalizeNoteItem($item);
                if ($normalized === null) {
                    $results[] = [
                        'cluster_ref' => $ref,
                        'name' => $name,
                        'status' => self::STATUS_FAILED,
                        'reason' => 'normalize_failed',
                        'matched' => null,
                    ];
                    continue;
                }
                $toAdd[] = $normalized;
                $merged[] = $normalized;
                $results[] = [
                    'cluster_ref' => (string) $normalized['cluster_ref'],
                    'name' => (string) $normalized['cluster_name_snapshot'],
                    'status' => self::STATUS_DISTRIBUTED,
                    'reason' => null,
                    'matched' => null,
                ];
            }

            return ['merged' => $merged, 'results' => $results, 'to_add' => $toAdd, 'revision' => $revision];
        };

        $destSnap = $this->workspaces->load($targetSiteId, $actorId);
        $plan = $attempt(
            is_array($destSnap['note_items'] ?? null) ? $destSnap['note_items'] : [],
            (int) ($destSnap['revision'] ?? 0),
        );

        $saveStatus = IdeaWorkspacePersistenceService::STATUS_NOOP;
        if ($plan['to_add'] !== []) {
            $save = $this->workspaces->save($targetSiteId, $plan['merged'], $plan['revision'], $actorId);
            $saveStatus = (string) ($save['status'] ?? 'error');
            if ($saveStatus === IdeaWorkspacePersistenceService::STATUS_CONFLICT) {
                $destSnap = $this->workspaces->load($targetSiteId, $actorId);
                $plan = $attempt(
                    is_array($destSnap['note_items'] ?? null) ? $destSnap['note_items'] : [],
                    (int) ($destSnap['revision'] ?? 0),
                );
                if ($plan['to_add'] !== []) {
                    $save = $this->workspaces->save($targetSiteId, $plan['merged'], $plan['revision'], $actorId);
                    $saveStatus = (string) ($save['status'] ?? 'error');
                } else {
                    $saveStatus = IdeaWorkspacePersistenceService::STATUS_NOOP;
                }
            }
        }

        $okStatuses = [
            IdeaWorkspacePersistenceService::STATUS_OK,
            IdeaWorkspacePersistenceService::STATUS_NOOP,
        ];
        $ok = in_array($saveStatus, $okStatuses, true);
        $results = $plan['results'];

        if (! $ok) {
            foreach ($results as &$row) {
                if ($row['status'] === self::STATUS_DISTRIBUTED) {
                    $row['status'] = self::STATUS_FAILED;
                    $row['reason'] = 'destination_save_'.$saveStatus;
                }
            }
            unset($row);
        }

        $count = static fn (string $status): int => count(array_filter(
            $results,
            static fn (array $r): bool => $r['status'] === $status,
        ));

        return [
            'ok' => $ok && $count(self::STATUS_FAILED) === 0,
            'target_site_id' => $targetSiteId,
            'target_domain' => $domain,
            'active_month' => $month,
            'distributed' => $ok ? $count(self::STATUS_DISTRIBUTED) : 0,
            'duplicate' => 0,
            'failed' => $count(self::STATUS_FAILED),
            'already_present' => $count(self::STATUS_ALREADY),
            'results' => $results,
            // Explicit reusable-Idea guarantee for clients.
            'source_unchanged' => true,
            'source_consumed' => false,
            'message' => $ok ? ($plan['to_add'] === [] ? 'idempotent_noop' : null) : ('destination_save_'.$saveStatus),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<array<string, mixed>>  $destItems
     * @param  array<string, string>  $topicKeys
     * @param  list<array{ref: string, label: string, type: string, examples: list<string>}>  $inventory
     * @return array<string, mixed>
     */
    private function analyzeItem(
        array $item,
        array $destItems,
        array $topicKeys,
        array $inventory,
        int $targetSiteId,
    ): array {
        $ref = (string) ($item['cluster_ref'] ?? '');
        $name = (string) ($item['cluster_name_snapshot'] ?? '');
        $base = [
            'cluster_ref' => $ref,
            'name' => $name,
            'duplicate' => false,
            'exact_match' => false,
            'warning' => false,
            'check_status' => self::CHECK_ANALYZED,
            'similarity_score' => null,
            'matched' => null,
            'matched_type' => null,
            'reason' => null,
        ];

        $hit = IdeaWorkspaceItemOps::findDuplicate($destItems, $ref, $name);
        if ($hit !== null) {
            return array_merge($base, [
                'exact_match' => true,
                'warning' => true,
                'matched' => (string) ($hit['cluster_name_snapshot'] ?? $hit['cluster_ref'] ?? ''),
                'matched_type' => 'idea',
                'reason' => 'exact_destination_workspace',
            ]);
        }

        $key = AuditNoteDnaNormalizer::normalizeKey(
            AuditNoteDnaNormalizer::normalizeSeedText($name) ?: $name
        );
        if ($key !== '' && isset($topicKeys[$key])) {
            return array_merge($base, [
                'exact_match' => true,
                'warning' => true,
                'matched' => $topicKeys[$key],
                'matched_type' => 'topic',
                'reason' => 'exact_destination_topic_core',
            ]);
        }

        if ($inventory === []) {
            return array_merge($base, [
                'check_status' => self::CHECK_ANALYZED,
                'reason' => 'no_destination_inventory',
            ]);
        }

        $client = $this->topicGroupMatches;
        if (! $client instanceof TopicGroupRetrievalClient) {
            try {
                $client = app(TopicGroupRetrievalClient::class);
            } catch (Throwable) {
                $client = null;
            }
        }
        if (! $client instanceof TopicGroupRetrievalClient) {
            return array_merge($base, [
                'check_status' => self::CHECK_UNAVAILABLE,
                // Unknown is not a similarity warning — never fabricate a score.
                'warning' => false,
                'similarity_score' => null,
                'reason' => 'semantic_client_unavailable',
            ]);
        }

        try {
            $groups = array_map(static fn (array $g): array => [
                'ref' => $g['ref'],
                'label' => $g['label'],
                'examples' => $g['examples'],
            ], $inventory);
            $result = $client->match('site:'.$targetSiteId, $name, $groups, null, 3);
            $best = $result->matches[0] ?? null;
            if ($best === null) {
                return array_merge($base, [
                    'check_status' => self::CHECK_ANALYZED,
                    'reason' => 'no_semantic_match',
                ]);
            }
            $matchedRef = (string) $best['ref'];
            $score = isset($best['score']) ? (float) $best['score'] : null;
            $meta = null;
            foreach ($inventory as $g) {
                if ($g['ref'] === $matchedRef) {
                    $meta = $g;
                    break;
                }
            }

            // TopicGroupRetrievalClient already applies provider min_score — returned hits are advisory.
            // Never treat semantic score as exact_match or Confirm blocker.
            return array_merge($base, [
                'check_status' => self::CHECK_ANALYZED,
                'warning' => true,
                'similarity_score' => $score,
                'matched' => (string) ($meta['label'] ?? $matchedRef),
                'matched_type' => (string) ($meta['type'] ?? 'unknown'),
                'reason' => 'semantic_similarity',
            ]);
        } catch (Throwable) {
            return array_merge($base, [
                'check_status' => self::CHECK_UNAVAILABLE,
                'warning' => false,
                'similarity_score' => null,
                'reason' => 'semantic_service_unavailable',
            ]);
        }
    }

    /**
     * Destination-scoped inventory for Semantic Keyword advisory.
     * Sources: dest Idea warehouse + draft planning keywords + published titles + Topic Core names.
     *
     * @param  list<array<string, mixed>>  $destItems
     * @param  array<string, string>  $topicKeys
     * @return list<array{ref: string, label: string, type: string, examples: list<string>}>
     */
    private function buildSemanticInventory(array $destItems, array $topicKeys): array
    {
        $out = [];
        $seen = [];
        $push = static function (array &$out, array &$seen, string $ref, string $label, string $type): void {
            $label = trim($label);
            if ($label === '') {
                return;
            }
            $key = AuditNoteDnaNormalizer::normalizeKey(
                AuditNoteDnaNormalizer::normalizeSeedText($label) ?: $label
            );
            if ($key === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $out[] = [
                'ref' => $ref,
                'label' => $label,
                'type' => $type,
                'examples' => [$label],
            ];
        };

        foreach ($destItems as $i => $item) {
            if (! is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['cluster_name_snapshot'] ?? $item['seed_text'] ?? ''));
            $ref = trim((string) ($item['cluster_ref'] ?? ''));
            $push($out, $seen, $ref !== '' ? 'idea:'.$ref : 'idea:'.$i, $label, 'idea');
        }

        $ti = 0;
        foreach ($topicKeys as $label) {
            $push($out, $seen, 'topic:'.$ti, (string) $label, 'topic');
            $ti++;
        }

        // Cap inventory for match payload size — provider returns scores; we do not invent thresholds.
        return array_slice($out, 0, 80);
    }

    /**
     * @param  list<array{ref: string, label: string, type: string, examples: list<string>}>  $base
     * @return list<array{ref: string, label: string, type: string, examples: list<string>}>
     */
    private function withDestinationContentInventory(int $targetSiteId, array $base): array
    {
        $seen = [];
        foreach ($base as $row) {
            $key = AuditNoteDnaNormalizer::normalizeKey(
                AuditNoteDnaNormalizer::normalizeSeedText((string) ($row['label'] ?? '')) ?: (string) ($row['label'] ?? '')
            );
            if ($key !== '') {
                $seen[$key] = true;
            }
        }
        $out = $base;
        $push = static function (array &$out, array &$seen, string $ref, string $label, string $type): void {
            $label = trim($label);
            if ($label === '') {
                return;
            }
            $key = AuditNoteDnaNormalizer::normalizeKey(
                AuditNoteDnaNormalizer::normalizeSeedText($label) ?: $label
            );
            if ($key === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $out[] = [
                'ref' => $ref,
                'label' => $label,
                'type' => $type,
                'examples' => [$label],
            ];
        };

        try {
            $keywords = SeoProjectTask::query()
                ->where('site_id', $targetSiteId)
                ->where('type', SeoProjectTask::TYPE_CREATE)
                ->whereHas('project', static function ($q): void {
                    $q->where('status', SeoProject::STATUS_DRAFT)->whereNull('archived_at');
                })
                ->orderByDesc('id')
                ->limit(40)
                ->pluck('keyword');
            $pi = 0;
            foreach ($keywords as $kw) {
                $push($out, $seen, 'planning:'.$pi, (string) $kw, 'planning');
                $pi++;
            }
        } catch (Throwable) {
            // Advisory only — skip planning inventory on failure.
        }

        try {
            $titles = SeoArticle::query()
                ->where('site_id', $targetSiteId)
                ->whereHas('wordpressLink', static function ($q): void {
                    $q->where('observed_post_status', 'publish');
                })
                ->orderByDesc('id')
                ->limit(40)
                ->pluck('title');
            $ai = 0;
            foreach ($titles as $title) {
                $push($out, $seen, 'article:'.$ai, (string) $title, 'article');
                $ai++;
            }
        } catch (Throwable) {
            // Advisory only — skip published inventory on failure.
        }

        return array_slice($out, 0, 80);
    }

    /**
     * @param  list<array<string, mixed>>  $selectedItems
     * @return list<array<string, mixed>>
     */
    private function normalizeSelected(array $selectedItems): array
    {
        $out = [];
        foreach ($selectedItems as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            if (! isset($raw['cluster_name_snapshot']) && isset($raw['name'])) {
                $raw['cluster_name_snapshot'] = $raw['name'];
            }
            $item = AuditNoteDnaNormalizer::normalizeNoteItem($raw);
            if ($item !== null) {
                $out[] = $item;
                continue;
            }
            $ref = trim((string) ($raw['cluster_ref'] ?? ''));
            $name = trim((string) ($raw['cluster_name_snapshot'] ?? $raw['name'] ?? ''));
            if ($ref === '' || $name === '') {
                continue;
            }
            $out[] = [
                'source_type' => AuditNoteDnaNormalizer::SOURCE_TYPE_CLUSTER,
                'cluster_ref' => $ref,
                'cluster_name_snapshot' => $name,
                'seed_text' => null,
                'mcp_share_snapshot' => null,
                'target_dna_count' => AuditNoteDnaNormalizer::DEFAULT_TARGET_DNA_COUNT,
                'target_mode' => 'manual',
                'dna' => [],
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, string>
     */
    public function topicDuplicateMap(int $siteId, array $names): array
    {
        $candidates = [];
        foreach (array_values($names) as $i => $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $candidates[] = [
                'candidate_key' => 'im-'.$i,
                'name' => $name,
                'target_dna_count' => 1,
                'dna' => [],
            ];
        }
        if ($candidates === []) {
            return [];
        }

        try {
            $result = $this->topicDupFilter->filter($siteId, $candidates);
        } catch (Throwable) {
            return [];
        }

        $map = [];
        foreach ($result['rejected'] as $row) {
            $cand = is_array($row['candidate'] ?? null) ? $row['candidate'] : [];
            $name = trim((string) ($cand['name'] ?? ''));
            $key = AuditNoteDnaNormalizer::normalizeKey(
                AuditNoteDnaNormalizer::normalizeSeedText($name) ?: $name
            );
            if ($key !== '') {
                $map[$key] = $name;
            }
        }

        return $map;
    }
}
