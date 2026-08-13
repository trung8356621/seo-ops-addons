<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;

/**
 * Prompt option lists for Settings — no is_active filter (ownership model).
 */
final class SeoPromptSettingsOptionsService
{
    /**
     * @return array<int, string>
     */
    public function activePromptOptions(): array
    {
        return $this->promptOptionsForTools(null);
    }

    /**
     * @return array<int, string>
     */
    public function activeImagePromptOptions(): array
    {
        return $this->promptOptionsForTools(['image']);
    }

    /**
     * @return array<int, string>
     */
    public function activeAnyImagePromptOptions(): array
    {
        return $this->promptOptionsForTools(['image', 'image_typography']);
    }

    /**
     * @return array<int, string>
     */
    public function activeTypographyImagePromptOptions(): array
    {
        return $this->promptOptionsForTools(['image_typography']);
    }

    /**
     * @return array<int, string>
     */
    public function activeVideoPromptOptions(): array
    {
        return $this->promptOptionsForTools(['video']);
    }

    /**
     * @return array<int, string>
     */
    public function activePromptOptionsForHook(string $hookKey): array
    {
        return $this->promptOptionsForHook($hookKey);
    }

    /**
     * Prompt gắn đúng hook_key (Settings binding dropdown).
     * `$includePromptId` giữ label cho binding legacy (hook_key trống / lệch) — tránh Filament chỉ hiện ID.
     *
     * @return array<int, string>
     */
    public function promptOptionsForHook(string $hookKey, ?int $includePromptId = null): array
    {
        $hookKey = trim($hookKey);
        if ($hookKey === '') {
            return [];
        }

        $options = SeoPrompt::query()
            ->where('hook_key', $hookKey)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(static fn (mixed $name, mixed $id): array => [
                (int) $id => trim((string) $name) !== '' ? trim((string) $name) : ('#'.(int) $id),
            ])
            ->all();

        return $this->ensurePromptOption($options, $includePromptId, $hookKey);
    }

    public function promptLabel(mixed $promptId): ?string
    {
        $id = (int) $promptId;
        if ($id <= 0) {
            return null;
        }

        $prompt = SeoPrompt::query()->find($id);
        if ($prompt === null) {
            return '#'.$id;
        }

        $name = trim((string) ($prompt->name ?? ''));

        return $name !== '' ? $name : '#'.$id;
    }

    /**
     * @param  list<string>|null  $tools
     * @return array<int, string>
     */
    public function promptOptionsForTools(?array $tools, ?int $includePromptId = null): array
    {
        $query = SeoPrompt::query();

        if ($tools !== null && $tools !== []) {
            $query->whereIn('tools', $tools);
        }

        $options = $query
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(static fn (mixed $name, mixed $id): array => [
                (int) $id => trim((string) $name) !== '' ? trim((string) $name) : ('#'.(int) $id),
            ])
            ->all();

        return $this->ensurePromptOption($options, $includePromptId, null);
    }

    /**
     * @param  list<string>|null  $tools
     * @return array<int, string>
     */
    private function activePromptOptionsForTools(?array $tools): array
    {
        return $this->promptOptionsForTools($tools);
    }

    /**
     * @param  array<int, string>  $options
     * @return array<int, string>
     */
    private function ensurePromptOption(array $options, ?int $includePromptId, ?string $expectedHookKey): array
    {
        $id = (int) ($includePromptId ?? 0);
        if ($id <= 0 || array_key_exists($id, $options)) {
            return $options;
        }

        $prompt = SeoPrompt::query()->find($id);
        if ($prompt === null) {
            $options[$id] = '#'.$id;

            return $options;
        }

        $name = trim((string) ($prompt->name ?? ''));
        $label = $name !== '' ? $name : '#'.$id;
        $promptHook = trim((string) ($prompt->hook_key ?? ''));

        if ($expectedHookKey !== null && $expectedHookKey !== '') {
            if ($promptHook === '') {
                $label .= ' ('.(string) __('seo-content-ai::filament.settings_workflows.prompt_missing_hook_key').')';
            } elseif ($promptHook !== $expectedHookKey) {
                $label .= ' ('.(string) __('seo-content-ai::filament.settings_workflows.prompt_wrong_hook_key', [
                    'hook' => $promptHook,
                ]).')';
            }
        }

        $options[$id] = $label;

        return $options;
    }
}
