<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Services;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationGraphValidator;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;
use Omnichannel\Addons\Agent\Automation\Support\SensitivePayloadRedactor;
use Illuminate\Support\Facades\File;

final class AutomationImportExportService
{
    public const SCHEMA_VERSION = 3;

    public function __construct(
        private readonly AutomationVersionService $versionService,
        private readonly AutomationGraphValidator $graphValidator,
        private readonly SensitivePayloadRedactor $redactor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function export(string $code): array
    {
        $rule = AutomationRule::query()
            ->where('code', $code)
            ->with(['nodes', 'edges'])
            ->first();

        if (! $rule instanceof AutomationRule) {
            throw new AutomationException(
                BusinessHookErrorCode::RuleValidationFailed->value,
                "Automation rule [{$code}] not found.",
            );
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'exported_at' => now()->toIso8601String(),
            'rule' => $this->rulePayload($rule, redact: true),
        ];
    }

    /**
     * @return array{created: bool, rule: AutomationRule}
     */
    public function importFromFile(string $path, bool $disabled = true): array
    {
        if (! File::exists($path)) {
            throw new AutomationException(
                BusinessHookErrorCode::RuleValidationFailed->value,
                "Import file not found: {$path}",
            );
        }

        $raw = json_decode(File::get($path), true);
        if (! is_array($raw)) {
            throw new AutomationException(
                BusinessHookErrorCode::RuleValidationFailed->value,
                'Import file must contain valid JSON object.',
            );
        }

        return $this->import($raw, $disabled);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{created: bool, rule: AutomationRule}
     */
    public function import(array $payload, bool $disabled = true): array
    {
        $schemaVersion = (int) ($payload['schema_version'] ?? 0);
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new AutomationException(
                BusinessHookErrorCode::RuleValidationFailed->value,
                'Unsupported schema_version. Expected '.self::SCHEMA_VERSION.'.',
            );
        }

        $ruleData = $payload['rule'] ?? null;
        if (! is_array($ruleData)) {
            throw new AutomationException(
                BusinessHookErrorCode::RuleValidationFailed->value,
                'Missing rule object in import payload.',
            );
        }

        $code = trim((string) ($ruleData['code'] ?? ''));
        if ($code === '') {
            throw new AutomationException(
                BusinessHookErrorCode::RuleValidationFailed->value,
                'Rule code is required.',
            );
        }

        $nodes = is_array($ruleData['nodes'] ?? null) ? $ruleData['nodes'] : [];
        $edges = is_array($ruleData['edges'] ?? null) ? $ruleData['edges'] : [];
        $layout = is_array($ruleData['layout'] ?? null) ? $ruleData['layout'] : null;

        $existing = AutomationRule::query()->where('code', $code)->first();
        if ($existing instanceof AutomationRule) {
            throw new AutomationException(
                BusinessHookErrorCode::RuleValidationFailed->value,
                "Rule [{$code}] already exists. Import creates new draft only.",
            );
        }

        $rule = AutomationRule::query()->create([
            'code' => $code,
            'name' => (string) ($ruleData['name'] ?? $code),
            'description' => $ruleData['description'] ?? null,
            'event_name' => (string) ($ruleData['event_name'] ?? ''),
            'is_enabled' => $disabled ? false : (bool) ($ruleData['is_enabled'] ?? false),
            'priority' => (int) ($ruleData['priority'] ?? 100),
            'stop_on_failure' => (bool) ($ruleData['stop_on_failure'] ?? true),
            'run_mode' => (string) ($ruleData['run_mode'] ?? 'queued'),
            'workflow_mode' => (string) ($ruleData['workflow_mode'] ?? 'graph'),
            'trigger_type' => (string) ($ruleData['trigger_type'] ?? 'event'),
            'schedule_expression' => $ruleData['schedule_expression'] ?? null,
            'schedule_timezone' => $ruleData['schedule_timezone'] ?? null,
            'conditions' => $ruleData['conditions'] ?? null,
            'settings' => $this->redactor->redact(is_array($ruleData['settings'] ?? null) ? $ruleData['settings'] : []),
            'site_id' => isset($ruleData['site_id']) ? (int) $ruleData['site_id'] : null,
            'version' => 1,
            'draft_revision' => 1,
        ]);

        $rule = $this->versionService->saveDraft($rule, $nodes, $edges, $layout);
        $errors = $this->graphValidator->validate($rule);
        if ($errors !== []) {
            throw new AutomationException(
                BusinessHookErrorCode::GraphValidationFailed->value,
                implode(' ', $errors),
            );
        }

        return ['created' => true, 'rule' => $rule->fresh(['nodes', 'edges']) ?? $rule];
    }

    /**
     * @return array<string, mixed>
     */
    public function rulePayload(AutomationRule $rule, bool $redact = false): array
    {
        $rule->loadMissing(['nodes', 'edges']);

        $settings = $rule->settings ?? [];
        if ($redact) {
            $settings = $this->redactor->redact($settings);
        }

        return [
            'code' => $rule->code,
            'name' => $rule->name,
            'description' => $rule->description,
            'event_name' => $rule->event_name,
            'is_enabled' => (bool) $rule->is_enabled,
            'priority' => (int) $rule->priority,
            'stop_on_failure' => (bool) $rule->stop_on_failure,
            'run_mode' => $rule->run_mode,
            'workflow_mode' => $rule->workflow_mode ?? 'graph',
            'trigger_type' => $rule->trigger_type ?? 'event',
            'schedule_expression' => $rule->schedule_expression,
            'schedule_timezone' => $rule->schedule_timezone,
            'conditions' => $rule->conditions,
            'settings' => $settings,
            'site_id' => $rule->site_id,
            'layout' => ($rule->settings ?? [])['layout'] ?? null,
            'nodes' => $rule->nodes->map(static fn ($n): array => [
                'node_key' => $n->node_key,
                'node_type' => $n->node_type,
                'name' => $n->name,
                'action_code' => $n->action_code,
                'position' => $n->position,
                'config' => $redact && is_array($n->config ?? null) ? $this->redactor->redact($n->config) : $n->config,
                'input_mapping' => $n->input_mapping,
                'settings' => $redact && is_array($n->settings ?? null) ? $this->redactor->redact($n->settings) : $n->settings,
                'ui_position' => $n->ui_position ?? null,
                'is_enabled' => (bool) $n->is_enabled,
            ])->values()->all(),
            'edges' => $rule->edges->map(static fn ($e): array => [
                'from_node_key' => $e->from_node_key,
                'to_node_key' => $e->to_node_key,
                'branch' => $e->branch,
                'priority' => (int) $e->priority,
                'condition' => $e->condition,
            ])->values()->all(),
        ];
    }

    public function templatePath(string $slug): string
    {
        return dirname(__DIR__).'/Templates/'.$slug.'.json';
    }

    /**
     * @return list<string>
     */
    public function listTemplateSlugs(): array
    {
        $dir = dirname(__DIR__).'/Templates';
        if (! is_dir($dir)) {
            return [];
        }

        $slugs = [];
        foreach (glob($dir.'/*.json') ?: [] as $file) {
            $slugs[] = basename($file, '.json');
        }

        sort($slugs);

        return $slugs;
    }
}
