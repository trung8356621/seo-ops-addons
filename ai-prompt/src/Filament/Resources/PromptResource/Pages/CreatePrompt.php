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

        // model_category không còn trên form — lưu default provider nếu cột còn; không dùng để route image.
        if (blank($data['model_category'] ?? null) && filled($data['ai_connection_id'] ?? null)) {
            $data['model_category'] = PromptResource::defaultModelCategoryForConnection($data['ai_connection_id']);
        }

        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $data['settings'] = PromptPostProcessing::mergeIntoSettings(
            $settings,
            is_array($settings['post_processing'] ?? null) ? $settings['post_processing'] : [],
        );

        // Legacy column kept; ownership model ignores it at runtime.
        $data['is_active'] = true;

        return PromptHookFormSchema::normalizeForSave($data);
    }
}
