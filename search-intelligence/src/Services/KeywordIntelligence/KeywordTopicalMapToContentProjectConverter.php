<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordClusterStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordTopicalMapVersionStatus;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordContentProjectLink;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordProjectConversion;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoTopicalMapVersion;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\CreateContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Quotas\KeywordIntelligenceQuotaGuard;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Convert approved topical map version → Content Project via CommandBus.
 * No gallery_description. No auto schedule/publish. Covered clusters skipped by default.
 */
final class KeywordTopicalMapToContentProjectConverter
{
    public function __construct(
        private readonly KeywordClusterContentActionResolver $actionResolver,
        private readonly KeywordIntelligenceQuotaGuard $quota,
    ) {}

    /**
     * @param  list<string>|null  $selectedClusterRefs
     * @param  list<string>|null  $selectedTopicRefs
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function preview(
        SeoKeywordWorkspace $workspace,
        SeoTopicalMapVersion $mapVersion,
        string $policy = 'new_only',
        ?array $selectedClusterRefs = null,
        ?array $selectedTopicRefs = null,
        array $overrides = [],
        bool $includeCovered = false,
    ): array {
        $this->assertApprovedMap($mapVersion, $workspace);

        $rows = [];
        $counts = [
            'write_new' => 0,
            'rewrite' => 0,
            'improve' => 0,
            'covered' => 0,
            'blocked' => 0,
            'needs_review' => 0,
        ];
        $warnings = [];
        $estimated = 0;

        foreach ($this->resolveClusters($workspace, $mapVersion, $selectedClusterRefs, $selectedTopicRefs) as $cluster) {
            $resolved = $this->actionResolver->resolve($cluster);
            $action = $resolved['action'];
            $override = $overrides[(string) $cluster->public_ref] ?? [];
            if (isset($override['item_type'])) {
                $action = (string) $override['item_type'];
            }
            if (isset($override['include']) && $override['include'] === false) {
                continue;
            }

            $action = $this->applyPolicy($action, $policy, $includeCovered, $warnings, (string) $cluster->public_ref);
            if ($action === null) {
                continue;
            }

            if (isset($counts[$action])) {
                $counts[$action]++;
            }

            $include = ! in_array($action, ['covered', 'blocked', 'needs_review'], true);
            if ($include) {
                $estimated++;
            }

            $rows[] = [
                'topic_ref' => $cluster->topic_id ? KeywordIntelligencePublicRef::topic((int) $cluster->topic_id) : null,
                'cluster_ref' => (string) $cluster->public_ref,
                'primary_keyword_ref' => $cluster->primary_keyword_id
                    ? KeywordIntelligencePublicRef::keyword((int) $cluster->primary_keyword_id)
                    : null,
                'primary_keyword' => (string) ($cluster->primaryKeyword?->keyword ?? $cluster->name),
                'suggested_title' => (string) ($override['title'] ?? $cluster->suggested_title ?? ''),
                'description' => (string) ($override['description'] ?? $cluster->suggested_description ?? ''),
                'suggested_content_type' => (string) ($cluster->suggested_content_type ?? 'article'),
                'content_project_item_type' => $action,
                'article_ref' => $override['article_ref'] ?? $resolved['article_ref'],
                'priority' => (float) ($override['priority'] ?? $cluster->priority_score ?? 50),
                'reason_codes' => $resolved['reason_codes'],
                'warnings' => [],
                'blocked_reason' => $action === 'blocked' ? implode(',', $resolved['reason_codes']) : null,
                'include' => $include,
                'eligible' => $include,
            ];
        }

        return [
            'selected_topic_count' => count(array_unique(array_filter(array_column($rows, 'topic_ref')))),
            'selected_cluster_count' => count($rows),
            'eligible_clusters' => $estimated,
            'write_new_count' => $counts['write_new'],
            'rewrite_count' => $counts['rewrite'],
            'improve_count' => $counts['improve'],
            'covered_count' => $counts['covered'],
            'blocked_count' => $counts['blocked'],
            'needs_review_count' => $counts['needs_review'],
            'warning_count' => count($warnings),
            'estimated_total_items' => $estimated,
            'requires_confirmation' => $this->quota->requiresConfirmation($estimated),
            'warnings' => $warnings,
            'items' => $rows,
            'map_version_ref' => (string) $mapVersion->public_ref,
            'workspace_ref' => (string) $workspace->public_ref,
            'policy' => $policy,
        ];
    }

    /**
     * Handler-compatible convert: builds preview payload internally from policy + cluster refs.
     *
     * @param  list<string>|null  $clusterRefs
     * @param  array<string, mixed>  $projectAttributes
     */
    public function convert(
        SeoKeywordWorkspace $workspace,
        SeoTopicalMapVersion $mapVersion,
        ActorContext $actor,
        ContentProjectCommandBus $bus,
        string $policy = 'new_only',
        ?array $clusterRefs = null,
        array $projectAttributes = [],
        ?string $idempotencyKey = null,
        string $targetMonth = '',
    ): ContentProjectActionResult {
        $previewPayload = $this->preview($workspace, $mapVersion, $policy, $clusterRefs);

        return $this->convertFromPreview(
            $workspace,
            $mapVersion,
            $actor,
            $bus,
            $previewPayload,
            $projectAttributes,
            $idempotencyKey,
            $targetMonth,
        );
    }

