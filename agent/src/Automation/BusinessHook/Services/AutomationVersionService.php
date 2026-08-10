<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Services;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationRuleVersionStatus;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRuleEdge;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRuleNode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRuleVersion;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRuleVersionEdge;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRuleVersionNode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationGraphValidator;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\LinearRuleGraphAdapter;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;
use Illuminate\Support\Facades\DB;

final class AutomationVersionService
{
    public function __construct(
        private readonly AutomationGraphValidator $graphValidator,
        private readonly LinearRuleGraphAdapter $linearAdapter,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @param  array<string, mixed>|null  $layout
     */
    public function saveDraft(
        AutomationRule $rule,
        array $nodes,
        array $edges,
        ?array $layout = null,
        ?int $expectedRevision = null,
        ?int $actorId = null,
    ): AutomationRule {
        return \App\Support\Automation\AutomationConnection::db()->transaction(function () use ($rule, $nodes, $edges, $layout, $expectedRevision, $actorId): AutomationRule {
            /** @var AutomationRule $locked */
            $locked = AutomationRule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();

            if ($expectedRevision !== null && (int) $locked->draft_revision !== $expectedRevision) {
                throw new AutomationException(
                    BusinessHookErrorCode::DraftConflict->value,
                    'Draft revision conflict. Reload and retry.',
                );
            }

            AutomationRuleNode::query()->where('automation_rule_id', $locked->id)->delete();
            AutomationRuleEdge::query()->where('automation_rule_id', $locked->id)->delete();

            foreach ($nodes as $i => $node) {
                AutomationRuleNode::query()->create([
                    'automation_rule_id' => $locked->id,
                    'node_key' => (string) $node['node_key'],
                    'node_type' => (string) $node['node_type'],
                    'name' => $node['name'] ?? null,
                    'action_code' => $node['action_code'] ?? null,
                    'position' => isset($node['position']) ? (int) $node['position'] : $i,
                    'config' => $node['config'] ?? null,
                    'input_mapping' => $node['input_mapping'] ?? null,
                    'settings' => $node['settings'] ?? null,
                    'ui_position' => $node['ui_position'] ?? null,
                    'is_enabled' => (bool) ($node['is_enabled'] ?? true),
                ]);
            }

            foreach ($edges as $edge) {
                AutomationRuleEdge::query()->create([
                    'automation_rule_id' => $locked->id,
                    'from_node_key' => (string) $edge['from_node_key'],
                    'to_node_key' => (string) $edge['to_node_key'],
                    'branch' => $edge['branch'] ?? null,
                    'priority' => (int) ($edge['priority'] ?? 100),
                    'condition' => $edge['condition'] ?? null,
                ]);
            }

            $revision = (int) $locked->draft_revision + 1;
            $locked->forceFill([
                'draft_revision' => $revision,
                'workflow_mode' => 'graph',
                'updated_by' => $actorId,
                'settings' => array_merge($locked->settings ?? [], [
                    'layout' => $layout ?? (($locked->settings ?? [])['layout'] ?? null),
                ]),
            ])->save();

            $draftVersion = $this->upsertDraftVersionRow($locked, $layout);

            return $locked->fresh(['nodes', 'edges']) ?? $locked;
        });
    }

    /**
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public function validateDraft(AutomationRule $rule): array
    {
        $rule->loadMissing(['nodes', 'edges']);
        $errors = [];
        $warnings = [];

        if ($rule->isGraphMode() || $rule->nodes->isNotEmpty()) {
            $errors = $this->graphValidator->validate($rule);
        }

        if ($rule->nodes->isEmpty() && $rule->isLinearMode()) {
            $warnings[] = 'Linear rule has no graph draft; publish will snapshot virtual linear graph.';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    public function publish(AutomationRule $rule, ?int $actorId = null): AutomationRuleVersion
    {
        return \App\Support\Automation\AutomationConnection::db()->transaction(function () use ($rule, $actorId): AutomationRuleVersion {
            /** @var AutomationRule $locked */
            $locked = AutomationRule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();
            $locked->load(['nodes', 'edges', 'actions']);

            $validation = $this->validateDraft($locked);
            if (! $validation['valid']) {
                throw new AutomationException(
                    BusinessHookErrorCode::GraphValidationFailed->value,
                    implode(' ', $validation['errors']),
                );
            }

            if ($locked->published_version_id) {
                AutomationRuleVersion::query()
                    ->whereKey($locked->published_version_id)
                    ->update(['status' => AutomationRuleVersionStatus::Archived->value]);
            }

            $nextVersion = (int) $locked->version + 1;
            $nodes = $locked->nodes;
            $edges = $locked->edges;

            if ($nodes->isEmpty() && $locked->isLinearMode()) {
                $virtual = $this->linearAdapter->toVirtualGraph($locked);
                $snapshot = $this->createVersionFromArrays(
                    $locked,
                    $nextVersion,
                    AutomationRuleVersionStatus::Published,
                    $virtual['nodes'],
                    $virtual['edges'],
                    $actorId,
                );
            } else {
                $snapshot = $this->createVersionFromCollections(
                    $locked,
                    $nextVersion,
                    AutomationRuleVersionStatus::Published,
                    $nodes,
                    $edges,
                    $actorId,
                );
            }

            $locked->forceFill([
                'version' => $nextVersion,
                'published_version_id' => $snapshot->id,
                'updated_by' => $actorId,
            ])->save();

            return $snapshot;
        });
    }

    public function ensurePublishedVersion(AutomationRule $rule, ?int $actorId = null): AutomationRuleVersion
    {
        if ($rule->published_version_id) {
            $existing = AutomationRuleVersion::query()->find($rule->published_version_id);
            if ($existing instanceof AutomationRuleVersion) {
                return $existing;
            }
        }

        return $this->publish($rule, $actorId);
    }

    public function resolveGraphForExecution(AutomationRule $rule, ?int $versionId = null): AutomationRuleVersion
    {
        if ($versionId !== null && $versionId > 0) {
            $version = AutomationRuleVersion::query()
                ->with(['nodes', 'edges'])
                ->whereKey($versionId)
                ->where('automation_rule_id', $rule->id)
                ->first();
            if ($version instanceof AutomationRuleVersion) {
                return $version;
            }
        }

        if ($rule->published_version_id) {
            $published = AutomationRuleVersion::query()
                ->with(['nodes', 'edges'])
                ->find($rule->published_version_id);
            if ($published instanceof AutomationRuleVersion) {
                return $published;
            }
        }

        throw new \RuntimeException(
            "Rule [{$rule->code}] has no resolvable published version for execution. Publish before enable.",
        );
    }

    private function upsertDraftVersionRow(AutomationRule $rule, ?array $layout): AutomationRuleVersion
    {
        $draft = null;
        if ($rule->draft_version_id) {
            $draft = AutomationRuleVersion::query()->find($rule->draft_version_id);
        }

        if (! $draft instanceof AutomationRuleVersion || $draft->status !== AutomationRuleVersionStatus::Draft->value) {
            $draft = AutomationRuleVersion::query()->create([
                'automation_rule_id' => $rule->id,
                'version' => 0,
                'status' => AutomationRuleVersionStatus::Draft->value,
                'workflow_mode' => $rule->workflow_mode ?? 'graph',
                'trigger_type' => $rule->trigger_type ?? 'event',
                'event_name' => $rule->event_name,
                'schedule_expression' => $rule->schedule_expression,
                'schedule_timezone' => $rule->schedule_timezone,
                'conditions' => $rule->conditions,
                'settings' => $rule->settings,
                'layout' => $layout,
                'draft_revision' => (int) $rule->draft_revision,
            ]);
            $rule->forceFill(['draft_version_id' => $draft->id])->save();
        } else {
            $draft->forceFill([
                'event_name' => $rule->event_name,
                'trigger_type' => $rule->trigger_type,
                'schedule_expression' => $rule->schedule_expression,
                'schedule_timezone' => $rule->schedule_timezone,
                'conditions' => $rule->conditions,
                'settings' => $rule->settings,
                'layout' => $layout ?? $draft->layout,
                'draft_revision' => (int) $rule->draft_revision,
            ])->save();
        }

        return $draft;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AutomationRuleNode>  $nodes
     * @param  \Illuminate\Support\Collection<int, AutomationRuleEdge>  $edges
     */
    private function createVersionFromCollections(
        AutomationRule $rule,
        int $version,
        AutomationRuleVersionStatus $status,
        $nodes,
        $edges,
        ?int $actorId,
    ): AutomationRuleVersion {
        $arrays = [
            'nodes' => $nodes->map(static fn (AutomationRuleNode $n): array => [
                'node_key' => $n->node_key,
                'node_type' => $n->node_type,
                'name' => $n->name,
                'action_code' => $n->action_code,
                'position' => $n->position,
                'config' => $n->config,
                'input_mapping' => $n->input_mapping,
                'settings' => $n->settings,
                'ui_position' => $n->ui_position ?? null,
                'is_enabled' => $n->is_enabled,
            ])->all(),
            'edges' => $edges->map(static fn (AutomationRuleEdge $e): array => [
                'from_node_key' => $e->from_node_key,
                'to_node_key' => $e->to_node_key,
                'branch' => $e->branch,
                'priority' => $e->priority,
                'condition' => $e->condition,
            ])->all(),
        ];

        return $this->createVersionFromArrays($rule, $version, $status, $arrays['nodes'], $arrays['edges'], $actorId);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     */
    private function createVersionFromArrays(
        AutomationRule $rule,
        int $version,
        AutomationRuleVersionStatus $status,
        array $nodes,
        array $edges,
        ?int $actorId,
    ): AutomationRuleVersion {
        $row = AutomationRuleVersion::query()->create([
            'automation_rule_id' => $rule->id,
            'version' => $version,
            'status' => $status->value,
            'workflow_mode' => $rule->workflow_mode ?? 'graph',
            'trigger_type' => $rule->trigger_type ?? 'event',
            'event_name' => $rule->event_name,
            'schedule_expression' => $rule->schedule_expression,
            'schedule_timezone' => $rule->schedule_timezone,
            'conditions' => $rule->conditions,
            'settings' => $rule->settings,
            'layout' => ($rule->settings ?? [])['layout'] ?? null,
            'draft_revision' => (int) $rule->draft_revision,
            'published_at' => $status === AutomationRuleVersionStatus::Published ? now() : null,
            'published_by' => $status === AutomationRuleVersionStatus::Published ? $actorId : null,
        ]);

        foreach ($nodes as $node) {
            AutomationRuleVersionNode::query()->create([
                'automation_rule_version_id' => $row->id,
                'node_key' => (string) $node['node_key'],
                'node_type' => (string) $node['node_type'],
                'name' => $node['name'] ?? null,
                'action_code' => $node['action_code'] ?? null,
                'position' => isset($node['position']) ? (int) $node['position'] : null,
                'config' => $node['config'] ?? null,
                'input_mapping' => $node['input_mapping'] ?? null,
                'settings' => $node['settings'] ?? null,
                'ui_position' => $node['ui_position'] ?? null,
                'is_enabled' => (bool) ($node['is_enabled'] ?? true),
            ]);
        }

        foreach ($edges as $edge) {
            AutomationRuleVersionEdge::query()->create([
                'automation_rule_version_id' => $row->id,
                'from_node_key' => (string) $edge['from_node_key'],
                'to_node_key' => (string) $edge['to_node_key'],
                'branch' => $edge['branch'] ?? null,
                'priority' => (int) ($edge['priority'] ?? 100),
                'condition' => $edge['condition'] ?? null,
            ]);
        }

        return $row->fresh(['nodes', 'edges']) ?? $row;
    }
}
