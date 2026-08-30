<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Pages;

use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource;
use Omnichannel\Addons\Seo\Services\SeoDomainCtaGlobalSettingsService;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use App\Help\HelpUi;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;

class DomainGlobalCtaSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'domains/settings';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Global CTA settings';

    protected static string $view = 'seo-content-ai::filament.resources.domain-resource.pages.global-cta-settings';

    /** @var array<string, mixed> */
    public array $settingsData = [];

    public function mount(SeoDomainCtaGlobalSettingsService $settings): void
    {
        $this->settingsData = [
            SeoDomainCtaGlobalSettingsService::KEY_GLOBAL_CTA => $this->repeaterStateForFill(
                $settings->getGlobalCta(),
            ),
        ];
        $this->form->fill($this->settingsData);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('seo-content-ai::filament.domain.global_cta_shared_section'))
                    ->headerActions([HelpUi::fieldHintAction('domain.global_cta')])
                    ->schema([
                        Forms\Components\Repeater::make(SeoDomainCtaGlobalSettingsService::KEY_GLOBAL_CTA)
                            ->label('')
                            ->schema([
                                Forms\Components\Select::make('type')
                                    ->label(__('seo-content-ai::filament.domain.cta_type'))
                                    ->options(SiteDomainPromptContextService::globalCtaFormTypeOptions())
                                    ->required()
                                    ->native(false)
                                    ->columnSpan(4),
                                Forms\Components\TextInput::make('value')
                                    ->label(__('seo-content-ai::filament.domain.cta_value'))
                                    ->required()
                                    ->maxLength(500)
                                    ->columnSpan(6),
                            ])
                            ->columns(10)
                            ->defaultItems(0)
                            ->addActionLabel(__('seo-content-ai::filament.domain.cta_add'))
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => filled($state['type'] ?? null)
                                ? (string) $state['type']
                                : __('seo-content-ai::filament.domain.cta_new')),
                    ]),
            ])
            ->statePath('settingsData');
    }

    public function saveGlobalCtaSettings(SeoDomainCtaGlobalSettingsService $settings): void
    {
        $data = $this->form->getState();

        $settings->saveGlobalCta($this->repeaterItemsFromState(
            $data[SeoDomainCtaGlobalSettingsService::KEY_GLOBAL_CTA] ?? [],
        ));

        Notification::make()
            ->title(__('seo-content-ai::filament.domain.global_cta_saved'))
            ->success()
            ->send();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, array<string, mixed>>
     */
    private function repeaterStateForFill(array $items): array
    {
        $state = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $state[(string) Str::uuid()] = $item;
        }

        return $state;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function repeaterItemsFromState(mixed $state): array
    {
        if (! is_array($state)) {
            return [];
        }

        $items = [];

        foreach ($state as $item) {
            if (! is_array($item)) {
                continue;
            }

            $items[] = $item;
        }

        return $items;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back_to_domains')
                ->label(__('seo-content-ai::filament.domain.back_to_domains'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(DomainResource::getUrl('index')),
        ];
    }

    public static function canAccess(): bool
    {
        return DomainResource::canViewAny();
    }
}
