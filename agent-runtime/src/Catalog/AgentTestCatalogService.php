<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Catalog;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\Media\Support\ImageToolType;

final class AgentTestCatalogService
{
    /** @return list<array{type: 'prompt'|'task', id: int, label: string, output_type: 'text'|'image'|'video'}> */
    public function forOwner(int $ownerId): array
    {
        if ($ownerId <= 0) {
            return [];
        }

        $prompts = SeoPrompt::query()
            ->select(['id', 'name', 'tools'])
            ->where('user_id', $ownerId)
            ->where('is_active', true)
            ->where('is_flow_prompt', false)
            ->orderBy('name')
            ->get()
            ->map(static fn (SeoPrompt $prompt): array => [
                'type' => 'prompt',
                'id' => (int) $prompt->id,
                'label' => (string) $prompt->name,
                'output_type' => self::promptOutputType($prompt->tools),
            ])
            ->all();

        $tasks = SeoTask::query()
            ->select(['id', 'name', 'output_type'])
            ->where('user_id', $ownerId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(static fn (SeoTask $task): array => [
                'type' => 'task',
                'id' => (int) $task->id,
                'label' => self::taskLabel((string) $task->name),
                'output_type' => self::normalizeOutputType($task->output_type),
            ])
            ->all();

        return [...$prompts, ...$tasks];
    }

    public static function taskLabel(string $name): string
    {
        $name = trim($name);
        if (str_starts_with(strtolower($name), 'task.') || str_starts_with($name, 'Task ')) {
            return $name;
        }

        return 'Task · '.$name;
    }

    private static function promptOutputType(mixed $tools): string
    {
        $tool = ImageToolType::fromMixed($tools);
        if ($tool === ImageToolType::Video) {
            return 'video';
        }

        return $tool->isImagePipeline() ? 'image' : 'text';
    }

    private static function normalizeOutputType(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, ['text', 'image', 'video'], true) ? $value : 'text';
    }
}