    /**
     * @param  array<string, mixed>  $previewPayload
     * @param  array<string, mixed>  $projectAttributes
     */
    public function convertFromPreview(
        SeoKeywordWorkspace $workspace,
        SeoTopicalMapVersion $mapVersion,
        ActorContext $actor,
        ContentProjectCommandBus $bus,
        array $previewPayload,
        array $projectAttributes = [],
        ?string $idempotencyKey = null,
        string $targetMonth = '',
    ): ContentProjectActionResult {
        $this->assertApprovedMap($mapVersion, $workspace);

        $selectedRefs = [];
        foreach ((array) ($previewPayload['items'] ?? []) as $item) {
            if (($item['include'] ?? false) === true
                && ! in_array((string) ($item['content_project_item_type'] ?? ''), ['covered', 'blocked', 'needs_review'], true)) {
                $selectedRefs[] = (string) $item['cluster_ref'];
            }
        }
        sort($selectedRefs);

        $idemHash = hash('sha256', implode('|', [
            (string) $workspace->tenant_id,
            (string) $workspace->site_id,
            (string) $mapVersion->id,
            implode(',', $selectedRefs),
            $targetMonth,
            (string) ($idempotencyKey ?? ''),
        ]));

        $existing = SeoKeywordProjectConversion::query()
            ->where('idempotency_key_hash', $idemHash)
            ->where('status', 'completed')
            ->first();
        if ($existing instanceof SeoKeywordProjectConversion) {
            return ContentProjectActionResult::ok(
                KeywordIntelligenceActionCodes::CONTENT_PROJECT_CREATED,
                'Idempotent conversion replay.',
                metadata: [
                    'conversion_ref' => (string) $existing->public_ref,
                    'content_project_ref' => $existing->content_project_ref,
                    'idempotent' => true,
                ],
            );
        }

        $maxItems = $this->configInt('seo-content-ai.keyword_intelligence.conversion.max_items_per_conversion', 500);
        if (count($selectedRefs) > $maxItems) {
            return ContentProjectActionResult::fail(
                'keyword.conversion_too_large',
                'Conversion exceeds max items.',
            );
        }

        if (! $this->quota->canConvert(count($selectedRefs))) {
            return ContentProjectActionResult::fail(
                KeywordIntelligenceActionCodes::CONVERSION_TOO_LARGE,
                'Cluster count exceeds convert quota.',
            );
        }

        $conversion = new SeoKeywordProjectConversion([
            'public_ref' => 'pending',
            'tenant_id' => $workspace->tenant_id,
            'site_id' => $workspace->site_id,
            'workspace_id' => $workspace->id,
            'topical_map_version_id' => $mapVersion->id,
            'status' => 'processing',
            'idempotency_key_hash' => $idemHash,
            'selected_cluster_refs' => $selectedRefs,
            'summary' => [
                'target_month' => $targetMonth,
                'item_count' => count($selectedRefs),
            ],
            'created_by' => $actor->actorId,
        ]);
        $conversion->save();
        $conversion->public_ref = KeywordIntelligencePublicRef::conversion((int) $conversion->id);
        $conversion->save();

        try {
            return DB::connection('omi_seo_ai')->transaction(function () use (
                $workspace,
                $mapVersion,
                $actor,
                $bus,
                $previewPayload,
                $projectAttributes,
                $conversion,
                $selectedRefs,
            ): ContentProjectActionResult {
                $tasksData = [];
                $clusterRows = [];
                $itemsByRef = [];
                foreach ((array) ($previewPayload['items'] ?? []) as $item) {
                    $itemsByRef[(string) ($item['cluster_ref'] ?? '')] = $item;
                }

                $clusters = SeoKeywordCluster::query()
                    ->with('primaryKeyword')
                    ->where('workspace_id', $workspace->id)
                    ->whereIn('public_ref', $selectedRefs !== [] ? $selectedRefs : ['__none__'])
                    ->get();

                $clusters = $clusters->sortBy(function (SeoKeywordCluster $c) use ($itemsByRef): array {
                    $item = $itemsByRef[(string) $c->public_ref] ?? [];
                    $type = (string) ($c->suggested_content_type ?? '');
                    $faqLast = $type === 'faq' ? 1 : 0;
                    $priority = -1 * (float) ($item['priority'] ?? $c->priority_score ?? 0);

                    return [$faqLast, $priority];
                })->values();

                foreach ($clusters as $cluster) {
                    $item = $itemsByRef[(string) $cluster->public_ref] ?? [];
                    $action = (string) ($item['content_project_item_type'] ?? 'write_new');

                    if ($action === 'improve' && trim((string) ($item['description'] ?? '')) === '') {
                        throw new InvalidArgumentException(KeywordIntelligenceActionCodes::CONVERSION_IMPROVE_DESCRIPTION_REQUIRED);
                    }

                    if (in_array($action, ['rewrite', 'improve'], true) && empty($item['article_ref'])) {
                        throw new InvalidArgumentException('keyword.conversion.article_target_required');
                    }

                    if ($cluster->converted_at !== null || $cluster->status === KeywordClusterStatus::Converted) {
                        $existingLink = SeoKeywordContentProjectLink::query()
                            ->where('cluster_id', $cluster->id)
                            ->where('relationship', 'origin')
                            ->exists();
                        if ($existingLink) {
                            continue;
                        }
                    }

                    $tasksData[] = $this->buildTaskRow($cluster, $item, $action, $mapVersion, $conversion);
                    $clusterRows[] = [$cluster, $item, $action];
                }

                if ($tasksData === []) {
                    $conversion->status = 'failed';
                    $conversion->summary = array_merge((array) $conversion->summary, ['error' => 'no_eligible_items']);
                    $conversion->save();

                    return ContentProjectActionResult::fail(
                        KeywordIntelligenceActionCodes::VALIDATION_FAILED,
                        'No eligible items for conversion.',
                    );
                }

                $attributes = array_merge([
                    'name' => trim((string) ($projectAttributes['name'] ?? ($workspace->name.' — Topical Map'))),
                    'site_id' => (int) $workspace->site_id,
                ], $projectAttributes);
                $attributes['site_id'] = (int) $workspace->site_id;
                unset($attributes['gallery_description']);

                $command = new CreateContentProjectCommand($attributes, $tasksData);
                $result = $bus->dispatch($command, $actor);

                if (! $result->success || $result->projectId === null) {
                    $conversion->status = 'failed';
                    $conversion->summary = array_merge((array) $conversion->summary, [
                        'error' => $result->message ?? 'create_project_failed',
                        'code' => $result->code ?? null,
                    ]);
                    $conversion->save();

                    return $result;
                }

                $projectRef = ContentProjectPublicRef::project($result->projectId);
                $conversion->content_project_ref = $projectRef;
                $conversion->status = 'completed';
                $conversion->summary = array_merge((array) $conversion->summary, [
                    'created_item_count' => count($tasksData),
                    'content_project_ref' => $projectRef,
                ]);
                $conversion->save();

                $now = now();
                foreach ($clusterRows as [$cluster, $item, $action]) {
                    /** @var SeoKeywordCluster $cluster */
                    $cluster->status = KeywordClusterStatus::Converted->value;
                    $cluster->content_project_ref = $projectRef;
                    $cluster->converted_at = $now;
                    $meta = (array) ($cluster->metadata ?? []);
                    $meta['source_snapshot'] = [
                        'keyword_workspace_ref' => (string) $workspace->public_ref,
                        'topical_map_version_ref' => (string) $mapVersion->public_ref,
                        'topic_ref' => $cluster->topic_id ? KeywordIntelligencePublicRef::topic((int) $cluster->topic_id) : null,
                        'cluster_ref' => (string) $cluster->public_ref,
                        'primary_keyword_ref' => $cluster->primary_keyword_id
                            ? KeywordIntelligencePublicRef::keyword((int) $cluster->primary_keyword_id)
                            : null,
                        'conversion_ref' => (string) $conversion->public_ref,
                        'source_priority' => $item['priority'] ?? $cluster->priority_score,
                        'source_content_type' => $cluster->suggested_content_type,
                    ];
                    $cluster->metadata = $meta;
                    $cluster->save();

                    $link = new SeoKeywordContentProjectLink([
                        'tenant_id' => $workspace->tenant_id,
                        'site_id' => $workspace->site_id,
                        'workspace_id' => $workspace->id,
                        'topical_map_version_id' => $mapVersion->id,
                        'topic_id' => $cluster->topic_id,
                        'cluster_id' => $cluster->id,
                        'keyword_id' => $cluster->primary_keyword_id,
                        'content_project_ref' => $projectRef,
                        'project_item_ref' => null,
                        'conversion_id' => $conversion->id,
                        'relationship' => match ($action) {
                            'rewrite' => 'rewrite_target',
                            'improve' => 'improve_target',
                            default => 'origin',
                        },
                    ]);
                    $link->save();
                }

                return ContentProjectActionResult::ok(
                    KeywordIntelligenceActionCodes::CONTENT_PROJECT_CREATED,
                    'Content project created from topical map version.',
                    $result->projectId,
                    metadata: array_merge($result->metadata, [
                        'conversion_ref' => (string) $conversion->public_ref,
                        'content_project_ref' => $projectRef,
                        'map_version_ref' => (string) $mapVersion->public_ref,
                        'workspace_ref' => (string) $workspace->public_ref,
                        'created_item_count' => count($tasksData),
                    ]),
                );
            });
        } catch (Throwable $e) {
            $conversion->status = 'failed';
            $conversion->summary = array_merge((array) $conversion->summary, [
                'error' => $e->getMessage(),
            ]);
            $conversion->save();

            return ContentProjectActionResult::fail(
                KeywordIntelligenceActionCodes::FAILED,
                'Conversion failed: '.$e->getMessage(),
            );
        }
    }

