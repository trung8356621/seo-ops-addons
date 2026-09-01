<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Forms;

use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpContactDiscovery;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpDraft;
use App\Models\Site;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormInputAction;
use Filament\Forms\Get;
use Filament\Forms\Set;

final class DomainTechnicalSeoForm
{
    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    public static function schema(): array
    {
        $maxWords = SiteDomainPromptContextService::MAX_SHORT_DESCRIPTION_WORDS;

        return [
            Forms\Components\Group::make()
                ->statePath('promptContext')
                ->columnSpanFull()
                ->schema([
                    Forms\Components\Grid::make(2)
                        ->schema([
                            Forms\Components\Group::make()
                                ->schema([
                                    self::domainSettingsSection(),
                                    self::contactSection(),
                                ]),
                            Forms\Components\Group::make()
                                ->schema([
                                    self::companyShortIdentitySection(),
                                    self::shortDescriptionSection($maxWords),
                                    self::linkListSection(),
                                ]),
                        ]),
                ]),
        ];
    }

    private static function domainSettingsSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make(__('seo-content-ai::filament.domain.domain_settings'))
            ->description(__('seo-content-ai::filament.domain.domain_settings_description'))
            ->schema([
                // Domain default tone removed — article generation uses Content Project Item
                // Automatic variety / tone_override. Legacy meta tone is ignored at runtime.
                Forms\Components\Textarea::make('cta_intro')
                    ->label(__('seo-content-ai::filament.domain.cta_instructions'))
                    ->helperText(__('seo-content-ai::filament.domain.cta_instructions_hint'))
                    ->rows(4)
                    ->maxLength(4000)
                    ->columnSpanFull(),
            ])
            ->collapsible();
    }

    private static function companyShortIdentitySection(): Forms\Components\Section
    {
        $max = SiteMcpDraft::COMPANY_SHORT_IDENTITY_MAX;

        return Forms\Components\Section::make(__('seo-content-ai::filament.domain.company_short_identity'))
            ->description(__('seo-content-ai::filament.domain.company_short_identity_hint', ['max' => $max]))
            ->schema([
                Forms\Components\TextInput::make('company_short_identity')
                    ->label(__('seo-content-ai::filament.domain.company_short_identity_label'))
                    ->maxLength($max)
                    ->live(debounce: 400)
                    ->helperText(function (Get $get) use ($max): string {
                        $len = mb_strlen(trim((string) $get('company_short_identity')));

                        return __('seo-content-ai::filament.domain.company_short_identity_chars', [
                            'count' => $len,
                            'max' => $max,
                        ]);
                    })
                    ->hintAction(self::wordpressSyncSuffixAction(
                        'sync_company_short_identity_wp',
                        'syncCompanyShortIdentityFromWordPress',
                        'syncingCompanyShortIdentityFromWp',
                    )),
            ])
            ->collapsible();
    }

    private static function shortDescriptionSection(int $maxWords): Forms\Components\Section
    {
        return Forms\Components\Section::make(__('seo-content-ai::filament.domain.short_description'))
            ->description(__('seo-content-ai::filament.domain.short_description_hint', ['max' => $maxWords]))
            ->schema([
                Forms\Components\Textarea::make('short_description')
                    ->label(__('seo-content-ai::filament.domain.short_description_label'))
                    ->rows(6)
                    ->maxLength(8000)
                    ->live(debounce: 400)
                    ->helperText(function (Get $get) use ($maxWords): string {
                        $count = app(SiteDomainPromptContextService::class)
                            ->countWords((string) $get('short_description'));

                        return __('seo-content-ai::filament.domain.short_description_words', [
                            'count' => $count,
                            'max' => $maxWords,
                        ]);
                    })
                    ->hintAction(self::wordpressSyncSuffixAction(
                        'sync_short_description_wp',
                        'syncShortDescriptionFromWordPress',
                        'syncingShortDescriptionFromWp',
                    )),
            ])
            ->collapsible();
    }

    private static function contactSection(): Forms\Components\Section
    {
        $socialOptions = [];
        foreach (SiteMcpContactDiscovery::SOCIAL_NETWORKS as $network) {
            $socialOptions[$network] = ucfirst($network === 'x' ? 'X / Twitter' : $network);
        }

        return Forms\Components\Section::make(__('seo-content-ai::filament.domain.contacts_section'))
            ->description(__('seo-content-ai::filament.domain.contacts_section_hint'))
            ->schema([
                Forms\Components\Repeater::make('phones')
                    ->label(__('seo-content-ai::filament.domain.phones'))
                    ->schema([
                        Forms\Components\TextInput::make('value')
                            ->label(__('seo-content-ai::filament.domain.phone_value'))
                            ->tel()
                            ->required()
                            ->maxLength(50),
                    ])
                    ->defaultItems(0)
                    ->addActionLabel(__('seo-content-ai::filament.domain.add_phone'))
                    ->reorderable()
                    ->collapsible()
                    ->itemLabel(fn (array $state): ?string => filled($state['value'] ?? null)
                        ? (string) $state['value']
                        : __('seo-content-ai::filament.domain.phone_new')),
                Forms\Components\Repeater::make('emails')
                    ->label(__('seo-content-ai::filament.domain.emails'))
                    ->schema([
                        Forms\Components\TextInput::make('value')
                            ->label(__('seo-content-ai::filament.domain.email_value'))
                            ->email()
                            ->required()
                            ->maxLength(255),
                    ])
                    ->defaultItems(0)
                    ->addActionLabel(__('seo-content-ai::filament.domain.add_email'))
                    ->reorderable()
                    ->collapsible()
                    ->itemLabel(fn (array $state): ?string => filled($state['value'] ?? null)
                        ? (string) $state['value']
                        : __('seo-content-ai::filament.domain.email_new')),
                Forms\Components\Repeater::make('socials')
                    ->label(__('seo-content-ai::filament.domain.socials'))
                    ->schema([
                        Forms\Components\Select::make('network')
                            ->label(__('seo-content-ai::filament.domain.social_network'))
                            ->options($socialOptions)
                            ->required()
                            ->native(false)
                            ->columnSpan(4),
                        Forms\Components\TextInput::make('url')
                            ->label(__('seo-content-ai::filament.domain.social_url'))
                            ->url()
                            ->required()
                            ->maxLength(2000)
                            ->columnSpan(6),
                    ])
                    ->columns(10)
                    ->defaultItems(0)
                    ->addActionLabel(__('seo-content-ai::filament.domain.add_social'))
                    ->reorderable()
                    ->collapsible()
                    ->itemLabel(fn (array $state): ?string => filled($state['network'] ?? null)
                        ? (string) $state['network']
                        : __('seo-content-ai::filament.domain.social_new')),
                Forms\Components\TextInput::make('address')
                    ->label(__('seo-content-ai::filament.domain.address'))
                    ->maxLength(500)
                    ->columnSpanFull(),
            ])
            ->collapsible();
    }

    private static function wordpressSyncSuffixAction(
        string $actionName,
        string $livewireMethod,
        string $syncingProperty,
    ): FormInputAction {
        return FormInputAction::make($actionName)
            ->label(fn (FormInputAction $action): string => self::wordpressSyncActionLabel($action, $syncingProperty))
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (FormInputAction $action): bool => self::isWordPressDomain($action))
            ->disabled(fn (FormInputAction $action): bool => self::isWordPressSyncBusy($action, $syncingProperty))
            ->action(function (FormInputAction $action, Set $set) use ($livewireMethod): void {
                $livewire = $action->getLivewire();
                if (is_object($livewire) && method_exists($livewire, $livewireMethod)) {
                    $livewire->{$livewireMethod}($set);
                }
            });
    }

    private static function wordpressSyncActionLabel(FormInputAction $action, string $syncingProperty): string
    {
        if (self::isWordPressSyncBusy($action, $syncingProperty)) {
            return 'Đang đồng bộ...';
        }

        return 'Đồng bộ WP';
    }

    private static function isWordPressSyncBusy(FormInputAction $action, string $syncingProperty): bool
    {
        $livewire = $action->getLivewire();

        return is_object($livewire)
            && property_exists($livewire, $syncingProperty)
            && (bool) $livewire->{$syncingProperty};
    }

    private static function isWordPressDomain(FormInputAction $action): bool
    {
        $livewire = $action->getLivewire();
        if (! is_object($livewire) || ! method_exists($livewire, 'getRecord')) {
            return false;
        }

        $site = $livewire->getRecord();
        if (! $site instanceof Site) {
            return false;
        }

        $site->loadMissing('metas');

        return trim((string) ($site->getMeta('seo_platform') ?? '')) === 'wordpress';
    }

    private static function linkListSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make(__('seo-content-ai::filament.domain.link_list'))
            ->description('Tóm tắt catalog đồng bộ từ WordPress + liên kết thủ công. Không tải toàn bộ URL WordPress vào form.')
            ->schema([
                Forms\Components\Placeholder::make('site_link_catalog_summary')
                    ->label('Catalog website')
                    ->content(function (Forms\Components\Placeholder $component): string {
                        $livewire = $component->getLivewire();
                        $site = method_exists($livewire, 'getRecord') ? $livewire->getRecord() : null;
                        if ($site === null) {
                            return 'Chưa có site.';
                        }

                        return app(\Omnichannel\Addons\Seo\Services\EffectiveDomainLinkResolver::class)
                            ->catalogSummary($site)['label'];
                    }),
                Forms\Components\Repeater::make('links')
                    ->label('Liên kết thủ công (prompt / override)')
                    ->helperText('Chỉ liên kết thủ công. Catalog WordPress không hiển thị ở đây.')
                    ->schema([
                        Forms\Components\TextInput::make('keyword')
                            ->label(__('seo-content-ai::filament.domain.link_keyword'))
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(4),
                        Forms\Components\TextInput::make('link')
                            ->label(__('seo-content-ai::filament.domain.link_url'))
                            ->placeholder('https://...')
                            ->required()
                            ->maxLength(2000)
                            ->columnSpan(6),
                    ])
                    ->columns(10)
                    ->defaultItems(0)
                    ->addActionLabel(__('seo-content-ai::filament.domain.link_add'))
                    ->reorderable()
                    ->collapsible()
                    ->itemLabel(fn (array $state): ?string => filled($state['keyword'] ?? null)
                        ? (string) $state['keyword']
                        : __('seo-content-ai::filament.domain.link_new')),
            ])
            ->collapsible();
    }
}
