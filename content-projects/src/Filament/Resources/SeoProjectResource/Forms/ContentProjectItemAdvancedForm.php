<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Forms;

use Filament\Forms;
use Filament\Forms\Get;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ItemGenerationMode;
use Throwable;

/**
 * "Advanced" block of a Content Project item row — the per-item generation policy.
 *
 * Collapsed by default so the item card stays short; every control is an override
 * on top of the domain / prompt defaults, so leaving it untouched changes nothing.
 */
final class ContentProjectItemAdvancedForm
{
    /**
     * Override fields counted in the section heading badge.
     *
     * @var list<string>
     */
    private const OVERRIDE_FIELDS = [
        'tone_override',
        'generation_mode_override',
        'model_override_id',
    ];

    /**
     * @return list<Forms\Components\Component>
     */
    public static function schema(): array
    {
        return [
            Forms\Components\Section::make()
                ->heading(fn (Get $get): string => self::heading($get))
                ->icon('heroicon-m-adjustments-horizontal')
                ->collapsible()
                ->collapsed()
                ->compact()
                ->columnSpanFull()
                ->schema([
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\Select::make('tone_override')
                            ->label(__('seo-content-ai::filament.projects.item_tone'))
                            ->placeholder(__('seo-content-ai::filament.projects.item_tone_automatic'))
                            ->options(fn (Get $get): array => app(SeoPromptSettingsService::class)
                                ->toneOfVoiceSelectOptions(self::stringState($get, 'tone_override')))
                            ->native(false)
                            ->searchable()
                            ->live(),

                        Forms\Components\Select::make('generation_mode_override')
                            ->label(__('seo-content-ai::filament.projects.item_generation_mode'))
                            ->placeholder(__('seo-content-ai::filament.projects.item_generation_mode_default'))
                            ->options([
                                ItemGenerationMode::FastEconomy->value => __('seo-content-ai::filament.projects.item_generation_mode_fast'),
                                ItemGenerationMode::BestQuality->value => __('seo-content-ai::filament.projects.item_generation_mode_best'),
                            ])
                            ->native(false)
                            ->live(),

                        Forms\Components\Select::make('model_override_id')
                            ->label(__('seo-content-ai::filament.projects.item_model_override'))
                            ->placeholder(__('seo-content-ai::filament.projects.item_model_routing_default'))
                            ->options(fn (): array => self::modelOptions())
                            ->native(false)
                            ->searchable()
                            ->live(),

                        Forms\Components\Toggle::make('model_fallback_enabled')
                            ->label(__('seo-content-ai::filament.projects.item_model_fallback'))
                            ->default(true)
                            ->inline(false)
                            ->visible(fn (Get $get): bool => self::intState($get, 'model_override_id') > 0),
                    ]),
                ]),
        ];
    }

    private static function heading(Get $get): string
    {
        $count = 0;

        foreach (self::OVERRIDE_FIELDS as $field) {
            if (self::stringState($get, $field) !== '') {
                $count++;
            }
        }

        if ($count === 0) {
            return (string) __('seo-content-ai::filament.projects.item_advanced');
        }

        return (string) __('seo-content-ai::filament.projects.item_advanced_overrides', ['count' => $count]);
    }

    /**
     * Active catalog models, labelled by their provider model id.
     *
     * @return array<int, string>
     */
    private static function modelOptions(): array
    {
        try {
            return SeoAiModel::query()
                ->where('status', SeoAiModel::STATUS_ACTIVE)
                ->orderByDesc('priority')
                ->orderBy('id')
                ->get()
                ->mapWithKeys(function (SeoAiModel $model): array {
                    $raw = trim((string) $model->raw_model_name);
                    $display = trim((string) $model->display_name);
                    $label = $raw !== '' ? $raw : $display;

                    return [(int) $model->id => $label !== '' ? $label : ('#'.(int) $model->id)];
                })
                ->all();
        } catch (Throwable) {
            // Missing AI catalog must not break the project editor.
            return [];
        }
    }

    private static function stringState(Get $get, string $field): string
    {
        $value = $get($field);

        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }

    private static function intState(Get $get, string $field): int
    {
        $value = self::stringState($get, $field);

        return is_numeric($value) ? (int) $value : 0;
    }
}
