<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\WorkflowRoles;

use Omnichannel\Addons\ContentProjects\Enums\WorkflowCapability;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionRole;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;

/**
 * Read-only diagnosis cho seo:workflow:doctor.
 */
final class WorkflowDoctorService
{
    public function __construct(
        private readonly WorkflowExecutionRoleResolver $roleResolver,
        private readonly WorkflowAssignmentValidator $assignmentValidator,
        private readonly WorkflowExecutionSnapshotBuilder $snapshotBuilder,
    ) {}

    /**
     * @return array{
     *     workflow_id: int,
     *     name: string,
     *     used_by: list<string>,
     *     flow_data_hash: string,
     *     roles: array<string, string|null>,
     *     missing_required: list<string>,
     *     duplicates: list<string>,
     *     prompt_missing: list<string>,
     *     hook_mismatch: list<string>,
     *     broken_edges: list<string>,
     *     ambiguous_unassigned: list<string>,
     *     can_run: array{publish: bool, content_only: bool, improve: bool, image: bool},
     *     blocking_errors: list<string>,
     *     warnings: list<string>
     * }
     */
    public function diagnose(SeoTask $task): array
    {
        $snap = $this->snapshotBuilder->fromTask($task);
        $flow = is_array($task->flow_data) ? $task->flow_data : [];
        $nodes = is_array($flow['nodes'] ?? null) ? $flow['nodes'] : [];
        $edges = is_array($flow['edges'] ?? null) ? $flow['edges'] : [];

        $roles = [];
        foreach (WorkflowExecutionRole::cases() as $role) {
            $found = $this->roleResolver->findNode($task, $role);
            $roles[$role->value] = $found['node_id'] ?? null;
        }

        $validation = $this->roleResolver->validateTask($task);
        $duplicates = array_values(array_filter(
            $validation,
            static fn (string $m): bool => str_contains($m, 'trùng'),
        ));
        $hookMismatch = array_values(array_filter(
            $validation,
            static fn (string $m): bool => str_contains($m, 'không khớp role'),
        ));
        $typeErrors = array_values(array_filter(
            $validation,
            static fn (string $m): bool => str_contains($m, 'không hợp lệ với type'),
        ));

        $promptMissing = $this->findMissingPrompts($nodes);
        $brokenEdges = $this->findBrokenEdges($nodes, $edges);
        $ambiguous = $this->findAmbiguousUnassigned($nodes);

        $usedBy = array_map(
            static fn (WorkflowCapability $c): string => $c->labelVi(),
            $this->assignmentValidator->settingsBindingsUsingTask((int) $task->getKey()),
        );

        $missingRequired = [];
        foreach ($this->assignmentValidator->settingsBindingsUsingTask((int) $task->getKey()) as $capability) {
            foreach ($capability->requiredRoles() as $role) {
                if ($roles[$role->value] === null) {
                    $missingRequired[] = $role->value.' ('.$capability->labelVi().')';
                }
            }
        }

        $canPublish = $roles[WorkflowExecutionRole::ArticleOutlineGenerate->value] !== null
            && $roles[WorkflowExecutionRole::ArticleContentGenerate->value] !== null
            && $duplicates === []
            && $promptMissing === []
            && $brokenEdges === [];

        $canContent = $roles[WorkflowExecutionRole::ArticleContentGenerate->value] !== null
            && $duplicates === []
            && $promptMissing === [];

        $canImprove = $roles[WorkflowExecutionRole::ArticleContentImprove->value] !== null
            && $duplicates === [];

        $canImage = $roles[WorkflowExecutionRole::ArticleImageGenerate->value] !== null
            && $duplicates === [];

        $blocking = array_values(array_unique(array_merge(
            $duplicates,
            $typeErrors,
            $hookMismatch,
            $promptMissing,
            $brokenEdges,
            array_map(static fn (string $r): string => 'Missing required: '.$r, $missingRequired),
        )));

        $warnings = array_map(
            static fn (string $id): string => 'Ambiguous unassigned prompt node: '.$id,
            $ambiguous,
        );

        return [
            'workflow_id' => (int) $task->getKey(),
            'name' => (string) ($task->name ?? ''),
            'used_by' => $usedBy,
            'flow_data_hash' => $snap->flowDataHash,
            'roles' => $roles,
            'missing_required' => $missingRequired,
            'duplicates' => $duplicates,
            'prompt_missing' => $promptMissing,
            'hook_mismatch' => $hookMismatch,
            'broken_edges' => $brokenEdges,
            'ambiguous_unassigned' => $ambiguous,
            'can_run' => [
                'publish' => $canPublish,
                'content_only' => $canContent,
                'improve' => $canImprove,
                'image' => $canImage,
            ],
            'blocking_errors' => $blocking,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<string>
     */
    private function findMissingPrompts(array $nodes): array
    {
        if (SeoPrompt::getConnectionResolver() === null) {
            return [];
        }

        $out = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $type = (string) ($node['type'] ?? '');
            if ($type !== 'prompt') {
                continue;
            }
            $nodeId = trim((string) ($node['id'] ?? ''));
            $promptId = isset($node['data']['promptId']) ? (int) $node['data']['promptId'] : 0;
            $role = $this->roleResolver->readRole($node);
            if ($role === null && $promptId <= 0) {
                continue;
            }
            if ($promptId <= 0) {
                if ($role !== null) {
                    $out[] = "Node {$nodeId}: đã gán role nhưng thiếu Prompt.";
                }

                continue;
            }
            try {
                $exists = SeoPrompt::query()->whereKey($promptId)->exists();
            } catch (\Throwable) {
                continue;
            }
            if (! $exists) {
                $out[] = "Node {$nodeId}: Prompt #{$promptId} không tồn tại.";
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @return list<string>
     */
    private function findBrokenEdges(array $nodes, array $edges): array
    {
        $ids = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $id = trim((string) ($node['id'] ?? ''));
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        $out = [];
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $edgeId = trim((string) ($edge['id'] ?? ''));
            $source = trim((string) ($edge['source'] ?? ''));
            $target = trim((string) ($edge['target'] ?? ''));
            if ($source !== '' && ! isset($ids[$source])) {
                $out[] = "Edge {$edgeId}: source «{$source}» không tồn tại.";
            }
            if ($target !== '' && ! isset($ids[$target])) {
                $out[] = "Edge {$edgeId}: target «{$target}» không tồn tại.";
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<string>
     */
    private function findAmbiguousUnassigned(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            if ((string) ($node['type'] ?? '') !== 'prompt') {
                continue;
            }
            if ($this->roleResolver->readRole($node) !== null) {
                continue;
            }
            $promptId = isset($node['data']['promptId']) ? (int) $node['data']['promptId'] : 0;
            if ($promptId <= 0) {
                continue;
            }
            $out[] = trim((string) ($node['id'] ?? ''));
        }

        return array_values(array_filter($out));
    }
}
