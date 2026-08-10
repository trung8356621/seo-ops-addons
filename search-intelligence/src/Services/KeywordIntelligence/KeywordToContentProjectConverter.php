<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordClusterStatus;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
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
use InvalidArgumentException;

/**
 * Chuyển approved keyword clusters thành Content Project (write_new mặc định,
 * hoặc rewrite/improve khi cluster có target_article_ref + suggested_content_type
 * tương ứng đã được set tường minh).
 */
final class KeywordToContentProjectConverter
{
    public function __construct(
        private readonly KeywordIntelligenceQuotaGuard $quota,
    ) {}

    /**
     * @param  list<string>  $clusterRefs
     * @return array<string, mixed>
     */
    public function preview(SeoKeywordWorkspace $workspace, array $clusterRefs): array
    {
        $clusters = $this->resolveClusters($workspace, $clusterRefs);

        $items = [];
        $eligible = 0;
        $totalKeywords = 0;
        $warnings = [];

        foreach ($clusters as $cluster) {
            [$contentType, $reason] = $this->resolveContentType($cluster);
            $isApproved = $cluster->status === KeywordClusterStatus::Approved;
            $isEligible = $isApproved && $reason === null;

            if (! $isEligible) {
                $warnings[] = $cluster->public_ref.': '.($reason ?? 'cluster status must be approved (current: '.(string) ($cluster->status?->value).').');
            } else {
                $eligible++;
            }

            $totalKeywords += (int) $cluster->keyword_count;

            $items[] = [
                'cluster_ref' => $cluster->public_ref,
                'name' => $cluster->name,
                'status' => $cluster->status?->value,
                'keyword_count' => (int) $cluster->keyword_count,
                'search_intent' => $cluster->search_intent?->value,
                'content_type' => $contentType,
                'target_article_ref' => $cluster->target_article_ref,
                'eligible' => $isEligible,
            ];
        }

        $clusterCount = count($clusters);

        return [
            'total_clusters' => $clusterCount,
            'eligible_clusters' => $eligible,
            'total_keywords' => $totalKeywords,
            'items' => $items,
            'warnings' => $warnings,
            'quota_exceeded' => ! $this->quota->canConvert($clusterCount),
            'requires_confirmation' => $this->quota->requiresConfirmation($clusterCount),
        ];
    }

