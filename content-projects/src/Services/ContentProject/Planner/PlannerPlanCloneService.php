<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner;

use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteClusterSuggestionQuery;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionOptions;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Throwable;

/**
 * Clone Project Planner configuration across domains (config only — never generated content).
 */
final class PlannerPlanCloneService
{
    private readonly PlannerExactTopicMatcher $clusters;

    public function __construct(
        AuditNoteClusterSuggestionQuery $clusters,
        private readonly ContentProjectPlannerRunService $plannerRuns,
    ) {
        $this->clusters = new AuditNotePlannerExactTopicMatcher($clusters);
    }

    /**
     * @param  list<array<string, mixed>>  $sourceNoteItems
     * @param  list<int>  $destinationSiteIds
     * @param  array<int, bool>  $clientHasPlanBySite  site_id => has localStorage plan
     * @param  array<int, list<array<string, mixed>>>  $clientItemsBySite  site_id => note_items from localStorage
     */
    public function cloneToDestinations(
        SeoProject $project,
        int $sourceSiteId,
        array $destinationSiteIds,
        array $sourceNoteItems,
        string $contentType,
        string $mode,
        ?int $actorId,
        array $clientHasPlanBySite = [],
        array $clientItemsBySite = [],
    ): PlannerPlanCloneResult {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);

        if ($sourceSiteId <= 0 || ! SeoAccessControl::canAccessSite($sourceSiteId)) {
            throw new InvalidArgumentException('Source domain is not accessible.');
        }

        $mode = $mode === PlannerPlanCloneAllowlist::MODE_MERGE_MISSING
            ? PlannerPlanCloneAllowlist::MODE_MERGE_MISSING
            : PlannerPlanCloneAllowlist::MODE_SKIP_EXISTING;

        $contentType = NewContentSuggestionOptions::normalizeContentType($contentType);
        $sourceItems = AuditNoteDnaNormalizer::normalizeNoteItems($sourceNoteItems);
        if ($sourceItems === []) {
            throw new InvalidArgumentException('Source plan has no Topics to copy.');
        }

        $destIds = [];
        foreach ($destinationSiteIds as $id) {
            $id = (int) $id;
            if ($id <= 0 || $id === $sourceSiteId) {
                continue;
            }
            $destIds[$id] = $id;
        }
        $destIds = array_values($destIds);
        if ($destIds === []) {
            throw new InvalidArgumentException('Select at least one destination domain.');
        }

        $sourceDomain = (string) (Site::query()->whereKey($sourceSiteId)->value('domain') ?? ('#'.$sourceSiteId));
        $correlationId = (string) Str::uuid();
        $destinations = [];

