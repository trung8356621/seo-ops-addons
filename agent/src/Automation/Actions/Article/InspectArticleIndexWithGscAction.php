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
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection\GscUrlInspectionService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Inspect one published article URL via GSC URL Inspection → Index Health recorder.
 */
final class InspectArticleIndexWithGscAction implements BusinessAction
{
    public function __construct(
        private readonly GscUrlInspectionService $inspection = new GscUrlInspectionService,
    ) {}

    public static function definition(): ActionDefinition
    {
        return new ActionDefinition(
            key: 'article.index_health.inspect_gsc',
            name: 'Inspect article index with GSC',
            description: 'Call Google URL Inspection for one published article and record Index Health (source=gsc_url_inspection). Does not use Search Analytics.',
            module: 'article',
            sideEffect: ActionSideEffect::ExternalWrite,
            riskLevel: ActionRiskLevel::Medium,
            selectability: ActionSelectability::Selectable,
            inputSchema: [
                'article_id' => ['type' => 'integer', 'required' => true],
            ],
            outputSchema: [
                'article_id' => ['type' => 'integer'],
                'check_status' => ['type' => 'string'],
                'effective_health' => ['type' => 'string'],
                'queued' => ['type' => 'boolean'],
            ],
            idempotent: false,
            lockScope: 'article',
            supportsDryRun: true,
            emittedEvents: ['article.index_health_gsc_inspected'],
        );
    }

    public function execute(ActionContext $context, array $input): ActionResult
    {
        $articleId = (int) ($input['article_id'] ?? 0);
        if ($articleId <= 0) {
            return ActionResult::failure('invalid_input', 'article_id is required.');
        }

        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            return ActionResult::failure('not_found', 'Article not found.');
        }

        if (! SeoAccessControl::canAccessArticle($article)) {
            return ActionResult::failure('forbidden', 'Access denied.');
        }

        if ($context->dryRun) {
            return ActionResult::success(
                output: ['article_id' => $articleId, 'dry_run' => true, 'queued' => false],
                status: \Omnichannel\Addons\Agent\Automation\Enums\ActionRunStatus::DryRun,
            );
        }

        $result = $this->inspection->inspectArticle(
            $articleId,
            ($context->actorId ?? 0) > 0 ? (int) $context->actorId : null,
        );

        if (! ($result['ok'] ?? false)) {
            return ActionResult::failure(
                (string) ($result['error_code'] ?? 'gsc.failed'),
                (string) ($result['error_message'] ?? 'GSC URL Inspection failed.'),
            );
        }

        return ActionResult::success(
            output: [
                'article_id' => $articleId,
                'site_id' => (int) ($result['site_id'] ?? 0),
                'check_status' => $result['check_status'] ?? null,
                'effective_health' => $result['effective_health'] ?? null,
                'check_id' => $result['check_id'] ?? null,
                'source' => $result['source'] ?? null,
                'queued' => false,
                'transitioned_to_dropped' => (bool) ($result['transitioned_to_dropped'] ?? false),
                'recovered_from_dropped' => (bool) ($result['recovered_from_dropped'] ?? false),
            ],
            events: [
                EventEnvelope::make(
                    eventKey: 'article.index_health_gsc_inspected',
                    entity: ['type' => 'article', 'id' => $articleId],
                    context: [
                        'correlation_id' => $context->correlationId,
                        'origin' => $context->origin,
                        'site_id' => (int) ($result['site_id'] ?? 0),
                        'actor_id' => $context->actorId,
                    ],
                    payload: [
                        'check_status' => $result['check_status'] ?? null,
                        'effective_health' => $result['effective_health'] ?? null,
                    ],
                ),
            ],
        );
    }
}
