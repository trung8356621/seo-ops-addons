<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Actions\Seo;

use Omnichannel\Addons\Agent\Automation\Contracts\BusinessAction;
use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Data\ActionDefinition;
use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Data\EventEnvelope;
use Omnichannel\Addons\Agent\Automation\Enums\ActionRiskLevel;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSelectability;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSideEffect;
use Omnichannel\Addons\Agent\Automation\Support\ActionSupport;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Services\SeoAuditScanService;

/**
 * Read-heavy SEO audit scan. Does not mutate article content or call WordPress.
 */
final class RunSeoAuditAction implements BusinessAction
{
    public function __construct(
        private readonly SeoAuditScanService $auditScan,
    ) {}

    public static function definition(): ActionDefinition
    {
        return new ActionDefinition(
            key: 'seo.audit.run',
            name: 'Run SEO audit scan',
            description: 'Scan/filter articles by SEO rule cache (read).',
            module: 'seo',
            sideEffect: ActionSideEffect::Read,
            riskLevel: ActionRiskLevel::Low,
            selectability: ActionSelectability::Selectable,
            inputSchema: [
                'site_id' => ['type' => 'integer', 'required' => true],
                'rule_keys' => ['type' => 'array', 'required' => false],
                'page' => ['type' => 'integer', 'required' => false],
                'per_page' => ['type' => 'integer', 'required' => false],
                'filter_low_seo_score' => ['type' => 'boolean', 'required' => false],
                'filter_technical_seo_score' => ['type' => 'boolean', 'required' => false],
            ],
            outputSchema: [
                'total' => ['type' => 'integer'],
                'items' => ['type' => 'array'],
            ],
            supportsDryRun: true,
            emittedEvents: ['seo.audit_completed'],
        );
    }

    public function execute(ActionContext $context, array $input): ActionResult
    {
        $siteId = (int) ($input['site_id'] ?? $context->siteId ?? 0);
        if ($siteId <= 0) {
            return ActionResult::failure('invalid_input', 'site_id is required.');
        }

        /** @var list<string> $ruleKeys */
        $ruleKeys = [];
        if (is_array($input['rule_keys'] ?? null)) {
            foreach ($input['rule_keys'] as $key) {
                if (is_string($key) && $key !== '') {
                    $ruleKeys[] = $key;
                }
            }
        }

        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($input['per_page'] ?? 15)));
        $filterLow = (bool) ($input['filter_low_seo_score'] ?? false);
        $filterTechnical = (bool) ($input['filter_technical_seo_score'] ?? false);

        if ($context->dryRun) {
            return ActionResult::success(
                output: ['site_id' => $siteId, 'dry_run' => true, 'rule_keys' => $ruleKeys],
                status: \Omnichannel\Addons\Agent\Automation\Enums\ActionRunStatus::DryRun,
            );
        }

        $baseQuery = SeoArticle::query()
            ->where('site_id', $siteId)
            ->where(static function ($query): void {
                $query->whereNull('skip_seo_score')->orWhere('skip_seo_score', false);
            });

        $paginator = $this->auditScan->paginateResults(
            $baseQuery,
            $ruleKeys,
            $filterLow,
            $filterTechnical,
            $page,
            $perPage,
        );

        /** @var list<array<string, mixed>> $items */
        $items = [];
        foreach ($paginator->items() as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return ActionResult::success(
            output: [
                'site_id' => $siteId,
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'items' => $items,
            ],
            events: [
                EventEnvelope::make(
                    eventKey: 'seo.audit_completed',
                    entity: ['type' => 'site', 'id' => $siteId],
                    context: [
                        'correlation_id' => $context->correlationId,
                        'origin' => $context->origin,
                        'site_id' => $siteId,
                        'actor_id' => $context->actorId,
                    ],
                    payload: [
                        'total' => $paginator->total(),
                        'rule_keys' => $ruleKeys,
                    ],
                ),
            ],
        );
    }
}