        foreach ($destIds as $destSiteId) {
            $domain = (string) (Site::query()->whereKey($destSiteId)->value('domain') ?? ('#'.$destSiteId));
            try {
                if (! SeoAccessControl::canAccessSite($destSiteId)) {
                    $destinations[] = $this->failedRow($destSiteId, $domain, 'Không có quyền domain đích.');
                    continue;
                }

                $existingState = $this->loadExistingPlanState($project, $destSiteId);
                $existing = $existingState['note_items'];
                $existingContentType = $existingState['content_type'];

                // Optional client-provided destination items for merge (localStorage).
                $clientExisting = is_array($clientItemsBySite[$destSiteId] ?? null)
                    ? $clientItemsBySite[$destSiteId]
                    : [];
                if ($clientExisting !== []) {
                    $existing = AuditNoteDnaNormalizer::normalizeNoteItems($clientExisting);
                }

                $hasPlan = $existing !== [] || ! empty($clientHasPlanBySite[$destSiteId]);

                if ($hasPlan && $mode === PlannerPlanCloneAllowlist::MODE_SKIP_EXISTING) {
                    $destinations[] = [
                        'site_id' => $destSiteId,
                        'domain' => $domain,
                        'status' => 'skipped',
                        'note_items' => $existing,
                        'content_type' => $existingContentType ?? $contentType,
                        'topic_count' => count($existing),
                        'dna_count' => $this->countDna($existing),
                        'target_total' => AuditNoteDnaNormalizer::totalTargetDnaCount($existing),
                        'warnings' => ['Domain đã có kế hoạch — đã bỏ qua.'],
                        'skipped_topics' => [],
                    ];
                    continue;
                }

                $mapped = $this->remapPlanForDestination(
                    $sourceItems,
                    $destSiteId,
                    $hasPlan ? $existing : [],
                    $mode,
                );

                // Merge: keep destination content type when already configured.
                $destContentType = $contentType;
                if (
                    $mode === PlannerPlanCloneAllowlist::MODE_MERGE_MISSING
                    && $hasPlan
                    && is_string($existingContentType)
                    && $existingContentType !== ''
                ) {
                    $destContentType = NewContentSuggestionOptions::normalizeContentType($existingContentType);
                }

                DB::connection('omi_seo_ai')->transaction(function () use (
                    $project,
                    $destSiteId,
                    $mapped,
                    $destContentType,
                    $actorId,
                    $correlationId,
                    $sourceSiteId,
                ): void {
                    $snapshot = NewContentSuggestionOptions::snapshot(
                        NewContentSuggestionOptions::normalize([
                            'quantity' => max(1, AuditNoteDnaNormalizer::totalTargetDnaCount($mapped['note_items'])),
                            'content_type' => $destContentType,
                            'post_type' => $destContentType,
                            'note_items' => $mapped['note_items'],
                        ]),
                        primaryLanguage: '',
                        siteId: $destSiteId,
                    );
                    $snapshot['clone_correlation_id'] = $correlationId;
                    $snapshot['cloned_from_site_id'] = $sourceSiteId;
                    // Never persist run/progress fields.
                    unset(
                        $snapshot['fill_remaining_of_run_id'],
                        $snapshot['fill_target_total'],
                    );

                    $this->plannerRuns->recordSavedConfig(
                        $project,
                        SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT,
                        $snapshot,
                        $actorId,
                    );
                });

                $destinations[] = [
                    'site_id' => $destSiteId,
                    'domain' => $domain,
                    'status' => 'copied',
                    'note_items' => $mapped['note_items'],
                    'content_type' => $destContentType,
                    'topic_count' => count($mapped['note_items']),
                    'dna_count' => $this->countDna($mapped['note_items']),
                    'target_total' => AuditNoteDnaNormalizer::totalTargetDnaCount($mapped['note_items']),
                    'warnings' => $mapped['warnings'],
                    'skipped_topics' => $mapped['skipped_topics'],
                ];
            } catch (Throwable $e) {
                $destinations[] = $this->failedRow($destSiteId, $domain, $e->getMessage());
            }
        }

