<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscContentAction;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscMappingStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscOpportunityStatus;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscOpportunity;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscPageMapping;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscProperty;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscQueryMapping;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\CreateContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\GscIntelligenceActionCodes;

/**
 * Convert approved GSC opportunities → Content Project via CreateContentProjectCommand.
 * Never uses gallery_description — improve_description / rewrite_brief only.
 */
final class GscOpportunityContentProjectConverter
{
    public const ALGORITHM_VERSION = '1.0.0';

    public function __construct(
        private readonly GscContentActionRecommendationService $recommendations,
        private readonly GscContentProjectPreviewBuilder $previewBuilder,
    ) {}

    /**
     * @param  list<SeoGscOpportunity>  $opportunities
     * @param  array<string, mixed>  $projectAttributes
     * @return array<string, mixed>
     */
    public function preview(SeoGscProperty $property, array $opportunities, array $projectAttributes = []): array
    {
        $items = [];
        $skipped = [];
        $warnings = [];

        foreach ($opportunities as $opportunity) {
            $built = $this->buildItemFromOpportunity($property, $opportunity);
            if (($built['skip'] ?? false) === true) {
                $skipped[] = $built['reason'];
                continue;
            }

            if (($built['warning'] ?? '') !== '') {
                $warnings[] = $built['warning'];
            }

            $items[] = $built['item'];
        }

        return [
            'property_ref' => $property->public_ref,
            'eligible_items' => count($items),
            'skipped_count' => count($skipped),
            'skipped_reasons' => $skipped,
            'warnings' => $warnings,
            'tasks_data' => $items,
            'project_attributes' => $projectAttributes,
            'algorithm_version' => self::ALGORITHM_VERSION,
        ];
    }

    /**
     * @param  list<SeoGscOpportunity>  $opportunities
     * @param  array<string, mixed>  $projectAttributes
     */
    public function convert(
        SeoGscProperty $property,
        array $opportunities,
        ActorContext $actor,
        ContentProjectCommandBus $bus,
        array $projectAttributes = [],
        ?string $idempotencyKey = null,
    ): ContentProjectActionResult {
        $preview = $this->preview($property, $opportunities, $projectAttributes);
        $tasksData = is_array($preview['tasks_data'] ?? null) ? $preview['tasks_data'] : [];

        if ($tasksData === []) {
            return ContentProjectActionResult::fail(
                GscIntelligenceActionCodes::VALIDATION_FAILED,
                'No eligible GSC opportunities for content project conversion.',
                warnings: (array) ($preview['warnings'] ?? []),
            );
        }

        $attributes = array_merge([
            'site_id' => (int) $property->site_id,
            'name' => (string) ($projectAttributes['name'] ?? 'GSC Opportunities Project'),
            'source' => 'gsc_intelligence',
            'gsc_property_ref' => $property->public_ref,
        ], $projectAttributes);

        unset($attributes['gallery_description']);

        $actorWithKey = new ActorContext(
            $actor->actorType,
            $actor->actorId,
            $actor->siteId ?? (int) $property->site_id,
            $idempotencyKey ?? $actor->idempotencyKey,
            $actor->correlationId,
            $actor->dryRun,
            $actor->confirmationToken,
        );

        $result = $bus->dispatch(new CreateContentProjectCommand($attributes, $tasksData), $actorWithKey);

        if (! $result->success) {
            return $result;
        }

        return ContentProjectActionResult::ok(
            GscIntelligenceActionCodes::CONVERSION_CREATED,
            'Content project created from GSC opportunities.',
            projectId: $result->projectId,
            metadata: array_merge(
                (array) ($result->metadata ?? []),
                ['preview' => $preview, 'property_ref' => $property->public_ref],
            ),
        );
    }

