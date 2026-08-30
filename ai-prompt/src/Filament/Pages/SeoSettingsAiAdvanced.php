<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Filament\Pages;

use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\Seo\Support\GoogleAiModelRegistry;
use Omnichannel\Addons\Media\Support\ImageModelInputLengthPolicy;
use Omnichannel\Addons\Seo\Support\RenderingPreference;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Content\Support\TypographyValidationLevel;
use Omnichannel\Addons\AiPrompt\Services\AiModelFamilyCatalog;
use Omnichannel\Addons\AiPrompt\Services\ImageFamilySelectionAdapter;
use Omnichannel\Addons\AiPrompt\Support\AiUsageMode;
use Omnichannel\Addons\AiPrompt\Support\VisionValidationModelRouter;
use App\Help\HelpUi;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * AI Advanced — model routing / validation. Không lẫn với Editor Media (Prompt|Workflow).
 */
class SeoSettingsAiAdvanced extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'settings/ai-advanced';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'AI Advanced';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-ai-advanced';

    /** @var array<string, mixed> */
    public array $settingsData = [];

    public function mount(SeoCreateArticleSettingsService $settings): void
    {
        $this->settingsData = $settings->getSettings();
        $adapter = new ImageFamilySelectionAdapter();
        $preference = RenderingPreference::fromMixed($this->settingsData[SeoCreateArticleSettingsService::KEY_RENDERING_PREFERENCE] ?? null);
        $generalSlugs = $this->slugList($this->settingsData[SeoCreateArticleSettingsService::KEY_IMAGE_MODEL_PRIORITY] ?? []);
        $typoSlugs = $this->slugList($this->settingsData[SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_MODEL_PRIORITY] ?? []);
        $this->settingsData['general_image_families'] = $adapter->familiesFromSlugs($generalSlugs) ?: [AiModelFamilyCatalog::AUTOMATIC];
        $this->settingsData['typography_image_families'] = $adapter->familiesFromSlugs($typoSlugs) ?: [AiModelFamilyCatalog::AUTOMATIC];
        $this->settingsData['image_usage_mode'] = match ($preference) {
            RenderingPreference::CostFirst => AiUsageMode::Economy->value,
            RenderingPreference::QualityFirst => AiUsageMode::QualityFirst->value,
            default => null,
        };
        $this->form->fill($this->settingsData);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_ai_advanced.model_priority_section'))
                    ->schema([
                        Forms\Components\CheckboxList::make('general_image_families')
                            ->label(__('seo-content-ai::filament.settings_ai_advanced.general_image_priority'))
                            ->options(fn (): array => $this->imageFamilyOptions())
                            ->columns(2),
                        Forms\Components\CheckboxList::make('typography_image_families')
                            ->label(__('seo-content-ai::filament.settings_ai_advanced.typography_image_priority'))
                            ->options(fn (): array => $this->imageFamilyOptions())
                            ->columns(2),
                        Forms\Components\Radio::make('image_usage_mode')
                            ->label(__('seo-content-ai::filament.ai_model_ux.mode'))
                            ->options(AiUsageMode::selectOptions())
                            ->inline()
                            ->nullable(),
                        Forms\Components\Repeater::make(SeoCreateArticleSettingsService::KEY_VIDEO_MODEL_PRIORITY)
                            ->label(__('seo-content-ai::filament.settings_ai_advanced.video_model_priority'))
                            ->schema([
                                Forms\Components\Select::make('slug')
                                    ->label(__('seo-content-ai::filament.ai_model_ux.model'))
                                    ->options(fn (): array => $this->videoFamilyLabels())
                                    ->searchable()
                                    ->required()
                                    ->native(false),
                            ])
                            ->addActionLabel(__('seo-content-ai::filament.settings_ai_advanced.add_video_model'))
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(function (array $state): ?string {
                                $slug = trim((string) ($state['slug'] ?? ''));

                                return $slug !== ''
                                    ? ($this->videoFamilyLabels()[$slug] ?? $slug)
                                    : __('seo-content-ai::filament.settings_workflows.new_image_model');
                            }),
                    ]),

                Forms\Components\Section::make(__('seo-content-ai::filament.ai_model_ux.technical_details'))
                    ->collapsed()
                    ->schema([
                        Forms\Components\Placeholder::make('image_model_priority_rules')
                            ->label(__('seo-content-ai::filament.settings_workflows.image_model_priority_rules'))
                            ->content(function (): HtmlString {
                                $rows = ImageModelInputLengthPolicy::routingTableRows();
                                $html = '<div class="overflow-x-auto"><table class="w-full text-sm border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">'
                                    .'<thead class="bg-gray-50 dark:bg-gray-800 text-left">'
                                    .'<tr>'
                                    .'<th class="px-3 py-2 font-medium">'.e(__('seo-content-ai::filament.settings_workflows.image_model_table_range')).'</th>'
                                    .'<th class="px-3 py-2 font-medium">'.e(__('seo-content-ai::filament.settings_workflows.image_model_table_tier')).'</th>'
                                    .'<th class="px-3 py-2 font-medium">'.e(__('seo-content-ai::filament.settings_workflows.image_model_table_reason')).'</th>'
                                    .'</tr></thead><tbody>';

                                foreach ($rows as $row) {
                                    $tierLabel = ImageModelInputLengthPolicy::tierHint((string) $row['tier']);
                                    $html .= '<tr class="border-t border-gray-200 dark:border-gray-700">'
                                        .'<td class="px-3 py-2 whitespace-nowrap">'.e((string) $row['range']).'</td>'
                                        .'<td class="px-3 py-2">'.e($tierLabel).'</td>'
                                        .'<td class="px-3 py-2 text-gray-600 dark:text-gray-300">'.e((string) $row['reason']).'</td>'
                                        .'</tr>';
                                }

                                $html .= '</tbody></table></div>';

                                return new HtmlString($html);
                            }),
                    ]),

                Forms\Components\Section::make(__('seo-content-ai::filament.settings_ai_advanced.validation_section'))
                    ->headerActions([HelpUi::fieldHintAction('settings.ai.typography_validation')])
                    ->schema([
                        Forms\Components\Toggle::make(SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_VALIDATION_ENABLED)
                            ->label(__('seo-content-ai::filament.settings_workflows.typography_validation_enabled'))
                            ->default(true),
                        Forms\Components\Radio::make(SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_VALIDATION_LEVEL)
                            ->label(__('seo-content-ai::filament.settings_workflows.typography_validation_level'))
                            ->options(fn (): array => TypographyValidationLevel::selectOptions())
                            ->default(TypographyValidationLevel::Balanced->value)
                            ->inline(),
                        Forms\Components\Select::make(SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_VALIDATION_MODEL)
                            ->label(__('seo-content-ai::filament.settings_ai_advanced.validation_model'))
                            ->hintAction(HelpUi::fieldHintAction('settings.ai.typography_validation', null, 'model'))
                            ->options(fn (): array => $this->visionValidationModelOptions())
                            ->searchable()
                            ->native(false)
                            ->placeholder(__('seo-content-ai::filament.settings_ai_advanced.validation_model_default')),
                        Forms\Components\TextInput::make(SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_MAX_CANDIDATES)
                            ->label(__('seo-content-ai::filament.settings_workflows.typography_max_candidates'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(3)
                            ->hintAction(HelpUi::fieldHintAction('settings.ai.typography_validation', null, 'max_candidates')),
                        Forms\Components\TextInput::make(SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_PASS_THRESHOLD)
                            ->label(__('seo-content-ai::filament.settings_workflows.typography_pass_threshold'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1)
                            ->step(0.01)
                            ->hintAction(HelpUi::fieldHintAction('settings.ai.typography_validation', null, 'pass_threshold')),
                        Forms\Components\Toggle::make(SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_ALLOW_GENERAL_IMAGE_FALLBACK)
                            ->label(__('seo-content-ai::filament.settings_ai_advanced.typography_allow_general_fallback'))
                            ->hintAction(HelpUi::fieldHintAction('settings.ai.typography_validation', null, 'fallback')),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ])
            ->statePath('settingsData');
    }

    /**
     * @return array<string, string>
     */
    private function imageFamilyOptions(): array
    {
        $catalog = new AiModelFamilyCatalog();
        $options = [];
        foreach ($catalog->all() as $family) {
            if ($family->modality === 'image') {
                $options[$family->familyKey] = $family->displayName;
            }
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function videoFamilyLabels(): array
    {
        $presenter = new \Omnichannel\Addons\AiPrompt\Support\AiModelLabelPresenter();
        $options = [];
        foreach (GoogleAiModelRegistry::videoSelectOptions() as $slug => $label) {
            $options[$slug] = $presenter->normal((string) $slug, (string) $label);
        }

        return $options;
    }

    /**
     * @param  list<array{slug?: string}>|list<string>  $stored
     * @return list<string>
     */
    private function slugList(mixed $stored): array
    {
        $out = [];
        if (! is_array($stored)) {
            return $out;
        }
        foreach ($stored as $item) {
            $slug = is_string($item) ? $item : (string) (is_array($item) ? ($item['slug'] ?? '') : '');
            if (trim($slug) !== '') {
                $out[] = trim($slug);
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function visionValidationModelOptions(): array
    {
        $router = new VisionValidationModelRouter();
        $options = [];
        foreach ($router->modelsToTry() as $slug) {
            $options[$slug] = GoogleAiModelRegistry::textSelectOptions()[$slug] ?? $slug;
        }

        return $options;
    }

    public function saveSettings(SeoCreateArticleSettingsService $settings): void
    {
        $data = $this->form->getState();
        $adapter = new ImageFamilySelectionAdapter();
        $mode = AiUsageMode::tryFromMixed($data['image_usage_mode'] ?? null);
        $existingGeneral = $settings->getImageModelPriority();
        $existingTypo = $settings->getTypographyModelPriority();
        $generalFamilies = array_map(static fn (mixed $key): string => (string) $key, (array) ($data['general_image_families'] ?? []));
        $typoFamilies = array_map(static fn (mixed $key): string => (string) $key, (array) ($data['typography_image_families'] ?? []));

        $generalSlugs = $mode instanceof AiUsageMode
            ? $adapter->expandByMode($generalFamilies, $mode)
            : $adapter->expandPreservingOrder($generalFamilies, $existingGeneral);
        $typoSlugs = $mode instanceof AiUsageMode
            ? $adapter->expandByMode($typoFamilies, $mode)
            : $adapter->expandPreservingOrder($typoFamilies, $existingTypo);

        $patch = [
            SeoCreateArticleSettingsService::KEY_IMAGE_MODEL_PRIORITY => $generalSlugs,
            SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_MODEL_PRIORITY => $typoSlugs,
            SeoCreateArticleSettingsService::KEY_VIDEO_MODEL_PRIORITY => $data[SeoCreateArticleSettingsService::KEY_VIDEO_MODEL_PRIORITY] ?? null,
            SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_VALIDATION_ENABLED => $data[SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_VALIDATION_ENABLED] ?? true,
            SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_VALIDATION_LEVEL => $data[SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_VALIDATION_LEVEL] ?? null,
            SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_VALIDATION_MODEL => $data[SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_VALIDATION_MODEL] ?? null,
            SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_MAX_CANDIDATES => $data[SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_MAX_CANDIDATES] ?? null,
            SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_PASS_THRESHOLD => $data[SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_PASS_THRESHOLD] ?? null,
            SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_ALLOW_GENERAL_IMAGE_FALLBACK => $data[SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_ALLOW_GENERAL_IMAGE_FALLBACK] ?? false,
        ];
        if ($mode instanceof AiUsageMode) {
            $patch[SeoCreateArticleSettingsService::KEY_RENDERING_PREFERENCE] = $mode === AiUsageMode::Economy
                ? RenderingPreference::CostFirst->value
                : RenderingPreference::QualityFirst->value;
        }

        $settings->saveSettings($patch);

        Notification::make()
            ->title(__('seo-content-ai::filament.settings_ai_advanced.saved'))
            ->success()
            ->send();
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }
}
