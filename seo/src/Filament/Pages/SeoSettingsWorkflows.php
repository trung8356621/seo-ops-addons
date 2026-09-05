<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Pages;

use Omnichannel\Addons\ContentProjects\Enums\WorkflowCapability;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;
use Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptHookPresentationService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsOptionsService;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowAssignmentValidator;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoStackAvailability;
use App\Help\HelpUi;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Workflows (Task) + dynamic Prompt Hook bindings + Editor Media (typography/video).
 */
class SeoSettingsWorkflows extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'settings/workflows';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Workflows';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-workflows';

    /** @var array<string, mixed> */
    public array $settingsData = [];

    public function mount(SeoCreateArticleSettingsService $settings): void
    {
        $this->settingsData = $settings->getSettings();
        $this->settingsData[SeoCreateArticleSettingsService::KEY_PROMPT_HOOK_BINDINGS] =
            $settings->encodePromptHookBindingsForForm($settings->getPromptHookBindings());
        $this->form->fill($this->settingsData);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_workflows.workflows_section'))
                    ->headerActions([HelpUi::fieldHintAction('settings.workflow.overview')])
                    ->schema([
                        $this->taskSelect(
                            SeoCreateArticleSettingsService::KEY_PUBLISH_ARTICLE,
                            __('seo-content-ai::filament.settings_workflows.publish_article'),
                        ),
                        // KEY_REWRITE_ARTICLE: legacy DB field giữ tạm — không đọc runtime / không hiện UI.
                        $this->taskSelect(
                            SeoCreateArticleSettingsService::KEY_POST_REVIEW,
                            __('seo-content-ai::filament.settings_workflows.post_review'),
                        ),
                        Forms\Components\Placeholder::make('workflow_health_publish')
                            ->label('')
                            ->content(fn (Get $get, WorkflowAssignmentValidator $validator): HtmlString => $this->workflowHealthHtml(
                                $validator,
                                $this->positiveIntOrNull($get(SeoCreateArticleSettingsService::KEY_PUBLISH_ARTICLE)),
                                WorkflowCapability::PublishArticle,
                            )),
                    ]),

                Forms\Components\Section::make(__('seo-content-ai::filament.settings_workflows.editor_media_section'))
                    ->headerActions([HelpUi::fieldHintAction('settings.workflow.editor_media')])
                    ->schema([
                        ...$this->editorMediaSourceFields(
                            sourceKey: SeoCreateArticleSettingsService::KEY_CREATE_TYPOGRAPHY_IMAGE_SOURCE,
                            promptKey: SeoCreateArticleSettingsService::KEY_CREATE_TYPOGRAPHY_IMAGE_PROMPT,
                            taskKey: SeoCreateArticleSettingsService::KEY_CREATE_TYPOGRAPHY_IMAGE_TASK,
                            label: __('seo-content-ai::filament.settings_workflows.create_typography_image'),
                            promptOptions: fn (SeoPromptSettingsOptionsService $options): array => $options->promptOptionsForTools(['image_typography']),
                        ),
                        ...$this->productGallerySourceFields(),
                        ...$this->editorMediaSourceFields(
                            sourceKey: SeoCreateArticleSettingsService::KEY_CREATE_VIDEO_SOURCE,
                            promptKey: SeoCreateArticleSettingsService::KEY_CREATE_VIDEO,
                            taskKey: SeoCreateArticleSettingsService::KEY_CREATE_VIDEO_TASK,
                            label: __('seo-content-ai::filament.settings_workflows.create_video'),
                            promptOptions: fn (SeoPromptSettingsOptionsService $options): array => $options->promptOptionsForTools(['video']),
                            isVideo: true,
                        ),
                    ]),

                Forms\Components\Section::make(__('seo-content-ai::filament.settings_workflows.prompt_hooks_section'))
                    ->headerActions([HelpUi::fieldHintAction('settings.workflow.prompt_hooks')])
                    ->schema($this->dynamicHookBindingFields()),
            ])
            ->statePath('settingsData');
    }

    /**
     * @return list<Forms\Components\Component>
     */
    private function dynamicHookBindingFields(): array
    {
        $catalog = app(PromptHookEditorCatalog::class);
        $presentation = app(PromptHookPresentationService::class);
        $fields = [];

        foreach ($catalog->settingsVisibleHooks() as $hook) {
            $hookKey = $hook['hook_key'];
            $view = $presentation->forHook($hookKey);
            if ($view === null) {
                $view = [
                    'hook_key' => $hookKey,
                    'label' => $hook['display_name'],
                    'description' => (string) ($hook['description'] ?? ''),
                    'sections' => [],
                    'default_instructions' => [],
                    'output_format' => [],
                    'notes' => [],
                    'inputs' => [],
                    'uses_prompt_markdown' => true,
                    'content_mode' => PromptHookPresentationService::CONTENT_MODE_LEGACY_PROMPT,
                ];
            }

            $encodedKey = SeoCreateArticleSettingsService::encodeHookKeyForForm($hookKey);
            $bindingPath = SeoCreateArticleSettingsService::KEY_PROMPT_HOOK_BINDINGS.'.'.$encodedKey;
            $sectionSchema = [
                Forms\Components\Select::make($bindingPath)
                    ->label(__('seo-content-ai::filament.settings_workflows.choose_prompt'))
                    ->options(function (SeoPromptSettingsOptionsService $options, Get $get) use ($hookKey, $bindingPath): array {
                        $selected = (int) ($get($bindingPath) ?? 0);

                        return $options->promptOptionsForHook($hookKey, $selected > 0 ? $selected : null);
                    })
                    ->getOptionLabelUsing(fn (mixed $value): ?string => app(SeoPromptSettingsOptionsService::class)->promptLabel($value))
                    ->searchable()
                    ->native(false)
                    ->position('auto')
                    ->nullable()
                    ->live()
                    ->placeholder(__('seo-content-ai::filament.settings_workflows.choose_prompt'))
                    ->hintActions([
                        Forms\Components\Actions\Action::make('open_prompts_'.$encodedKey)
                            ->label(__('seo-content-ai::filament.settings_workflows.open_prompt_management'))
                            ->url(PromptResource::getUrl('index', [
                                'tableFilters' => ['hook_key' => ['value' => $hookKey]],
                            ]))
                            ->openUrlInNewTab(),
                    ]),
            ];

            $guidanceHtml = $presentation->formatSectionsHtml($view);
            if ($guidanceHtml !== '') {
                $sectionSchema[] = Forms\Components\Section::make(
                    __('seo-content-ai::filament.settings_workflows.hook_view_default_guidance')
                )
                    ->schema([
                        Forms\Components\Placeholder::make('hook_guidance_'.$encodedKey)
                            ->label('')
                            ->content(new HtmlString($guidanceHtml)),
                    ])
                    ->collapsible()
                    ->collapsed()
                    ->compact();
            }

            $fields[] = Forms\Components\Section::make(
                app(PromptHookEditorCatalog::class)->labelWithHookKey(
                    (string) ($view['label'] ?? $hook['display_name']),
                    $hookKey,
                )
            )
                ->schema($sectionSchema)
                ->collapsible()
                ->collapsed(false)
                ->extraAttributes([
                    'class' => 'seo-settings-hook-card',
                    'id' => 'seo-hook-card-'.$encodedKey,
                ]);
        }

        return $fields;
    }

    /**
     * Product Gallery: mode only in media section. Prompt ownership = Hook card.
     *
     * @return list<Forms\Components\Component>
     */
    private function productGallerySourceFields(): array
    {
        return [
            Forms\Components\Radio::make(SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_SOURCE)
                ->label(__('seo-content-ai::filament.settings_workflows.create_product_gallery_source'))
                ->options([
                    SeoCreateArticleSettingsService::SOURCE_PROMPT => __('seo-content-ai::filament.settings_workflows.source_prompt'),
                    SeoCreateArticleSettingsService::SOURCE_WORKFLOW => __('seo-content-ai::filament.settings_workflows.source_workflow'),
                ])
                ->inline()
                ->live(),
            Forms\Components\Placeholder::make('product_gallery_prompt_status')
                ->label(__('seo-content-ai::filament.settings_workflows.product_gallery_prompt_status_label'))
                ->content(fn (Get $get): HtmlString => $this->productGalleryPromptStatusHtml($get))
                ->visible(fn (Get $get): bool => ($get(SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_SOURCE) ?? '')
                    === SeoCreateArticleSettingsService::SOURCE_PROMPT),
            Forms\Components\Select::make(SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_TASK)
                ->label(__('seo-content-ai::filament.settings_workflows.choose_workflow'))
                ->options(function (CreateArticlesFromTaskService $service, Get $get): array {
                    $selected = (int) ($get(SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_TASK) ?? 0);

                    return $service->taskOptionsForSettings($selected > 0 ? $selected : null);
                })
                ->getOptionLabelUsing(fn (mixed $value): ?string => app(CreateArticlesFromTaskService::class)->taskLabel($value))
                ->searchable()
                ->native(false)
                ->position('auto')
                ->placeholder(__('seo-content-ai::filament.settings_workflows.choose_workflow'))
                ->visible(fn (Get $get): bool => ($get(SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_SOURCE) ?? '')
                    === SeoCreateArticleSettingsService::SOURCE_WORKFLOW),
        ];
    }

    private function productGalleryPromptStatusHtml(Get $get): HtmlString
    {
        $encoded = SeoCreateArticleSettingsService::encodeHookKeyForForm('product.gallery.generate');
        $anchor = '#seo-hook-card-'.$encoded;
        $bindings = is_array($get(SeoCreateArticleSettingsService::KEY_PROMPT_HOOK_BINDINGS) ?? null)
            ? $get(SeoCreateArticleSettingsService::KEY_PROMPT_HOOK_BINDINGS)
            : [];
        $promptId = isset($bindings[$encoded]) ? (int) $bindings[$encoded] : 0;
        if ($promptId <= 0) {
            return new HtmlString(
                '<p class="text-sm text-amber-700 dark:text-amber-300">'
                .e((string) __('seo-content-ai::filament.settings_workflows.product_gallery_prompt_missing'))
                .' <a href="'.e($anchor).'" class="font-medium underline">'
                .e((string) __('seo-content-ai::filament.settings_workflows.product_gallery_open_hook_card'))
                .'</a></p>'
            );
        }

        $name = \Omnichannel\Addons\AiPrompt\Models\SeoPrompt::query()->whereKey($promptId)->value('name');
        $label = is_string($name) && trim($name) !== '' ? trim($name) : ('#'.$promptId);

        return new HtmlString(
            '<p class="text-sm text-gray-700 dark:text-gray-200">'
            .e((string) __('seo-content-ai::filament.settings_workflows.product_gallery_prompt_using', ['name' => $label]))
            .'</p>'
            .'<p class="mt-1 text-sm">'
            .'<a href="'.e($anchor).'" class="font-medium text-primary-600 underline dark:text-primary-400">'
            .e((string) __('seo-content-ai::filament.settings_workflows.product_gallery_manage_at_hook'))
            .'</a></p>'
        );
    }

    private function taskSelect(
        string $field,
        string $label,
    ): Forms\Components\Select {
        return Forms\Components\Select::make($field)
            ->label($label)
            ->options(function (CreateArticlesFromTaskService $service, Get $get) use ($field): array {
                $selected = (int) ($get($field) ?? 0);

                return $service->taskOptionsForSettings($selected > 0 ? $selected : null);
            })
            ->getOptionLabelUsing(fn (mixed $value): ?string => app(CreateArticlesFromTaskService::class)->taskLabel($value))
            ->searchable()
            ->native(false)
            ->position('auto')
            ->live()
            ->placeholder(__('seo-content-ai::filament.settings_workflows.choose_workflow'));
    }

    private function workflowHealthHtml(
        WorkflowAssignmentValidator $validator,
        ?int $taskId,
        WorkflowCapability $capability,
    ): HtmlString {
        $health = $validator->healthForTaskId($taskId, $capability);
        if ($health['message'] === '') {
            return new HtmlString('');
        }

        $color = $health['ok'] ? 'text-success-600' : 'text-warning-600';
        $link = '';
        if ($taskId !== null && $taskId > 0) {
            try {
                $url = TaskResource::getUrl('builder', ['record' => $taskId]);
                $link = ' <a href="'.e($url).'" class="underline font-medium" wire:navigate>'
                    .e(__('seo-content-ai::filament.settings_workflows.open_workflow_builder'))
                    .'</a>';
            } catch (\Throwable) {
                $link = '';
            }
        }

        return new HtmlString(
            '<p class="text-sm '.$color.'">'.e($health['message']).$link.'</p>',
        );
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /**
     * @param  callable(SeoPromptSettingsOptionsService): array<int, string>  $promptOptions
     * @return list<Forms\Components\Component>
     */
    private function editorMediaSourceFields(
        string $sourceKey,
        string $promptKey,
        string $taskKey,
        string $label,
        callable $promptOptions,
        bool $isVideo = false,
    ): array {
        $radio = Forms\Components\Radio::make($sourceKey)
            ->label($label)
            ->options([
                SeoCreateArticleSettingsService::SOURCE_PROMPT => __('seo-content-ai::filament.settings_workflows.source_prompt'),
                SeoCreateArticleSettingsService::SOURCE_WORKFLOW => __('seo-content-ai::filament.settings_workflows.source_workflow'),
            ])
            ->inline()
            ->live();

        return [
            $radio,
            Forms\Components\Select::make($promptKey)
                ->label(__('seo-content-ai::filament.settings_workflows.choose_prompt'))
                ->options(function (SeoPromptSettingsOptionsService $options, Get $get) use ($promptOptions, $promptKey): array {
                    $base = $promptOptions($options);
                    $selected = (int) ($get($promptKey) ?? 0);
                    if ($selected > 0 && ! array_key_exists($selected, $base)) {
                        $label = $options->promptLabel($selected);
                        if ($label !== null) {
                            $base[$selected] = $label;
                        }
                    }

                    return $base;
                })
                ->getOptionLabelUsing(fn (mixed $value): ?string => app(SeoPromptSettingsOptionsService::class)->promptLabel($value))
                ->searchable()
                ->native(false)
                ->position('auto')
                ->placeholder($isVideo
                    ? __('seo-content-ai::filament.settings_workflows.choose_video_prompt')
                    : __('seo-content-ai::filament.settings_workflows.choose_image_prompt'))
                ->visible(fn (Get $get): bool => ($get($sourceKey) ?? SeoCreateArticleSettingsService::SOURCE_PROMPT)
                    === SeoCreateArticleSettingsService::SOURCE_PROMPT),
            Forms\Components\Select::make($taskKey)
                ->label(__('seo-content-ai::filament.settings_workflows.choose_workflow'))
                ->options(function (CreateArticlesFromTaskService $service, Get $get) use ($taskKey): array {
                    $selected = (int) ($get($taskKey) ?? 0);

                    return $service->taskOptionsForSettings($selected > 0 ? $selected : null);
                })
                ->getOptionLabelUsing(fn (mixed $value): ?string => app(CreateArticlesFromTaskService::class)->taskLabel($value))
                ->searchable()
                ->native(false)
                ->position('auto')
                ->placeholder(__('seo-content-ai::filament.settings_workflows.choose_workflow'))
                ->visible(fn (Get $get): bool => ($get($sourceKey) ?? '')
                    === SeoCreateArticleSettingsService::SOURCE_WORKFLOW),
        ];
    }

    public function saveSettings(SeoCreateArticleSettingsService $settings): void
    {
        try {
            $data = $this->form->getState();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.settings_workflows.save_failed'))
                ->body(collect($exception->errors())->flatten()->first() ?: $exception->getMessage())
                ->danger()
                ->send();

            throw $exception;
        }

        $rawBindings = is_array($data[SeoCreateArticleSettingsService::KEY_PROMPT_HOOK_BINDINGS] ?? null)
            ? $data[SeoCreateArticleSettingsService::KEY_PROMPT_HOOK_BINDINGS]
            : [];
        $bindings = $settings->decodePromptHookBindingsFromForm($rawBindings);

        try {
            $settings->assertValidPromptHookBindings($bindings);
            $this->assertProductGalleryModeConfigured($data, $bindings);
            $assignmentErrors = app(WorkflowAssignmentValidator::class)->validatePendingSettings($data);
            if ($assignmentErrors !== []) {
                throw ValidationException::withMessages([
                    SeoCreateArticleSettingsService::KEY_PUBLISH_ARTICLE => $assignmentErrors,
                ]);
            }
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.settings_workflows.save_failed'))
                ->body($this->formatWorkflowValidationBody($exception))
                ->danger()
                ->send();

            throw $exception;
        }

        $settings->saveSettings([
            SeoCreateArticleSettingsService::KEY_PUBLISH_ARTICLE => $data[SeoCreateArticleSettingsService::KEY_PUBLISH_ARTICLE] ?? null,
            // Legacy field: giữ giá trị cũ (rollback), không nhận từ UI đã ẩn.
            SeoCreateArticleSettingsService::KEY_REWRITE_ARTICLE => $settings->getSettings()[SeoCreateArticleSettingsService::KEY_REWRITE_ARTICLE] ?? null,
            SeoCreateArticleSettingsService::KEY_POST_REVIEW => $data[SeoCreateArticleSettingsService::KEY_POST_REVIEW] ?? null,
            SeoCreateArticleSettingsService::KEY_PROMPT_HOOK_BINDINGS => $bindings,
            SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_SOURCE => $data[SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_SOURCE] ?? null,
            SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_TASK => $data[SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_TASK] ?? null,
            SeoCreateArticleSettingsService::KEY_CREATE_TYPOGRAPHY_IMAGE_SOURCE => $data[SeoCreateArticleSettingsService::KEY_CREATE_TYPOGRAPHY_IMAGE_SOURCE] ?? null,
            SeoCreateArticleSettingsService::KEY_CREATE_TYPOGRAPHY_IMAGE_PROMPT => $data[SeoCreateArticleSettingsService::KEY_CREATE_TYPOGRAPHY_IMAGE_PROMPT] ?? null,
            SeoCreateArticleSettingsService::KEY_CREATE_TYPOGRAPHY_IMAGE_TASK => $data[SeoCreateArticleSettingsService::KEY_CREATE_TYPOGRAPHY_IMAGE_TASK] ?? null,
            SeoCreateArticleSettingsService::KEY_CREATE_VIDEO_SOURCE => $data[SeoCreateArticleSettingsService::KEY_CREATE_VIDEO_SOURCE] ?? null,
            SeoCreateArticleSettingsService::KEY_CREATE_VIDEO => $data[SeoCreateArticleSettingsService::KEY_CREATE_VIDEO] ?? null,
            SeoCreateArticleSettingsService::KEY_CREATE_VIDEO_TASK => $data[SeoCreateArticleSettingsService::KEY_CREATE_VIDEO_TASK] ?? null,
        ]);

        $this->settingsData[SeoCreateArticleSettingsService::KEY_PROMPT_HOOK_BINDINGS] =
            $settings->encodePromptHookBindingsForForm($settings->getPromptHookBindings());
        $this->form->fill($this->settingsData);

        Notification::make()
            ->title(__('seo-content-ai::filament.settings_workflows.saved'))
            ->success()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, int>  $bindings
     */
    private function assertProductGalleryModeConfigured(array $data, array $bindings): void
    {
        $source = (string) ($data[SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_SOURCE] ?? '');
        if ($source === SeoCreateArticleSettingsService::SOURCE_PROMPT) {
            if (! isset($bindings['product.gallery.generate'])) {
                throw ValidationException::withMessages([
                    SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_SOURCE => [
                        (string) __('seo-content-ai::filament.settings_workflows.product_gallery_prompt_required'),
                    ],
                ]);
            }

            return;
        }

        if ($source === SeoCreateArticleSettingsService::SOURCE_WORKFLOW) {
            $taskId = (int) ($data[SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_TASK] ?? 0);
            if ($taskId <= 0) {
                throw ValidationException::withMessages([
                    SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_TASK => [
                        (string) __('seo-content-ai::filament.settings_workflows.product_gallery_workflow_required'),
                    ],
                ]);
            }
        }
    }

    private function formatWorkflowValidationBody(ValidationException $exception): string
    {
        $messages = collect($exception->errors())->flatten()->filter()->values()->all();
        $body = $messages !== []
            ? implode("\n", $messages)
            : $exception->getMessage();

        $publishErrors = $exception->errors()[SeoCreateArticleSettingsService::KEY_PUBLISH_ARTICLE] ?? [];
        $taskId = $this->positiveIntOrNull($this->settingsData[SeoCreateArticleSettingsService::KEY_PUBLISH_ARTICLE] ?? null);
        if ($publishErrors !== [] && $taskId !== null) {
            try {
                $url = TaskResource::getUrl('builder', ['record' => $taskId]);
                $body .= "\n".__('seo-content-ai::filament.settings_workflows.open_workflow_builder').': '.$url;
            } catch (\Throwable) {
                // ignore
            }
        }

        return $body;
    }

    public static function canAccess(): bool
    {
        if (! SeoStackAvailability::enabled()) {
            return false;
        }

        $user = auth()->user();
        if ($user instanceof \App\Models\User
            && in_array((string) $user->role, [\App\Models\User::ROLE_OWNER, \App\Models\User::ROLE_ADMIN], true)
        ) {
            return true;
        }

        return SeoAccessControl::canAccessManagerFeatures();
    }
}
