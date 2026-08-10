<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Presentation\Workflow;

/**
 * Serializes Round-1 workflow projection arrays into React viewer DTOs.
 * Presentation only — does not change mapping / evidence / status semantics.
 */
final class AutomationWorkflowViewerPayload
{
    /**
     * @param  array<string, mixed>  $workflow  Projected workflow from AutomationWorkflowMapBuilder
     * @return array<string, mixed>
     */
    public function fromProjectedWorkflow(array $workflow): array
    {
        $nodes = [];
        foreach ($workflow['nodes'] ?? [] as $node) {
            if (! is_array($node)) {
                continue;
            }
            $nodes[] = $this->mapNode($node);
        }

        $edges = [];
        foreach ($workflow['edges'] ?? [] as $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $edges[] = [
                'source' => (string) ($edge['from'] ?? ''),
                'target' => (string) ($edge['to'] ?? ''),
                'type' => (string) ($edge['type'] ?? 'next'),
                'label' => (string) ($edge['type_label'] ?? $edge['type'] ?? ''),
                'evidence' => (string) ($edge['evidence'] ?? ''),
            ];
        }

        $lastStatus = $workflow['last_status'] ?? null;

        return [
            'id' => (string) ($workflow['id'] ?? ''),
            'name' => (string) ($workflow['name'] ?? ''),
            'description' => (string) ($workflow['description'] ?? ''),
            'category' => (string) ($workflow['category'] ?? ''),
            'category_label' => (string) ($workflow['category_label'] ?? ''),
            'status' => $lastStatus !== null ? (string) $lastStatus : 'never_executed',
            'status_label' => (string) ($workflow['status_label'] ?? ''),
            'mapping_status' => (string) ($workflow['mapping_status'] ?? ''),
            'mapping_label' => (string) ($workflow['mapping_label'] ?? ''),
            'step_count' => (int) ($workflow['step_count'] ?? count($nodes)),
            'component_count' => (int) ($workflow['component_count'] ?? 0),
            'queued_transitions' => (int) ($workflow['queued_transitions'] ?? 0),
            'definition_sources' => array_values(array_map('strval', $workflow['definition_sources'] ?? [])),
            'latest_execution' => [
                'status' => $lastStatus !== null ? (string) $lastStatus : null,
                'status_label' => (string) ($workflow['status_label'] ?? ''),
                'ran_at' => $workflow['last_run_at'] ?? null,
                'error' => (string) ($workflow['last_error'] ?? ''),
            ],
            'nodes' => $nodes,
            'edges' => $edges,
            'links' => [
                'executions' => (string) ($workflow['links']['executions'] ?? ''),
                'operations' => (string) ($workflow['links']['operations'] ?? ''),
                'components_tab' => (string) ($workflow['links']['components_tab'] ?? ''),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function mapNode(array $node): array
    {
        $rawType = (string) ($node['type'] ?? 'action');
        $runMode = (string) ($node['run_mode'] ?? '');
        $optional = (bool) ($node['optional'] ?? false);
        $matched = is_array($node['matched_components'] ?? null) ? $node['matched_components'] : [];

        $nodeStatus = $this->resolveNodeStatus($matched, (bool) ($node['registered'] ?? false));
        $evidence = [];
        if (! empty($node['evidence'])) {
            $evidence[] = (string) $node['evidence'];
        }

        return [
            'id' => (string) ($node['id'] ?? ''),
            'label' => (string) ($node['label'] ?? $node['canonical'] ?? ''),
            'technical_id' => (string) ($node['canonical'] ?? ''),
            'type' => $this->mapNodeType($rawType, $runMode),
            'raw_type' => $rawType,
            'status' => $nodeStatus,
            'mode' => $this->mapMode($runMode, $optional),
            'optional' => $optional,
            'registered' => (bool) ($node['registered'] ?? false),
            'description' => '',
            'evidence' => $evidence,
            'handler' => $this->firstHandler($matched),
            'matched_components' => array_values(array_map(
                static fn (array $c): array => [
                    'id' => (string) ($c['id'] ?? ''),
                    'code' => (string) ($c['code'] ?? ''),
                    'source' => (string) ($c['source'] ?? ''),
                    'last_status' => $c['last_status'] ?? null,
                    'last_run_at' => $c['last_run_at'] ?? null,
                ],
                array_filter($matched, 'is_array'),
            )),
        ];
    }

    private function mapNodeType(string $rawType, string $runMode): string
    {
        return match ($rawType) {
            'event' => 'event',
            'capability' => 'command',
            'command' => $runMode === 'queued' ? 'queue' : 'command',
            'action' => 'action',
            'pipeline', 'pipeline_step' => 'action',
            'condition' => 'condition',
            'result' => 'result',
            default => 'action',
        };
    }

    private function mapMode(string $runMode, bool $optional): string
    {
        if ($optional) {
            return 'optional';
        }

        return match ($runMode) {
            'queued', 'pipeline' => $runMode === 'queued' ? 'queued' : 'sync',
            'command_bus', 'event', 'sync' => $runMode === 'event' ? 'sync' : 'sync',
            'manual' => 'manual',
            default => $runMode !== '' ? 'sync' : 'sync',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $matched
     */
    private function resolveNodeStatus(array $matched, bool $registered): string
    {
        $latest = null;
        $latestAt = null;
        foreach ($matched as $component) {
            $status = $component['last_status'] ?? null;
            $ranAt = $component['last_run_at'] ?? null;
            if (! is_string($status) || $status === '') {
                continue;
            }
            if ($ranAt !== null && ($latestAt === null || (string) $ranAt > (string) $latestAt)) {
                $latestAt = $ranAt;
                $latest = $status;
            } elseif ($latest === null) {
                $latest = $status;
            }
        }

        return match ($latest) {
            'completed' => 'completed',
            'failed' => 'failed',
            'pending', 'processing' => 'processing',
            'partial' => 'stale',
            default => $registered ? 'never_executed' : 'never_executed',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $matched
     */
    private function firstHandler(array $matched): ?string
    {
        foreach ($matched as $component) {
            $code = (string) ($component['code'] ?? $component['id'] ?? '');
            if ($code !== '') {
                return $code;
            }
        }

        return null;
    }
}
