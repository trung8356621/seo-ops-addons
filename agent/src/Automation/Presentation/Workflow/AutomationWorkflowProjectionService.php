<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Presentation\Workflow;

use Omnichannel\Addons\Agent\Automation\Presentation\AutomationFlowCatalog;
use Omnichannel\Addons\Agent\Automation\Presentation\AutomationFlowPresentationRegistry;

/**
 * Public read API for Automation Flows Phase 2 UI.
 */
final class AutomationWorkflowProjectionService
{
    public function __construct(
        private readonly AutomationFlowCatalog $catalog,
        private readonly AutomationWorkflowMapBuilder $builder,
        private readonly AutomationFlowPresentationRegistry $presentation,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listWorkflows(
        ?string $category = null,
        ?string $mapping = null,
        ?string $health = null,
    ): array {
        return $this->builder->listWorkflows($category, $mapping, $health);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findWorkflow(string $workflowId): ?array
    {
        return $this->builder->findWorkflow($workflowId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listComponents(
        ?string $category = null,
        ?string $eventName = null,
        ?string $health = null,
    ): array {
        $flows = $this->catalog->listFlows($category, $eventName, $health);

        return array_map(function (array $flow): array {
            $flow['status_label'] = ($flow['last_status'] ?? null) !== null
                ? $this->presentation->statusLabel((string) $flow['last_status'])
                : $this->presentation->statusLabel('never');
            $flow['category_label'] = $this->presentation->categoryLabel((string) ($flow['category'] ?? ''));
            $incomplete = (int) ($flow['step_count'] ?? 0) <= 1
                && in_array(($flow['source'] ?? ''), ['business_event', 'content_project_capability'], true);
            $flow['mapping_incomplete'] = $incomplete;
            if ($incomplete) {
                $flow['step_label'] = __('seo-content-ai::filament.automation.flows.single_component_label');
            } else {
                $flow['step_label'] = __('seo-content-ai::filament.automation.flows.steps_count', [
                    'count' => (int) ($flow['step_count'] ?? 0),
                ]);
            }

            return $flow;
        }, $flows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findComponent(string $flowId): ?array
    {
        return $this->catalog->findFlow($flowId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listUnmapped(): array
    {
        return $this->builder->listUnmappedComponents();
    }

    /**
     * @return array{workflows: int, components: int, mapped: int, unmapped: int}
     */
    public function summary(): array
    {
        return $this->builder->inventorySummary();
    }

    /**
     * @return array<string, string>
     */
    public function workflowCategoryOptions(): array
    {
        $cats = [];
        foreach ($this->listWorkflows() as $workflow) {
            $cat = (string) ($workflow['category'] ?? '');
            if ($cat !== '') {
                $cats[$cat] = $this->presentation->categoryLabel($cat);
            }
        }
        asort($cats);

        return $cats;
    }

    /**
     * @return array<string, string>
     */
    public function componentCategoryOptions(): array
    {
        return $this->catalog->categoryOptions();
    }

    /**
     * @return array<string, string>
     */
    public function componentEventOptions(): array
    {
        return $this->catalog->eventOptions();
    }
}
