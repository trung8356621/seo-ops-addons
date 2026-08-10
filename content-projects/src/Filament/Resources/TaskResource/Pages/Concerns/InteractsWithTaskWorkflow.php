<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource\Pages\Concerns;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookBinding;
use Omnichannel\Addons\Content\Services\ArticleWritingExecutionService;
use Omnichannel\Addons\Content\Services\ArticleWritingLegacyRewriteAdapter;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowAssignmentValidator;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowExecutionRoleResolver;
use Omnichannel\Addons\AiPrompt\Services\WorkflowTagExtractorService;
use Omnichannel\Addons\Seo\Support\AiModelCatalog;

trait InteractsWithTaskWorkflow
{
    /**
     * @return list<array{id: string, name: string, hook_key: string, defaultModel: string, models: array<string, string>, tasks: list<array{id: string, name: string}>}>
     */
    public function getPromptsForBuilder(): array
    {
        return SeoPrompt::query()
            ->with('aiConnection')
            ->orderBy('name')
            ->get()
            ->map(function (SeoPrompt $prompt): array {
                $tasks = $prompt->resolvedParts()
                    ->where('role', 'task')
                    ->map(static fn ($part, int $index): array => [
                        'id' => 'part_'.$prompt->id.'_'.(int) ($part->position ?? $index),
                        'name' => (string) ($part->name ?: 'Task'),
                    ])
                    ->values()
                    ->all();

                if ($tasks === []) {
                    $tasks = [['id' => 'task_main', 'name' => 'Whole prompt']];
                }

                $detectedTags = is_array($prompt->settings['detected_tags'] ?? null)
                    ? $prompt->settings['detected_tags']
                    : app(WorkflowTagExtractorService::class)
                        ->detectTagsFromPromptTemplate((string) ($prompt->markdown_content ?? ''));
                $detectedTags = array_values(array_filter($detectedTags, static fn (mixed $row): bool => is_array($row)));
                if ($detectedTags !== []) {
                    $tasks = array_map(
                        static fn (array $tag): array => [
                            'id' => (string) ($tag['id'] ?? ''),
                            'name' => (string) ($tag['label'] ?? ''),
                            'key' => (string) ($tag['key'] ?? ''),
                        ],
                        $detectedTags,
                    );
                }

                return [
                    'id' => (string) $prompt->id,
                    'name' => (string) $prompt->name,
                    'hook_key' => trim((string) ($prompt->hook_key ?? '')),
                    'defaultModel' => AiModelCatalog::defaultForConnection($prompt->aiConnection),
                    'models' => AiModelCatalog::optionsForConnection($prompt->aiConnection),
                    'tasks' => $tasks,
                    'detected_tags' => $detectedTags,
                    'supports_merge_outline_save' => $this->promptSupportsMergeOutlineSave($prompt),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{nodes: array<int, mixed>, edges: array<int, mixed>}
     */
    protected function normalizeFlowPayload(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $this->normalizeFlowPayload($decoded) : ['nodes' => [], 'edges' => []];
        }

        if (! is_array($raw)) {
            return ['nodes' => [], 'edges' => []];
        }

        if (isset($raw['nodes']) || isset($raw['edges'])) {
            return [
                'nodes' => is_array($raw['nodes'] ?? null) ? $raw['nodes'] : [],
                'edges' => is_array($raw['edges'] ?? null) ? $raw['edges'] : [],
            ];
        }

        return ['nodes' => [], 'edges' => []];
    }

    /**
     * @param  array{name?: string, flow_data?: mixed}  $data
     */
    public function saveFlow(array $data): void
    {
        $flowData = $this->normalizeFlowPayload($data['flow_data'] ?? null);
        $roleErrors = app(WorkflowExecutionRoleResolver::class)->validateFlowData($flowData);

        $record = method_exists($this, 'getRecord') ? $this->getRecord() : null;
        if ($record instanceof \Omnichannel\Addons\AiPrompt\Models\SeoTask) {
            $roleErrors = array_merge(
                $roleErrors,
                app(WorkflowAssignmentValidator::class)->validateFlowPreservesSettingsBindings($record, $flowData),
            );
        }

        if ($roleErrors !== []) {
            $this->dispatch(
                'task-flow-save-failed',
                message: 'Không thể lưu quy trình: '.implode(' ', $roleErrors),
            );

            return;
        }

        $saved = $this->persistTaskFlow(
            trim((string) ($data['name'] ?? '')),
            $flowData,
        );

        $this->dispatch(
            $saved ? 'task-flow-saved' : 'task-flow-save-failed',
            message: $saved ? 'Đã lưu quy trình thành công.' : 'Không thể lưu quy trình.',
        );
    }

    abstract protected function persistTaskFlow(string $taskName, array $flowData): bool;

    private function promptSupportsMergeOutlineSave(SeoPrompt $prompt): bool
    {
        try {
            $binding = PromptHookBinding::tryFromPrompt($prompt);
            $hook = trim((string) ($binding?->hookKey ?? ''));

            return $hook === ArticleWritingExecutionService::HOOK_KEY
                || $hook === ArticleWritingLegacyRewriteAdapter::LEGACY_REWRITE_HOOK;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
