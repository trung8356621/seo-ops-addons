<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Actions\Article;

use Omnichannel\Addons\Agent\Automation\Contracts\BusinessAction;
use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Data\ActionDefinition;
use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Data\EventEnvelope;
use Omnichannel\Addons\Agent\Automation\Enums\ActionRiskLevel;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSelectability;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSideEffect;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection\GscUrlInspectionPolicy;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection\GscUrlInspectionRunService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Queue bounded due Index Health batch via GSC URL Inspection.
 * Must not claim final counts while queued.
 */
final class InspectDueArticleIndexesWithGscAction implements BusinessAction
{
    public function __construct(
        private readonly GscUrlInspectionRunService $runs = new GscUrlInspectionRunService,
    ) {}

    public static function definition(): ActionDefinition
    {
        return new ActionDefinition(
            key: 'article.index_health.inspect_due_gsc',
            name: 'Inspect due Index Health with GSC',
            description: 'Queue a bounded batch of due published URLs for GSC URL Inspection. Returns run_id while queued — do not invent final index counts.',
            module: 'article',
            sideEffect: ActionSideEffect::ExternalWrite,
            riskLevel: ActionRiskLevel::Medium,
            selectability: ActionSelectability::Selectable,
            inputSchema: [
                'site_id' => ['type' => 'integer', 'required' => true],
                'limit' => ['type' => 'integer', 'required' => false],
            ],
            outputSchema: [
                'queued' => ['type' => 'boolean'],
                'run_id' => ['type' => 'integer'],
                'requested' => ['type' => 'integer'],
                'status' => ['type' => 'string'],
            ],
            idempotent: false,
            lockScope: 'site',
            supportsDryRun: true,
            emittedEvents: ['article.index_health_gsc_inspection_queued'],
        );
    }

    public function execute(ActionContext $context, array $input): ActionResult
    {
        $siteId = (int) ($input['site_id'] ?? $context->siteId ?? 0);
        if ($siteId <= 0) {
            return ActionResult::failure('invalid_input', 'site_id is required.');
        }

        if (! SeoAccessControl::canAccessSite($siteId)) {
            return ActionResult::failure('forbidden', 'Access denied.');
        }

        $limit = isset($input['limit'])
            ? GscUrlInspectionPolicy::clampLimit((int) $input['limit'])
            : GscUrlInspectionPolicy::DEFAULT_BATCH_LIMIT;

        if ($context->dryRun) {
            return ActionResult::success(
                output: ['site_id' => $siteId, 'limit' => $limit, 'dry_run' => true, 'queued' => true],
                status: \Omnichannel\Addons\Agent\Automation\Enums\ActionRunStatus::DryRun,
            );
        }

        $result = $this->runs->queueDue(
            $siteId,
            ($context->actorId ?? 0) > 0 ? (int) $context->actorId : null,
            $limit,
        );

        if (! ($result['ok'] ?? false)) {
            return ActionResult::failure(
                (string) ($result['error_code'] ?? 'gsc.failed'),
                (string) ($result['error_message'] ?? 'Failed to queue GSC URL Inspection.'),
            );
        }

        return ActionResult::success(
            output: [
                'site_id' => $siteId,
                'queued' => (bool) ($result['queued'] ?? true),
                'run_id' => $result['run_id'] ?? null,
                'public_ref' => $result['public_ref'] ?? null,
                'status' => $result['status'] ?? 'queued',
                'requested' => $result['requested'] ?? null,
                'inspected' => $result['inspected'] ?? null,
                'indexed' => $result['indexed'] ?? null,
                'not_indexed' => $result['not_indexed'] ?? null,
                'unknown' => $result['unknown'] ?? null,
                'failed' => $result['failed'] ?? null,
            ],
            events: [
                EventEnvelope::make(
                    eventKey: 'article.index_health_gsc_inspection_queued',
                    entity: ['type' => 'site', 'id' => $siteId],
                    context: [
                        'correlation_id' => $context->correlationId,
                        'origin' => $context->origin,
                        'site_id' => $siteId,
                        'actor_id' => $context->actorId,
                    ],
                    payload: [
                        'run_id' => $result['run_id'] ?? null,
                        'queued' => (bool) ($result['queued'] ?? true),
                    ],
                ),
            ],
        );
    }
}
