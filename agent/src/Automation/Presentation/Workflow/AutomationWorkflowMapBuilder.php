<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Presentation\Workflow;

use Omnichannel\Addons\Agent\Automation\Presentation\AutomationFlowCatalog;
use Omnichannel\Addons\Agent\Automation\Presentation\AutomationFlowPresentationRegistry;

/**
 * Builds high-level workflow projections from declarative maps + registered components.
 * Read-model only — does not execute automation.
 */
final class AutomationWorkflowMapBuilder
{
    public function __construct(
        private readonly AutomationFlowCatalog $catalog,
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
        $components = $this->catalog->listFlows();
        $index = $this->indexComponents($components);
        $workflows = [];

        foreach (AutomationWorkflowMapDefinitions::all() as $definition) {
            $projected = $this->projectWorkflow($definition, $index);
            if ($category !== null && $category !== '' && ($projected['category'] ?? '') !== $category) {
                continue;
            }
            if ($mapping === 'complete' && ($projected['mapping_status'] ?? '') !== 'mapped') {
                continue;
            }
            if ($mapping === 'partial' && ($projected['mapping_status'] ?? '') !== 'partial') {
                continue;
            }
            if ($health === 'never' && ($projected['last_status'] ?? null) !== null) {
                continue;
            }
            if ($health === 'has_runs' && ($projected['last_status'] ?? null) === null) {
                continue;
            }
            if ($health === 'failed' && ($projected['last_status'] ?? '') !== 'failed') {
                continue;
            }
            if ($health === 'processing' && ! in_array($projected['last_status'] ?? '', ['pending', 'processing'], true)) {
                continue;
            }
            $workflows[] = $projected;
        }

        return $workflows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findWorkflow(string $workflowId): ?array
    {
        foreach ($this->listWorkflows() as $workflow) {
            if (($workflow['id'] ?? '') === $workflowId) {
                return $workflow;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listUnmappedComponents(): array
    {
        $mappedKeys = $this->mappedComponentKeys();
        $unmapped = [];

        foreach ($this->catalog->listFlows() as $component) {
            if ($this->componentIsMapped($component, $mappedKeys)) {
                continue;
            }

            $unmapped[] = [
                ...$component,
                'unmapped_reason' => __('seo-content-ai::filament.automation.flows.unmapped_reason'),
                'status_label' => ($component['last_status'] ?? null) !== null
                    ? $this->presentation->statusLabel((string) $component['last_status'])
                    : $this->presentation->statusLabel('never'),
                'category_label' => $this->presentation->categoryLabel((string) ($component['category'] ?? '')),
            ];
        }

        return $unmapped;
    }

    /**
     * @return array{workflows: int, components: int, mapped: int, unmapped: int}
     */
    public function inventorySummary(): array
    {
        $components = $this->catalog->listFlows();
        $mappedKeys = $this->mappedComponentKeys();
        $mapped = 0;
        foreach ($components as $component) {
            if ($this->componentIsMapped($component, $mappedKeys)) {
                $mapped++;
            }
        }

        return [
            'workflows' => count(AutomationWorkflowMapDefinitions::all()),
            'components' => count($components),
            'mapped' => $mapped,
            'unmapped' => count($components) - $mapped,
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array{by_id: array<string, array<string, mixed>>, by_code: array<string, array<string, mixed>>, by_event: array<string, list<array<string, mixed>>>}  $index
     * @return array<string, mixed>
     */
    private function projectWorkflow(array $definition, array $index): array
    {
        $nodes = [];
        $matchedComponentIds = [];
        $lastStatus = null;
        $lastRunAt = null;
        $lastError = null;
        $queuedTransitions = 0;

        foreach ($definition['edges'] as $edge) {
            if (($edge['type'] ?? '') === 'queued') {
                $queuedTransitions++;
            }
        }

        foreach ($definition['nodes'] as $nodeDef) {
            $matched = $this->resolveNodeComponents($nodeDef, $index);
            foreach ($matched as $component) {
                $matchedComponentIds[(string) ($component['id'] ?? '')] = true;
                $status = $component['last_status'] ?? null;
                $runAt = $component['last_run_at'] ?? null;
                if ($runAt !== null && ($lastRunAt === null || (string) $runAt > (string) $lastRunAt)) {
                    $lastRunAt = $runAt;
                    $lastStatus = is_string($status) ? $status : null;
                    $lastError = $component['last_error'] ?? null;
                } elseif ($lastStatus === null && is_string($status)) {
                    $lastStatus = $status;
                    $lastError = $component['last_error'] ?? null;
                }
            }

            $label = __($nodeDef['label_key']);
            if ($label === $nodeDef['label_key']) {
                $label = $this->presentation->fallbackLabel((string) $nodeDef['canonical']);
            }

            $nodes[] = [
                'id' => $nodeDef['id'],
                'canonical' => $nodeDef['canonical'],
                'type' => $nodeDef['type'],
                'label' => $label,
                'evidence' => $nodeDef['evidence'],
                'run_mode' => $nodeDef['run_mode'] ?? null,
                'optional' => (bool) ($nodeDef['optional'] ?? false),
                'matched_components' => array_values(array_map(
                    static fn (array $c): array => [
                        'id' => $c['id'] ?? null,
                        'code' => $c['code'] ?? null,
                        'source' => $c['source'] ?? null,
                        'last_status' => $c['last_status'] ?? null,
                        'last_run_at' => $c['last_run_at'] ?? null,
                    ],
                    $matched,
                )),
                'registered' => $matched !== [],
            ];
        }

        $registeredNodeCount = count(array_filter($nodes, static fn (array $n): bool => $n['registered'] === true));
        $nodeCount = count($nodes);
        $mappingStatus = match (true) {
            $registeredNodeCount === 0 => 'unmapped',
            $registeredNodeCount < $nodeCount => 'partial',
            default => 'mapped',
        };

        $name = __($definition['name_key']);
        if ($name === $definition['name_key']) {
            $name = $this->presentation->fallbackLabel((string) $definition['id']);
        }
        $description = __($definition['description_key']);
        if ($description === $definition['description_key']) {
            $description = '';
        }

        return [
            'id' => $definition['id'],
            'category' => $definition['category'],
            'category_label' => $this->presentation->categoryLabel((string) $definition['category']),
            'name' => $name,
            'description' => $description,
            'definition_sources' => $definition['definition_sources'],
            'component_count' => count($matchedComponentIds),
            'step_count' => $nodeCount,
            'queued_transitions' => $queuedTransitions,
            'mapping_status' => $mappingStatus,
            'mapping_label' => $this->presentation->mappingLabel($mappingStatus),
            'last_status' => $lastStatus,
            'last_run_at' => $lastRunAt,
            'last_error' => $lastError,
            'status_label' => $lastStatus !== null
                ? $this->presentation->statusLabel($lastStatus)
                : $this->presentation->statusLabel('never'),
            'nodes' => $nodes,
            'edges' => array_map(
                function (array $edge): array {
                    return [
                        'from' => $edge['from'],
                        'to' => $edge['to'],
                        'type' => $edge['type'],
                        'type_label' => $this->presentation->edgeTypeLabel((string) $edge['type']),
                        'evidence' => $edge['evidence'],
                    ];
                },
                $definition['edges'],
            ),
            'incomplete' => $mappingStatus !== 'mapped',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @return array{by_id: array<string, array<string, mixed>>, by_code: array<string, array<string, mixed>>, by_event: array<string, list<array<string, mixed>>>}
     */
    private function indexComponents(array $components): array
    {
        $byId = [];
        $byCode = [];
        $byEvent = [];

        foreach ($components as $component) {
            $id = (string) ($component['id'] ?? '');
            $code = (string) ($component['code'] ?? '');
            $event = (string) ($component['event_name'] ?? '');
            if ($id !== '') {
                $byId[$id] = $component;
            }
            if ($code !== '') {
                $byCode[$code] = $component;
            }
            if ($event !== '') {
                $byEvent[$event][] = $component;
            }
        }

        return ['by_id' => $byId, 'by_code' => $byCode, 'by_event' => $byEvent];
    }

    /**
     * @param  array<string, mixed>  $nodeDef
     * @param  array{by_id: array<string, array<string, mixed>>, by_code: array<string, array<string, mixed>>, by_event: array<string, list<array<string, mixed>>>}  $index
     * @return list<array<string, mixed>>
     */
    private function resolveNodeComponents(array $nodeDef, array $index): array
    {
        $matched = [];
        $keys = $nodeDef['component_match'] ?? [(string) $nodeDef['canonical']];

        foreach ($keys as $key) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }

            if (str_starts_with($key, 'rule:')) {
                foreach ($index['by_id'] as $id => $component) {
                    if (str_starts_with($id, 'rule:') && ($component['event_name'] ?? '') === 'article.publish_requested') {
                        $matched[$id] = $component;
                    }
                }
                continue;
            }

            if (isset($index['by_id'][$key])) {
                $matched[$key] = $index['by_id'][$key];
            }
            if (isset($index['by_code'][$key])) {
                $component = $index['by_code'][$key];
                $matched[(string) ($component['id'] ?? $key)] = $component;
            }
            if (isset($index['by_event'][$key])) {
                foreach ($index['by_event'][$key] as $component) {
                    $matched[(string) ($component['id'] ?? $key)] = $component;
                }
            }

            // Prefix forms used by catalog ids.
            foreach (['capability:'.$key, 'event:'.$key, 'pipeline:'.$key] as $prefixed) {
                if (isset($index['by_id'][$prefixed])) {
                    $matched[$prefixed] = $index['by_id'][$prefixed];
                }
            }
        }

        return array_values($matched);
    }

    /**
     * @return array<string, true>
     */
    private function mappedComponentKeys(): array
    {
        $keys = [];
        foreach (AutomationWorkflowMapDefinitions::all() as $definition) {
            foreach ($definition['nodes'] as $node) {
                foreach ($node['component_match'] ?? [$node['canonical']] as $key) {
                    $keys[(string) $key] = true;
                }
                $keys[(string) $node['canonical']] = true;
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $component
     * @param  array<string, true>  $mappedKeys
     */
    private function componentIsMapped(array $component, array $mappedKeys): bool
    {
        $candidates = array_filter([
            (string) ($component['id'] ?? ''),
            (string) ($component['code'] ?? ''),
            (string) ($component['event_name'] ?? ''),
        ]);

        foreach ($candidates as $candidate) {
            if (isset($mappedKeys[$candidate])) {
                return true;
            }
        }

        // Rules for publish_requested belong to publishing workflow.
        if (($component['source'] ?? '') === 'business_hook_rule'
            && ($component['event_name'] ?? '') === 'article.publish_requested'
        ) {
            return true;
        }

        return false;
    }
}