    private function assertApprovedMap(SeoTopicalMapVersion $mapVersion, SeoKeywordWorkspace $workspace): void
    {
        if ((int) $mapVersion->workspace_id !== (int) $workspace->id) {
            throw new InvalidArgumentException('keyword.conversion.map_workspace_mismatch');
        }
        $status = (string) ($mapVersion->status ?? '');
        if ($status !== KeywordTopicalMapVersionStatus::Approved->value) {
            throw new InvalidArgumentException('keyword.conversion.map_not_approved');
        }
    }

    /**
     * @param  list<string>|null  $selectedClusterRefs
     * @param  list<string>|null  $selectedTopicRefs
     * @return list<SeoKeywordCluster>
     */
    private function resolveClusters(
        SeoKeywordWorkspace $workspace,
        SeoTopicalMapVersion $mapVersion,
        ?array $selectedClusterRefs,
        ?array $selectedTopicRefs,
    ): array {
        $query = SeoKeywordCluster::query()
            ->with('primaryKeyword')
            ->where('workspace_id', $workspace->id);

        $snapshotAssignments = (array) (($mapVersion->snapshot['assignments'] ?? []) ?: []);
        $mapClusterRefs = [];
        foreach ($snapshotAssignments as $row) {
            if ((string) ($row['relationship'] ?? '') === 'primary') {
                $mapClusterRefs[] = (string) ($row['cluster_ref'] ?? '');
            }
        }
        $mapClusterRefs = array_values(array_filter(array_unique($mapClusterRefs)));
        if ($mapClusterRefs !== []) {
            $query->whereIn('public_ref', $mapClusterRefs);
        }

        if ($selectedClusterRefs !== null && $selectedClusterRefs !== []) {
            $query->whereIn('public_ref', $selectedClusterRefs);
        }
        if ($selectedTopicRefs !== null && $selectedTopicRefs !== []) {
            $topicIds = [];
            foreach ($selectedTopicRefs as $ref) {
                try {
                    $topicIds[] = KeywordIntelligencePublicRef::resolveTopicIdStrict((string) $ref);
                } catch (Throwable) {
                }
            }
            if ($topicIds !== []) {
                $query->whereIn('topic_id', $topicIds);
            }
        }

        return array_values($query->get()->all());
    }

