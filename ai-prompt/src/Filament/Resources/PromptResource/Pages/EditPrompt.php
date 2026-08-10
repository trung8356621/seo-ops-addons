<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource\Pages;

use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsOverview;
use Omnichannel\Addons\Seo\Filament\Resources\Pages\SeoEditRecord;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\VersionNotFound;
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookFormSchema;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeSettingsResolver;
use Omnichannel\Addons\AiPrompt\Services\AiModelsReadinessService;
use Omnichannel\Addons\Media\Support\ImageToolType;
use Omnichannel\Addons\AiPrompt\Support\PromptPostProcessing;
use Filament\Actions;

class EditPrompt extends SeoEditRecord
{
    protected static string $resource = PromptResource::class;

    protected function getHeaderActions(): array
    {
        $readiness = app(AiModelsReadinessService::class);
        $record = $this->getRecord();
        $isReady = $readiness->isPromptReady($record);

        return [
            Actions\Action::make('test')
                ->label($isReady ? 'Run test' : 'Sync models')
                ->icon($isReady ? 'heroicon-o-play' : 'heroicon-o-cpu-chip')
                ->color($isReady ? 'success' : 'warning')
                ->url(
                    $isReady
                        ? PromptResource::getUrl('test', ['record' => $record])
                        : SeoSettingsOverview::getUrl(),
                ),
            Actions\DeleteAction::make()
                ->form(function (): array {
                    $record = $this->getRecord();
                    $locator = app(\Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptUsageLocator::class);
                    if (! $locator->isReferenced((int) $record->id)) {
                        return [];
                    }

                    $lines = $locator->summarize((int) $record->id);

                    return [
                        \Filament\Forms\Components\Placeholder::make('usage_list')
                            ->label(__('seo-content-ai::filament.prompt.delete_in_use_title'))
                            ->content(new \Illuminate\Support\HtmlString(
                                '<ul class="list-disc pl-5 text-sm space-y-1">'
                                .implode('', array_map(
                                    static fn (string $line): string => '<li>'.e($line).'</li>',
                                    $lines,
                                ))
                                .'</ul>'
                            )),
                        \Filament\Forms\Components\Checkbox::make('force_detach')
                            ->label(__('seo-content-ai::filament.prompt.force_detach'))
                            ->helperText(__('seo-content-ai::filament.prompt.force_detach_help'))
                            ->required(),
                    ];
                })
                ->before(function (array $data): void {
                    $record = $this->getRecord();
                    $guard = app(\Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptDeleteGuard::class);
                    $locator = app(\Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptUsageLocator::class);
                    if (! $locator->isReferenced((int) $record->id)) {
                        return;
                    }
                    if (! (bool) ($data['force_detach'] ?? false)) {
                        $guard->assertDeletable((int) $record->id);
                    }
                    $guard->detachUsages((int) $record->id);
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $markdown = trim((string) ($data['markdown_content'] ?? ''));

        $data['variables'] = PromptResource::mergeVariablesFromMarkdown(
            $markdown,
            $this->record->variables ?? [],
        );

        // Field model_category đã gỡ khỏi form — không đẩy vào state (tránh clear cột khi save).
        unset($data['model_category']);

        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $data['settings'] = PromptPostProcessing::mergeIntoSettings(
            $settings,
            is_array($settings['post_processing'] ?? null) ? $settings['post_processing'] : [],
        );

        $hookKey = trim((string) ($data['hook_key'] ?? ''));
        if ($hookKey !== '') {
            try {
                // Canonical catalog (semver 0.1.0) — không dùng legacy registry (version int 1).
                // Legacy version trên form làm settingsFields rỗng + normalizeForSave ném VersionNotFound (save fail, không toast).
                $catalog = app(PromptHookEditorCatalog::class);
                $storedVersion = trim((string) ($data['hook_version'] ?? ''));
                try {
                    $definition = $storedVersion !== ''
                        ? $catalog->find($hookKey, $storedVersion)
                        : $catalog->latestPinnedOrFail($hookKey);
                } catch (VersionNotFound) {
                    $definition = $catalog->latestPinnedOrFail($hookKey);
                }
                $data['hook_version'] = $definition->version->toString();
                $resolved = app(PromptHookRuntimeSettingsResolver::class)
                    ->resolve(
                        $definition,
                        is_array($data['hook_settings'] ?? null) ? $data['hook_settings'] : [],
                        [],
                    );
                $data['hook_settings'] = $resolved['hook'];
            } catch (\Throwable) {
                // Hook manifest thiếu / đổi key — giữ raw state.
            }
        } else {
            $data['hook_key'] = null;
            $data['hook_version'] = null;
            $data['hook_settings'] = null;
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $markdown = trim((string) ($data['markdown_content'] ?? ''));

        if (isset($data['name'])) {
            $data['title'] = $data['name'];
        }

        $data['variables'] = PromptResource::mergeVariablesFromMarkdown(
            $markdown,
            $data['variables'] ?? [],
        );

        // Giữ giá trị DB cũ — không cập nhật từ form.
        unset($data['model_category']);

        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $postProcessing = is_array($settings['post_processing'] ?? null) ? $settings['post_processing'] : [];

        if (! ImageToolType::fromMixed($data['tools'] ?? 'default')->isImagePipeline()
            && $postProcessing === []) {
            $existingSettings = is_array($this->record->settings ?? null) ? $this->record->settings : [];
            $postProcessing = is_array($existingSettings['post_processing'] ?? null)
                ? $existingSettings['post_processing']
                : [];
        }

        $data['settings'] = PromptPostProcessing::mergeIntoSettings(
            $settings,
            $postProcessing,
            (int) ($this->record->id ?? 0) ?: null,
        );

        $oldHook = trim((string) ($this->record->hook_key ?? ''));
        $newHook = trim((string) ($data['hook_key'] ?? ''));
        app(\Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptDeleteGuard::class)
            ->assertHookChangeAllowed((int) $this->record->id, $oldHook !== '' ? $oldHook : null, $newHook !== '' ? $newHook : null);

        return PromptHookFormSchema::normalizeForSave($data);
    }
}
