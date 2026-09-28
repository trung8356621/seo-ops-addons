<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SettingsTransfer;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\Services\PromptPack\PromptPortableIdentity;
use Omnichannel\Addons\AiPrompt\Services\PromptPack\TaskPortableIdentity;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;

final class WorkflowBindingsSection implements PortableSettingsSection
{
    public function key(): string
    {
        return 'workflows';
    }

    public function export(int $userId): array
    {
        $promptIdentity = new PromptPortableIdentity();
        $taskIdentity = new TaskPortableIdentity();
        $settingsService = app(SeoCreateArticleSettingsService::class);

        $bindings = $settingsService->getPromptHookBindings();
        $portablePrompts = [];
        foreach ($bindings as $hook => $promptId) {
            $prompt = SeoPrompt::query()->where('user_id', $userId)->whereKey((int) $promptId)->first();
            if (! $prompt instanceof SeoPrompt) {
                continue;
            }
            $portablePrompts[$hook] = [
                'portable_uuid' => $promptIdentity->ensure($prompt),
                'name' => (string) $prompt->name,
            ];
        }

        $taskSlots = [
            'publish_article_task_id' => $settingsService->getPublishArticleTaskId(),
            'create_image_task_id' => $settingsService->getCreateImageTaskId(),
            'create_video_task_id' => $settingsService->getCreateVideoTaskId(),
            'post_review_task_id' => $settingsService->getPostReviewTaskId(),
            'create_typography_image_task_id' => $settingsService->getCreateTypographyImageTaskId(),
        ];
        $portableTasks = [];
        foreach ($taskSlots as $slotKey => $taskId) {
            if ($taskId === null || (int) $taskId <= 0) {
                continue;
            }
            $task = SeoTask::query()->where('user_id', $userId)->whereKey((int) $taskId)->first();
            if (! $task instanceof SeoTask) {
                continue;
            }
            $portableTasks[$slotKey] = [
                'portable_uuid' => $taskIdentity->ensure($task),
                'name' => (string) $task->name,
            ];
        }

        return [
            'prompt_bindings' => $portablePrompts,
            'task_bindings' => $portableTasks,
            'media_sources' => [
                'create_image_source' => $settingsService->getCreateImageSource(),
                'create_typography_image_source' => $settingsService->getCreateTypographyImageSource(),
                'create_video_source' => $settingsService->getCreateVideoSource(),
            ],
        ];
    }

    public function diff(int $userId, array $incoming): array
    {
        $current = $this->export($userId);
        $afterPrompts = is_array($incoming['prompt_bindings'] ?? null) ? $incoming['prompt_bindings'] : [];
        $afterTasks = is_array($incoming['task_bindings'] ?? null) ? $incoming['task_bindings'] : [];
        $afterSources = is_array($incoming['media_sources'] ?? null) ? $incoming['media_sources'] : [];

        $changed = 0;
        $lines = [];
        $warnings = [];

        if (json_encode($current['prompt_bindings'] ?? []) !== json_encode($afterPrompts)) {
            $changed = 1;
            $lines[] = 'Prompt hook bindings updated';
        }
        if (json_encode($current['task_bindings'] ?? []) !== json_encode($afterTasks)) {
            $changed = 1;
            $lines[] = 'Task workflow bindings updated';
        }
        if ($afterSources !== [] && json_encode($current['media_sources'] ?? []) !== json_encode($afterSources)) {
            $changed = 1;
            $lines[] = 'Media source preferences updated';
        }

        foreach ($afterPrompts as $hook => $ref) {
            $uuid = is_array($ref) ? (string) ($ref['portable_uuid'] ?? '') : '';
            if ($uuid !== '' && $this->promptByUuid($userId, $uuid) === null) {
                $warnings[] = 'Prompt binding '.$hook.' requires a prompt that is not on this workspace yet.';
            }
        }

        foreach ($afterTasks as $slot => $ref) {
            $uuid = is_array($ref) ? (string) ($ref['portable_uuid'] ?? '') : '';
            if ($uuid !== '' && $this->taskByUuid($userId, $uuid) === null) {
                $warnings[] = 'Task binding '.$slot.' requires a workflow that is not on this workspace yet.';
            }
        }

        return [
            'changed' => $changed,
            'unchanged' => $changed === 0 ? 1 : 0,
            'lines' => $lines,
            'warnings' => $warnings,
            'payload' => [
                'prompt_bindings' => $afterPrompts,
                'task_bindings' => $afterTasks,
                'media_sources' => $afterSources,
            ],
        ];
    }