    /**
     * @param  list<string>  $clusterRefs
     * @param  array<string, mixed>  $projectAttributes
     */
    public function convert(
        SeoKeywordWorkspace $workspace,
        array $clusterRefs,
        ActorContext $actor,
        ContentProjectCommandBus $bus,
        array $projectAttributes = [],
    ): ContentProjectActionResult {
        $clusters = $this->resolveClusters($workspace, $clusterRefs);
        $eligibleClusters = [];
        $tasksData = [];
        $warnings = [];

        foreach ($clusters as $cluster) {
            if ($cluster->status !== KeywordClusterStatus::Approved) {
                $warnings[] = $cluster->public_ref.': skipped, cluster is not approved.';

                continue;
            }

            [$contentType, $reason] = $this->resolveContentType($cluster);
            if ($reason !== null) {
                $warnings[] = $cluster->public_ref.': '.$reason;

                continue;
            }

            $tasksData[] = $this->buildTaskRow($cluster, $contentType);
            $eligibleClusters[] = $cluster;
        }

        if ($eligibleClusters === []) {
            return ContentProjectActionResult::fail(
                KeywordIntelligenceActionCodes::VALIDATION_FAILED,
                'No approved clusters eligible for conversion.',
                warnings: $warnings,
            );
        }

        if (! $this->quota->canConvert(count($eligibleClusters))) {
            return ContentProjectActionResult::fail(
                KeywordIntelligenceActionCodes::CONVERSION_TOO_LARGE,
                'Too many clusters for a single conversion.',
            );
        }

        $attributes = array_merge([
            'name' => trim((string) $workspace->name).' — Keyword Intelligence',
            'site_id' => $workspace->site_id,
        ], $projectAttributes);
        $attributes['site_id'] = (int) $workspace->site_id;

        $command = new CreateContentProjectCommand($attributes, $tasksData);
        $result = $bus->dispatch($command, $actor);

        if (! $result->success || $result->projectId === null) {
            return $result;
        }

        $projectRef = ContentProjectPublicRef::project($result->projectId);
        $now = now();

        foreach ($eligibleClusters as $cluster) {
            $cluster->status = KeywordClusterStatus::Converted->value;
            $cluster->content_project_ref = $projectRef;
            $cluster->converted_at = $now;
            $cluster->save();
        }

        return ContentProjectActionResult::ok(
            KeywordIntelligenceActionCodes::CONTENT_PROJECT_CREATED,
            'Content project created from keyword clusters.',
            $result->projectId,
            metadata: array_merge($result->metadata, [
                'ki_workspace_ref' => $workspace->public_ref,
                'ki_cluster_refs' => array_map(static fn (SeoKeywordCluster $c): ?string => $c->public_ref, $eligibleClusters),
                'ki_keyword_refs' => array_map(
                    static fn (SeoKeywordCluster $c): ?string => $c->primaryKeyword?->public_ref,
                    $eligibleClusters,
                ),
                'ki_map_version_ref' => $this->latestMapVersionRef($workspace),
            ]),
            warnings: $warnings,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTaskRow(SeoKeywordCluster $cluster, string $contentType): array
    {
        $row = [
            'type' => $contentType === 'write_new' ? 'create' : $contentType,
            'keyword' => (string) ($cluster->primaryKeyword?->keyword ?? $cluster->name),
            'title' => (string) ($cluster->suggested_title ?: ($cluster->primaryKeyword?->keyword ?? $cluster->name)),
            'description' => (string) ($cluster->suggested_description ?? ''),
        ];

        if ($contentType !== 'write_new' && $cluster->target_article_ref !== null && $cluster->target_article_ref !== '') {
            try {
                $articleId = ContentProjectPublicRef::decodeArticle((string) $cluster->target_article_ref);
                $article = SeoArticle::query()->find($articleId);
                if ($article instanceof SeoArticle) {
                    $row['source_content'] = (string) $article->title;
                }
            } catch (InvalidArgumentException) {
                // Invalid ref — row simply lacks source_content; sync layer will reject it.
            }
        }

        return $row;
    }

    /**
     * @param  list<string>  $clusterRefs
     * @return list<SeoKeywordCluster>
     */
    private function resolveClusters(SeoKeywordWorkspace $workspace, array $clusterRefs): array
    {
        $ids = [];
        foreach ($clusterRefs as $ref) {
            $ids[] = KeywordIntelligencePublicRef::resolveClusterIdStrict((string) $ref);
        }

        $query = SeoKeywordCluster::query()->where('workspace_id', $workspace->id);
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        return array_values($query->get()->all());
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function resolveContentType(SeoKeywordCluster $cluster): array
    {
        $type = trim((string) ($cluster->suggested_content_type ?: 'write_new'));

        $hasTarget = $cluster->target_article_ref !== null && $cluster->target_article_ref !== '';

        if ($hasTarget) {
            if (! in_array($type, ['rewrite', 'improve'], true)) {
                return [$type !== '' ? $type : 'write_new', 'target_article_ref is set but suggested_content_type must be explicitly "rewrite" or "improve".'];
            }

            return [$type, null];
        }

        if (in_array($type, ['rewrite', 'improve'], true)) {
            // No target article to rewrite/improve — fall back to write_new safely.
            return ['write_new', null];
        }

        return ['write_new', null];
    }

    private function latestMapVersionRef(SeoKeywordWorkspace $workspace): ?string
    {
        $latest = SeoTopicalMapVersion::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('version')
            ->first();

        return $latest?->public_ref;
    }
}
