<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Pages;

use Omnichannel\Addons\Seo\Services\SeoScoringSettingsService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoScoringRuleMessageResolver;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SeoSettingsScoring extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'settings/scoring';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'SEO scoring rules';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-scoring';

    /** @var array<string, mixed> */
    public array $scoringSettingsData = [];

    public function mount(SeoScoringSettingsService $settings): void
    {
        $this->scoringSettingsData = [
            'rules' => array_map(
                static function (array $rule): array {
                    return [
                        'key' => $rule['key'],
                        'label' => SeoScoringRuleMessageResolver::messageForKey($rule['key']),
                        'enabled' => (bool) ($rule['enabled'] ?? true),
                        'deduction' => (int) ($rule['deduction'] ?? 0),
                        'default_deduction' => SeoScoringRulesRegistry::defaultDeductionFor($rule['key']),
                    ];
                },
                $settings->effectiveRules(),
            ),
        ];

        $this->form->fill($this->scoringSettingsData);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_scoring.section'))
                    ->description(__('seo-content-ai::filament.settings_scoring.section_description'))
                    ->schema([
                        Forms\Components\Repeater::make('rules')
                            ->label('')
                            ->schema([
                                Forms\Components\Hidden::make('key'),
                                Forms\Components\Hidden::make('default_deduction'),
                                Forms\Components\Placeholder::make('label')
                                    ->label(__('seo-content-ai::filament.settings_scoring.rule_label'))
                                    ->content(fn (Forms\Get $get): string => (string) ($get('label') ?? '')),
                                Forms\Components\Toggle::make('enabled')
                                    ->label(__('seo-content-ai::filament.settings_scoring.enabled'))
                                    ->inline(false)
                                    ->default(true),
                                Forms\Components\TextInput::make('deduction')
                                    ->label(__('seo-content-ai::filament.settings_scoring.deduction'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(SeoScoringRulesRegistry::BASE_SCORE)
                                    ->required()
                                    ->suffix('đ'),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->itemLabel(fn (array $state): ?string => filled($state['label'] ?? null)
                                ? (string) $state['label']
                                : (string) ($state['key'] ?? null)),
                    ]),
            ])
            ->statePath('scoringSettingsData');
    }

    public function saveScoringSettings(SeoScoringSettingsService $settings): void
    {
        $data = $this->form->getState();
        $rules = [];

        foreach (is_array($data['rules'] ?? null) ? $data['rules'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $rules[$key] = [
                'enabled' => (bool) ($row['enabled'] ?? true),
                'deduction' => (int) ($row['deduction'] ?? 0),
            ];
        }

        $settings->saveRuleOverrides($rules);

        Notification::make()
            ->title(__('seo-content-ai::filament.settings_scoring.saved'))
            ->body(__('seo-content-ai::filament.settings_scoring.saved_hint'))
            ->success()
            ->send();
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }
}
