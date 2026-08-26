<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource\Pages;

use Omnichannel\Addons\Seo\Filament\Resources\Pages\SeoCreateRecord;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource;
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookFormSchema;
use Omnichannel\Addons\AiPrompt\Support\PromptPostProcessing;

class CreatePrompt extends SeoCreateRecord
{
    protected static string $resource = PromptResource::class;

    public function mount(): void
    {
        parent::mount();

        $state = $this->form->getState();
        $settings = is_array($state['settings'] ?? null) ? $state['settings'] : [];
        $this->form->fill([
            'settings' => PromptPostProcessing::mergeIntoSettings(
                $settings,
                is_array($settings['post_processing'] ?? null) ? $settings['post_processing'] : [],
            ),
        ]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $markdown = trim((string) ($data['markdown_content'] ?? ''));

        $data['user_id'] = auth()->id();
        $data['title'] = $data['name'] ?? $data['title'] ?? '';
        $data['variables'] = PromptResource::mergeVariablesFromMarkdown(
            $markdown,
            $data['variables'] ?? [],
        );

        // Legacy routing columns are not collected on modern create form.
        unset(
            $data['model_category'],
            $data['routing_mode'],
            $data['routing_profile_key'],
            $data['ai_connection_id'],
        );

        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        // Do not seed obsolete prompt-level routing keys.
        unset($settings['routing_family_key'], $settings['usage_mode']);
        $data['settings'] = PromptPostProcessing::mergeIntoSettings(
            $settings,
            is_array($settings['post_processing'] ?? null) ? $settings['post_processing'] : [],
        );

        // Legacy column kept; ownership model ignores it at runtime.
        $data['is_active'] = true;

        return PromptHookFormSchema::normalizeForSave($data);
    }
}