    /**
     * @param  list<string>  $warnings
     */
    private function applyPolicy(string $action, string $policy, bool $includeCovered, array &$warnings, string $clusterRef): ?string
    {
        if ($action === 'blocked' || $action === 'needs_review') {
            return $action;
        }

        if ($action === 'covered') {
            if (! $includeCovered) {
                return 'covered';
            }
            $warnings[] = $clusterRef.': covered override requested — still needs explicit rewrite/improve evidence.';

            return 'covered';
        }

        return match ($policy) {
            'new_only' => $action === 'write_new' ? $action : null,
            'new_and_rewrite' => in_array($action, ['write_new', 'rewrite'], true) ? $action : null,
            'all_reviewed_actions', 'manual_selection' => $action,
            default => $action === 'write_new' ? $action : null,
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function buildTaskRow(
        SeoKeywordCluster $cluster,
        array $item,
        string $action,
        SeoTopicalMapVersion $mapVersion,
        SeoKeywordProjectConversion $conversion,
    ): array {
        $keyword = trim((string) ($item['primary_keyword'] ?? $cluster->primaryKeyword?->keyword ?? ''));
        $title = trim((string) ($item['suggested_title'] ?? $cluster->suggested_title ?? ''));
        $description = trim((string) ($item['description'] ?? $cluster->suggested_description ?? ''));

        $row = [
            'type' => $action === 'write_new' ? 'create' : $action,
            'description' => $description,
            'metadata' => [
                'topical_map_version_ref' => (string) $mapVersion->public_ref,
                'cluster_ref' => (string) $cluster->public_ref,
                'conversion_ref' => (string) $conversion->public_ref,
                'source_content_type' => (string) ($cluster->suggested_content_type ?? ''),
            ],
        ];

        if ($keyword !== '') {
            $row['keyword'] = $keyword;
        }
        if ($title !== '') {
            $row['title'] = $title;
        }

        if (in_array($action, ['rewrite', 'improve'], true)) {
            $articleRef = (string) ($item['article_ref'] ?? '');
            if ($articleRef !== '') {
                try {
                    $articleId = ContentProjectPublicRef::decodeArticle($articleRef);
                    $article = SeoArticle::query()->find($articleId);
                    if ($article instanceof SeoArticle) {
                        $row['source_content'] = (string) $article->title;
                        $row['source_article_ref'] = $articleRef;
                    }
                } catch (InvalidArgumentException) {
                }
            }
        }

        return $row;
    }

    private function configInt(string $key, int $default): int
    {
        if (! function_exists('config')) {
            return $default;
        }
        try {
            return max(1, (int) config($key, $default));
        } catch (Throwable) {
            return $default;
        }
    }
}