    public function apply(int $userId, array $incoming, string $mode): void
    {
        unset($mode);
        $settingsService = app(SeoCreateArticleSettingsService::class);
        $patch = [];

        $afterPrompts = is_array($incoming['prompt_bindings'] ?? null) ? $incoming['prompt_bindings'] : [];
        $promptIds = [];
        foreach ($afterPrompts as $hook => $ref) {
            $uuid = is_array($ref) ? (string) ($ref['portable_uuid'] ?? '') : '';
            $name = is_array($ref) ? (string) ($ref['name'] ?? '') : '';
            $prompt = $uuid !== '' ? $this->promptByUuid($userId, $uuid) : null;
            if ($prompt === null && $name !== '') {
                $prompt = SeoPrompt::query()->where('user_id', $userId)->where('name', $name)->first();
            }
            if ($prompt instanceof SeoPrompt) {
                $promptIds[(string) $hook] = (int) $prompt->id;
            }
        }
        if ($promptIds !== []) {
            $patch[SeoCreateArticleSettingsService::KEY_PROMPT_HOOK_BINDINGS] = $promptIds;
        }

        $afterTasks = is_array($incoming['task_bindings'] ?? null) ? $incoming['task_bindings'] : [];
        foreach ($afterTasks as $slot => $ref) {
            $uuid = is_array($ref) ? (string) ($ref['portable_uuid'] ?? '') : '';
            $name = is_array($ref) ? (string) ($ref['name'] ?? '') : '';
            $task = $uuid !== '' ? $this->taskByUuid($userId, $uuid) : null;
            if ($task === null && $name !== '') {
                $task = SeoTask::query()->where('user_id', $userId)->where('name', $name)->first();
            }
            if ($task instanceof SeoTask) {
                $patch[(string) $slot] = (int) $task->id;
            }
        }

        $afterSources = is_array($incoming['media_sources'] ?? null) ? $incoming['media_sources'] : [];
        foreach ($afterSources as $sourceKey => $sourceVal) {
            if (is_string($sourceVal) && in_array($sourceVal, [
                SeoCreateArticleSettingsService::SOURCE_PROMPT,
                SeoCreateArticleSettingsService::SOURCE_WORKFLOW,
                SeoCreateArticleSettingsService::SOURCE_NONE,
            ], true)) {
                $patch[(string) $sourceKey] = $sourceVal;
            }
        }

        if ($patch !== []) {
            $settingsService->saveSettings($patch);
        }
    }

    private function promptByUuid(int $userId, string $uuid): ?SeoPrompt
    {
        $query = SeoPrompt::query()->withTrashed()->where('user_id', $userId);
        try {
            $byCol = (clone $query)->where('portable_uuid', $uuid)->first();
            if ($byCol instanceof SeoPrompt) {
                return $byCol;
            }
        } catch (\Throwable) {
        }

        foreach ($query->get() as $prompt) {
            $settings = is_array($prompt->settings) ? $prompt->settings : [];
            if (trim((string) ($settings['portable_uuid'] ?? '')) === $uuid) {
                return $prompt;
            }
        }

        return null;
    }

    private function taskByUuid(int $userId, string $uuid): ?SeoTask
    {
        return (new TaskPortableIdentity())->findByUuid($userId, $uuid);
    }
}
