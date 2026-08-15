<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Pages;

use Omnichannel\Addons\SearchFoundation\Services\CtaKeywordBlacklistDebugService;
use Omnichannel\Addons\Seo\Services\SeoKeywordSettingsService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SeoSettingsKeywords extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'settings/keywords';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Keywords';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-keywords';

    /** @var array<string, mixed> */
    public array $keywordSettingsData = [];

    /** @var array<string, mixed>|null */
    public ?array $debugReport = null;

    public function mount(SeoKeywordSettingsService $settings): void
    {
        $this->keywordSettingsData = [
            SeoKeywordSettingsService::KEY_CTA_BLACKLIST => $settings->getCtaBlacklist(),
        ];

        $this->form->fill($this->keywordSettingsData);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_keywords.cta_blacklist'))
                    ->description(__('seo-content-ai::filament.settings_keywords.cta_blacklist_description'))
                    ->schema([
                        Forms\Components\TagsInput::make(SeoKeywordSettingsService::KEY_CTA_BLACKLIST)
                            ->label(__('seo-content-ai::filament.settings_keywords.cta_blacklist_label'))
                            ->placeholder(__('seo-content-ai::filament.settings_keywords.cta_blacklist_placeholder'))
                            ->columnSpanFull()
                            ->helperText(__('seo-content-ai::filament.settings_keywords.cta_blacklist_hint')),
                    ]),
            ])
            ->statePath('keywordSettingsData');
    }

    public function saveKeywordSettings(SeoKeywordSettingsService $settings): void
    {
        $data = $this->form->getState();

        $settings->saveSettings([
            SeoKeywordSettingsService::KEY_CTA_BLACKLIST => $settings->normalizeBlacklist(
                $data[SeoKeywordSettingsService::KEY_CTA_BLACKLIST] ?? [],
            ),
        ]);

        Notification::make()
            ->title(__('seo-content-ai::filament.settings_keywords.saved'))
            ->success()
            ->send();
    }

    public function debugCtaBlacklist(
        SeoKeywordSettingsService $settings,
        CtaKeywordBlacklistDebugService $debugService,
    ): void {
        $data = $this->form->getState();
        $blacklist = $settings->normalizeBlacklist(
            $data[SeoKeywordSettingsService::KEY_CTA_BLACKLIST] ?? [],
        );

        if ($blacklist === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.settings_keywords.debug_empty_blacklist'))
                ->warning()
                ->send();

            return;
        }

        $siteId = SeoAccessControl::globalSiteId();
        $this->debugReport = $debugService->scan($siteId, $blacklist);

        $matchedKeywords = count($this->debugReport['matched_keywords'] ?? []);
        $domainLabel = $siteId !== null
            ? (string) (Site::query()->whereKey($siteId)->value('domain') ?? $siteId)
            : __('seo-content-ai::filament.settings_keywords.debug_all_domains');

        Notification::make()
            ->title(__('seo-content-ai::filament.settings_keywords.debug_completed'))
            ->body(__('seo-content-ai::filament.settings_keywords.debug_completed_body', [
                'keywords' => $matchedKeywords,
                'domain' => $domainLabel,
            ]))
            ->success()
            ->send();
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }
}