        return new PlannerPlanCloneResult(
            sourceSiteId: $sourceSiteId,
            sourceDomain: $sourceDomain,
            mode: $mode,
            sourceTopicCount: count($sourceItems),
            sourceDnaCount: $this->countDna($sourceItems),
            sourceTargetTotal: AuditNoteDnaNormalizer::totalTargetDnaCount($sourceItems),
            destinations: $destinations,
            correlationId: $correlationId,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $sourceItems
     * @param  list<array<string, mixed>>  $existingItems
     * @return array{
     *   note_items: list<array<string, mixed>>,
     *   warnings: list<string>,
     *   skipped_topics: list<string>
     * }
     */
    public function remapPlanForDestination(
        array $sourceItems,
        int $destinationSiteId,
        array $existingItems,
        string $mode,
    ): array {
        $existingByName = [];
        $merged = [];
        foreach (AuditNoteDnaNormalizer::normalizeNoteItems($existingItems) as $item) {
            $key = $this->topicIdentityKey($item);
            $existingByName[$key] = $item;
            $merged[$key] = $item;
        }

        $warnings = [];
        $skippedTopics = [];

        foreach (AuditNoteDnaNormalizer::normalizeNoteItems($sourceItems) as $sourceItem) {
            $sourceKey = $this->topicIdentityKey($sourceItem);
            $resolved = $this->resolveDestinationTopic($sourceItem, $destinationSiteId);

            if (($resolved['status'] ?? '') === 'ambiguous') {
                $skippedTopics[] = (string) ($sourceItem['cluster_name_snapshot'] ?? $sourceKey);
                $warnings[] = 'Topic mơ hồ (nhiều match): '.(string) ($sourceItem['cluster_name_snapshot'] ?? '');
                continue;
            }

            $destItem = $resolved['item'];
            $destKey = $this->topicIdentityKey($destItem);

            if ($mode === PlannerPlanCloneAllowlist::MODE_MERGE_MISSING && isset($merged[$destKey])) {
                $merged[$destKey] = $this->mergeTopicMissingOnly($merged[$destKey], $destItem);
                continue;
            }

            if ($mode === PlannerPlanCloneAllowlist::MODE_MERGE_MISSING && isset($existingByName[$sourceKey])) {
                // Same display identity already present under another ref — merge DNA only.
                $merged[$sourceKey] = $this->mergeTopicMissingOnly($existingByName[$sourceKey], $destItem);
                continue;
            }

            if (! isset($merged[$destKey])) {
                // Fresh destination or new topic: strip source MCP metrics.
                $destItem['mcp_share_snapshot'] = $destItem['source_type'] === AuditNoteDnaNormalizer::SOURCE_TYPE_MANUAL_SEED
                    ? null
                    : ($destItem['mcp_share_snapshot'] ?? null);
                $merged[$destKey] = $destItem;
            }
        }

        return [
            'note_items' => array_values($merged),
            'warnings' => $warnings,
            'skipped_topics' => $skippedTopics,
        ];
    }

    /**
     * @param  array<string, mixed>  $sourceItem
     * @return array{status: string, item: array<string, mixed>}
     */
    public function resolveDestinationTopic(array $sourceItem, int $destinationSiteId): array
    {
        $sourceType = AuditNoteDnaNormalizer::normalizeSourceType(
            $sourceItem['source_type'] ?? null,
            (string) ($sourceItem['cluster_ref'] ?? ''),
        );
        $name = (string) ($sourceItem['cluster_name_snapshot'] ?? $sourceItem['seed_text'] ?? '');
        $dna = AuditNoteDnaNormalizer::normalizeDnaList(is_array($sourceItem['dna'] ?? null) ? $sourceItem['dna'] : []);

        if ($sourceType === AuditNoteDnaNormalizer::SOURCE_TYPE_MANUAL_SEED) {
            $matches = $this->clusters->findExactNormalizedNameMatches($destinationSiteId, $name);
            if (count($matches) === 1) {
                $match = $matches[0];

                return [
                    'status' => 'mapped',
                    'item' => AuditNoteDnaNormalizer::normalizeNoteItem([
                        'source_type' => AuditNoteDnaNormalizer::SOURCE_TYPE_CLUSTER,
                        'cluster_ref' => $match['cluster_ref'],
                        'cluster_name_snapshot' => $match['cluster_name'],
                        'mcp_share_snapshot' => $match['mcp_share'],
                        'target_dna_count' => $sourceItem['target_dna_count'] ?? AuditNoteDnaNormalizer::DEFAULT_TARGET_DNA_COUNT,
                        'target_mode' => $sourceItem['target_mode'] ?? 'manual',
                        'dna' => $dna,
                    ]) ?? $this->manualCloneItem($sourceItem, $dna),
                ];
            }
            if (count($matches) > 1) {
                return ['status' => 'ambiguous', 'item' => $sourceItem];
            }

            return [
                'status' => 'manual',
                'item' => $this->manualCloneItem($sourceItem, $dna),
            ];
        }

        $matches = $this->clusters->findExactNormalizedNameMatches($destinationSiteId, $name);
        if (count($matches) === 1) {
            $match = $matches[0];

            return [
                'status' => 'mapped',
                'item' => AuditNoteDnaNormalizer::normalizeNoteItem([
                    'source_type' => AuditNoteDnaNormalizer::SOURCE_TYPE_CLUSTER,
                    'cluster_ref' => $match['cluster_ref'],
                    'cluster_name_snapshot' => $match['cluster_name'],
                    'mcp_share_snapshot' => $match['mcp_share'],
                    'target_dna_count' => $sourceItem['target_dna_count'] ?? AuditNoteDnaNormalizer::DEFAULT_TARGET_DNA_COUNT,
                    'target_mode' => $sourceItem['target_mode'] ?? 'auto',
                    'dna' => $dna,
                ]) ?? $this->manualCloneItem($sourceItem, $dna),
            ];
        }
        if (count($matches) > 1) {
            return ['status' => 'ambiguous', 'item' => $sourceItem];
        }

        // No exact Topic on destination → manual/planned Planner Topic (no SEO Cluster create).
        return [
            'status' => 'manual',
            'item' => $this->manualCloneItem($sourceItem, $dna),
        ];
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public function mergeTopicMissingOnly(array $target, array $incoming): array
    {
        $targetDna = AuditNoteDnaNormalizer::normalizeDnaList(is_array($target['dna'] ?? null) ? $target['dna'] : []);
        $incomingDna = AuditNoteDnaNormalizer::normalizeDnaList(is_array($incoming['dna'] ?? null) ? $incoming['dna'] : []);

        $byPhrase = [];
        foreach ($targetDna as $row) {
            $byPhrase[AuditNoteDnaNormalizer::normalizeKey((string) $row['phrase'])] = $row;
        }
        foreach ($incomingDna as $row) {
            $key = AuditNoteDnaNormalizer::normalizeKey((string) $row['phrase']);
            if ($key === '' || isset($byPhrase[$key])) {
                // Keep destination placement / slots — do not overwrite.
                continue;
            }
            $byPhrase[$key] = $row;
        }

        $target['dna'] = array_values($byPhrase);
        // Keep destination target_dna_count / target_mode / content settings.
        $normalized = AuditNoteDnaNormalizer::normalizeNoteItem($target);

        return $normalized ?? $target;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadExistingPlanItems(SeoProject $project, int $siteId): array
    {
        return $this->loadExistingPlanState($project, $siteId)['note_items'];
    }

    /**
     * @return array{note_items: list<array<string, mixed>>, content_type: string|null}
     */
    public function loadExistingPlanState(SeoProject $project, int $siteId): array
    {
        $runs = SeoContentProjectPlannerRun::query()
            ->where('project_id', (int) $project->getKey())
            ->where('source_type', SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT)
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        foreach ($runs as $run) {
            $summary = is_array($run->result_summary) ? $run->result_summary : [];
            $kind = (string) ($summary['kind'] ?? SeoContentProjectPlannerRun::KIND_EXECUTED);
            if ($kind !== SeoContentProjectPlannerRun::KIND_SAVED_CONFIG) {
                continue;
            }
            $snap = is_array($run->configuration_snapshot) ? $run->configuration_snapshot : [];
            if ((int) ($snap['site_id'] ?? 0) !== $siteId) {
                continue;
            }
            $items = is_array($snap['note_items'] ?? null) ? $snap['note_items'] : [];
            $normalized = AuditNoteDnaNormalizer::normalizeNoteItems($items);
            if ($normalized === []) {
                continue;
            }

            $type = NewContentSuggestionOptions::normalizeContentType(
                (string) ($snap['content_type'] ?? $snap['post_type'] ?? NewContentSuggestionOptions::CONTENT_TYPE_POST),
            );

            return [
                'note_items' => $normalized,
                'content_type' => $type,
            ];
        }

        return [
            'note_items' => [],
            'content_type' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function topicIdentityKey(array $item): string
    {
        if (AuditNoteDnaNormalizer::isManualSeed($item)) {
            return 'manual:'.AuditNoteDnaNormalizer::normalizeKey((string) ($item['seed_text'] ?? $item['cluster_name_snapshot'] ?? ''));
        }

        return 'cluster:'.AuditNoteDnaNormalizer::normalizeKey((string) ($item['cluster_name_snapshot'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $sourceItem
     * @param  list<array<string, mixed>>  $dna
     * @return array<string, mixed>
     */
    private function manualCloneItem(array $sourceItem, array $dna): array
    {
        $seed = AuditNoteDnaNormalizer::normalizeSeedText(
            $sourceItem['seed_text'] ?? $sourceItem['cluster_name_snapshot'] ?? '',
        );

        return AuditNoteDnaNormalizer::normalizeNoteItem([
            'source_type' => AuditNoteDnaNormalizer::SOURCE_TYPE_MANUAL_SEED,
            'cluster_ref' => AuditNoteDnaNormalizer::manualSeedRef(),
            'cluster_name_snapshot' => $seed,
            'seed_text' => $seed,
            'mcp_share_snapshot' => null,
            'target_dna_count' => $sourceItem['target_dna_count'] ?? AuditNoteDnaNormalizer::DEFAULT_TARGET_DNA_COUNT,
            'target_mode' => 'manual',
            'dna' => $dna,
        ]) ?? [
            'source_type' => AuditNoteDnaNormalizer::SOURCE_TYPE_MANUAL_SEED,
            'cluster_ref' => AuditNoteDnaNormalizer::manualSeedRef(),
            'cluster_name_snapshot' => $seed,
            'seed_text' => $seed,
            'mcp_share_snapshot' => null,
            'target_dna_count' => AuditNoteDnaNormalizer::DEFAULT_TARGET_DNA_COUNT,
            'target_mode' => 'manual',
            'dna' => $dna,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function countDna(array $items): int
    {
        $n = 0;
        foreach ($items as $item) {
            $n += count(is_array($item['dna'] ?? null) ? $item['dna'] : []);
        }

        return $n;
    }

    /**
     * @return array<string, mixed>
     */
    private function failedRow(int $siteId, string $domain, string $message): array
    {
        return [
            'site_id' => $siteId,
            'domain' => $domain,
            'status' => 'failed',
            'note_items' => [],
            'content_type' => NewContentSuggestionOptions::CONTENT_TYPE_POST,
            'topic_count' => 0,
            'dna_count' => 0,
            'target_total' => 0,
            'warnings' => [$message],
            'skipped_topics' => [],
        ];
    }
}