    /**
     * @return array{item: array<string, mixed>, skip?: bool, reason?: string, warning?: string}
     */
    private function buildItemFromOpportunity(SeoGscProperty $property, SeoGscOpportunity $opportunity): array
    {
        $status = $opportunity->status;
        if ($status === GscOpportunityStatus::Ignored || $status === GscOpportunityStatus::Stale) {
            return ['item' => [], 'skip' => true, 'reason' => 'opportunity_ignored_or_stale'];
        }

        $evidence = is_array($opportunity->evidence) ? $opportunity->evidence : [];
        $metrics = is_array($evidence['metrics'] ?? null) ? $evidence['metrics'] : [];
        $query = (string) ($evidence['query'] ?? $evidence['normalized_query'] ?? '');

        $queryMapping = $this->resolveQueryMapping($property, $opportunity);
        $pageMapping = $this->resolvePageMapping($property, $opportunity);

        $manualQuery = $queryMapping !== null
            && $queryMapping->status === GscMappingStatus::Approved
            && ($queryMapping->metadata['manual'] ?? false) === true;

        $manualPage = $pageMapping !== null
            && $pageMapping->status === GscMappingStatus::Approved
            && ($pageMapping->metadata['manual'] ?? false) === true;

        $reviewedEvidence = in_array($status, [GscOpportunityStatus::Accepted, GscOpportunityStatus::Reviewed, GscOpportunityStatus::Resolved], true);

        $context = [
            'query' => $query,
            'display_query' => $query,
            'opportunities' => [['type' => $opportunity->opportunity_type?->value ?? (string) $opportunity->opportunity_type]],
            'article_ref' => $pageMapping?->article_ref,
            'keyword_ref' => $queryMapping?->keyword_id !== null
                ? \Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef::keyword((int) $queryMapping->keyword_id)
                : null,
            'reviewed_evidence' => $reviewedEvidence,
            'manual_query_mapping' => $manualQuery,
            'manual_page_mapping' => $manualPage,
        ];

        $recommendation = $this->recommendations->recommend($metrics, $context);
        $action = $recommendation['action'];
        $actionValue = $action instanceof GscContentAction ? $action->value : (string) $action;

        if (in_array($actionValue, [GscContentAction::Rewrite->value, GscContentAction::Improve->value], true) && ! $reviewedEvidence) {
            return [
                'item' => [],
                'skip' => true,
                'reason' => 'rewrite_improve_requires_reviewed_opportunity',
            ];
        }

        if ($actionValue === GscContentAction::Blocked->value) {
            return ['item' => [], 'skip' => true, 'reason' => 'blocked_action'];
        }

        $item = $this->previewBuilder->build(
            [
                'action' => $actionValue,
                'reason_codes' => $recommendation['reason_codes'] ?? [],
                'article_ref' => $recommendation['article_ref'] ?? null,
            ],
            $metrics,
            $context,
        );

        $item['gsc_opportunity_ref'] = $opportunity->public_ref;
        unset($item['gallery_description']);

        $warning = '';
        if ($manualQuery || $manualPage) {
            $warning = 'Manual mappings preserved for opportunity '.$opportunity->public_ref;
        }

        return ['item' => $item, 'warning' => $warning];
    }

    private function resolveQueryMapping(SeoGscProperty $property, SeoGscOpportunity $opportunity): ?SeoGscQueryMapping
    {
        $ref = trim((string) ($opportunity->query_mapping_ref ?? ''));
        if ($ref === '') {
            return null;
        }

        $mapping = SeoGscQueryMapping::query()
            ->where('property_id', $property->id)
            ->where('public_ref', $ref)
            ->first();

        return $mapping instanceof SeoGscQueryMapping ? $mapping : null;
    }

    private function resolvePageMapping(SeoGscProperty $property, SeoGscOpportunity $opportunity): ?SeoGscPageMapping
    {
        $ref = trim((string) ($opportunity->page_mapping_ref ?? ''));
        if ($ref === '') {
            return null;
        }

        $mapping = SeoGscPageMapping::query()
            ->where('property_id', $property->id)
            ->where('public_ref', $ref)
            ->first();

        return $mapping instanceof SeoGscPageMapping ? $mapping : null;
    }
}
