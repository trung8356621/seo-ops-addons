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
     *
     * @return array<int, string>
     */
    public function promptOptionsForHook(string $hookKey): array
    {
        $hookKey = trim($hookKey);
        if ($hookKey === '') {
            return [];
        }

        return SeoPrompt::query()
            ->where('hook_key', $hookKey)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @param  list<string>|null  $tools
     * @return array<int, string>
     */
    public function promptOptionsForTools(?array $tools): array
    {
        $query = SeoPrompt::query();

        if ($tools !== null && $tools !== []) {
            $query->whereIn('tools', $tools);
        }

        return $query
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @param  list<string>|null  $tools
     * @return array<int, string>
     */
    private function activePromptOptionsForTools(?array $tools): array
    {
        return $this->promptOptionsForTools($tools);
    }
}
