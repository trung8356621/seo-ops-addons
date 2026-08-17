<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SettingsTransfer;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\PromptPack\PromptPortableIdentity;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;

final class WorkflowBindingsSection implements PortableSettingsSection
{
    public function key(): string
    {
        return 'workflows';
    }

    public function export(int $userId): array
    {
        $identity = new PromptPortableIdentity();
        $bindings = app(SeoCreateArticleSettingsService::class)->getPromptHookBindings();
        $portable = [];
        foreach ($bindings as $hook => $promptId) {
            $prompt = SeoPrompt::query()->where('user_id', $userId)->whereKey((int) $promptId)->first();
            if (! $prompt instanceof SeoPrompt) {
                continue;
            }
            $portable[$hook] = ['portable_uuid' => $identity->ensure($prompt)];
        }

        return [
            'prompt_bindings' => $portable,
            '_excluded' => [
                'publish_article_task_id',
                'create_image_task_id',
                'create_video_task_id',
                'post_review_task_id',
            ],
        ];
    }

    public function diff(int $userId, array $incoming): array
    {
        $current = $this->export($userId);
        $after = is_array($incoming['prompt_bindings'] ?? null) ? $incoming['prompt_bindings'] : [];
        $before = $current['prompt_bindings'] ?? [];
        $changed = json_encode($before) === json_encode($after) ? 0 : 1;
        $warnings = [];
        foreach ($after as $hook => $ref) {
            $uuid = is_array($ref) ? (string) ($ref['portable_uuid'] ?? '') : '';
            if ($uuid !== '' && $this->promptByUuid($userId, $uuid) === null) {
                $warnings[] = 'Prompt binding '.$hook.' requires a prompt that is not on this workspace yet.';
            }
        }

        return [
            'changed' => $changed,
            'unchanged' => $changed === 0 ? 1 : 0,
            'lines' => $changed === 1 ? ['Prompt hook bindings updated'] : [],
            'warnings' => $warnings,
            'payload' => ['prompt_bindings' => $after],
        ];
    }

    public function apply(int $userId, array $incoming, string $mode): void
    {
        unset($mode);
        $after = is_array($incoming['prompt_bindings'] ?? null) ? $incoming['prompt_bindings'] : [];
        $ids = [];
        foreach ($after as $hook => $ref) {
            $uuid = is_array($ref) ? (string) ($ref['portable_uuid'] ?? '') : '';
            $prompt = $uuid !== '' ? $this->promptByUuid($userId, $uuid) : null;
            if ($prompt instanceof SeoPrompt) {
                $ids[(string) $hook] = (int) $prompt->id;
            }
        }
        if ($ids === []) {
            return;
        }
        app(SeoCreateArticleSettingsService::class)->saveSettings([
            SeoCreateArticleSettingsService::KEY_PROMPT_HOOK_BINDINGS => $ids,
        ]);
    }

    private function promptByUuid(int $userId, string $uuid): ?SeoPrompt
    {
        foreach (SeoPrompt::query()->where('user_id', $userId)->get() as $prompt) {
            $settings = is_array($prompt->settings) ? $prompt->settings : [];
            if (trim((string) ($settings['portable_uuid'] ?? $prompt->getAttribute('portable_uuid') ?? '')) === $uuid) {
                return $prompt;
            }
        }

        return null;
    }
}
