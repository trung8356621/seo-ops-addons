<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;

/**
 * Usage resolver — Settings bindings + Task Prompt Blocks.
 * Agent / Quick Chat reserved slots (empty until wired).
 */
final class PromptUsageLocator
{
    public function __construct(
        private readonly SeoCreateArticleSettingsService $settings,
        private readonly PromptHookEditorCatalog $catalog,
        private readonly PromptPreviewSectionViewModel $viewModel,
    ) {}

    /**
     * @return list<array{type: string, label: string, detail: string}>
     */
    public function locate(int $promptId): array
    {
        if ($promptId <= 0) {
            return [];
        }

        $usages = [];

        foreach ($this->settings->getPromptHookBindings() as $hookKey => $boundId) {
            if ((int) $boundId !== $promptId) {
                continue;
            }
            $label = $this->hookLabel((string) $hookKey);
            $usages[] = [
                'type' => 'settings',
                'label' => 'Settings: '.$label,
                'detail' => (string) $hookKey,
            ];
        }

        foreach ($this->scanTaskPromptBlocks($promptId) as $usage) {
            $usages[] = $usage;
        }

        // Reserved — Agent / Quick Chat presets (Phase later).
        foreach ($this->reservedAgentUsages($promptId) as $usage) {
            $usages[] = $usage;
        }
        foreach ($this->reservedQuickChatUsages($promptId) as $usage) {
            $usages[] = $usage;
        }

        return $this->viewModel->orderedUsages($usages);
    }

    /**
     * @return list<string>
     */
    public function summarize(int $promptId): array
    {
        return array_values(array_map(
            static fn (array $row): string => $row['label'],
            $this->locate($promptId),
        ));
    }

    /**
     * @return array{badge: string, tooltip: ?string, kind: string}
     */
    public function badge(int $promptId): array
    {
        return $this->viewModel->badge($this->locate($promptId));
    }

    public function usageCount(int $promptId): int
    {
        return count($this->locate($promptId));
    }

    public function isReferenced(int $promptId): bool
    {
        return $this->locate($promptId) !== [];
    }

    /**
     * Detach Settings bindings + clear Task flow_data prompt_id references.
     */
    public function detachAll(int $promptId): int
    {
        if ($promptId <= 0) {
            return 0;
        }

        $detached = 0;
        $bindings = $this->settings->getPromptHookBindings();
        $clear = [];
        foreach ($bindings as $hookKey => $boundId) {
            if ((int) $boundId === $promptId) {
                $clear[(string) $hookKey] = null;
                $detached++;
            }
        }
        if ($clear !== []) {
            $this->settings->savePromptHookBindings($clear);
        }

        $needle = (string) $promptId;
        SeoTask::query()
            ->select(['id', 'flow_data'])
            ->orderBy('id')
            ->chunkById(100, function ($tasks) use ($promptId, $needle, &$detached): void {
                foreach ($tasks as $task) {
                    $workflow = is_array($task->flow_data) ? $task->flow_data : [];
                    $nodes = is_array($workflow['nodes'] ?? null) ? $workflow['nodes'] : [];
                    $changed = false;
                    foreach ($nodes as $i => $node) {
                        if (! is_array($node)) {
                            continue;
                        }
                        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
                        $nodePromptId = $data['prompt_id'] ?? $data['promptId'] ?? null;
                        if ($nodePromptId === null) {
                            continue;
                        }
                        if ((int) $nodePromptId !== $promptId && (string) $nodePromptId !== $needle) {
                            continue;
                        }
                        unset($data['prompt_id'], $data['promptId']);
                        $nodes[$i]['data'] = $data;
                        $changed = true;
                        $detached++;
                    }
                    if ($changed) {
                        $workflow['nodes'] = $nodes;
                        $task->flow_data = $workflow;
                        $task->save();
                    }
                }
            });

        return $detached;
    }

    private function hookLabel(string $hookKey): string
    {
        foreach ($this->catalog->settingsVisibleHooks() as $row) {
            if ($row['hook_key'] === $hookKey) {
                return $row['display_name'];
            }
        }

        try {
            $definition = $this->catalog->latestPinnedOrFail($hookKey);

            return $definition->name !== '' ? $definition->name : $hookKey;
        } catch (\Throwable) {
            return $hookKey;
        }
    }

    /**
     * @return list<array{type: string, label: string, detail: string}>
     */
    private function scanTaskPromptBlocks(int $promptId): array
    {
        $usages = [];
        $needle = (string) $promptId;

        SeoTask::query()
            ->select(['id', 'name', 'flow_data'])
            ->orderBy('id')
            ->chunkById(100, function ($tasks) use ($promptId, $needle, &$usages): void {
                foreach ($tasks as $task) {
                    $workflow = is_array($task->flow_data) ? $task->flow_data : [];
                    $nodes = is_array($workflow['nodes'] ?? null) ? $workflow['nodes'] : [];
                    $seen = false;
                    foreach ($nodes as $node) {
                        if (! is_array($node)) {
                            continue;
                        }
                        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
                        $nodePromptId = $data['prompt_id'] ?? $data['promptId'] ?? null;
                        if ($nodePromptId === null) {
                            continue;
                        }
                        if ((int) $nodePromptId !== $promptId && (string) $nodePromptId !== $needle) {
                            continue;
                        }
                        $seen = true;
                        break;
                    }
                    if (! $seen) {
                        continue;
                    }
                    $taskName = trim((string) ($task->name ?? '')) ?: ('Task #'.$task->id);
                    $usages[] = [
                        'type' => 'workflow',
                        'label' => 'Workflow: '.$taskName,
                        'detail' => 'task_id='.(int) $task->id,
                    ];
                }
            });

        return $usages;
    }

    /**
     * @return list<array{type: string, label: string, detail: string}>
     */
    private function reservedAgentUsages(int $promptId): array
    {
        unset($promptId);

        return [];
    }

    /**
     * @return list<array{type: string, label: string, detail: string}>
     */
    private function reservedQuickChatUsages(int $promptId): array
    {
        unset($promptId);

        return [];
    }
}
