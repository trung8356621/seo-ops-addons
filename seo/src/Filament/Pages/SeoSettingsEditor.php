<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Pages;

use Omnichannel\Addons\Content\Services\ArticleEditorHistoryService;
use Omnichannel\Addons\Seo\Filament\Forms\Components\SettingsTagsInput;
use Omnichannel\Addons\Seo\Services\SeoOverviewSettingsService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoStackAvailability;
use Omnichannel\Addons\Seo\Support\SettingsTagNormalizer;
use App\Help\HelpUi;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SeoSettingsEditor extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'settings/editor';

    protected static bool $shouldRegisterNavigation = false;

    public function getTitle(): string
    {
        return __('Article editor');
    }


    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-editor';

    /** @var array<string, mixed> */
    public array $editorSettingsData = [];

    public function mount(ArticleEditorHistoryService $editorSettings, SeoOverviewSettingsService $overviewSettings): void
    {
        $overviewRaw = $overviewSettings->getSettings();

        $this->editorSettingsData = array_merge(
            $editorSettings->getSettings(),
            [
                'wiki_trust_domains' => $editorSettings->getWikiTrustDomains(),
                SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS => $overviewRaw[SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS],
            ],
        );

        $this->form->fill($this->editorSettingsData);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_editor.section'))
                    ->headerActions([HelpUi::fieldHintAction('settings.editor.history_autosave')])
                    ->schema([
                        Forms\Components\TextInput::make('history_step')
                            ->label(__('seo-content-ai::filament.settings_editor.history_step'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->required()
                            ->default(ArticleEditorHistoryService::DEFAULT_HISTORY_STEP),
                        Forms\Components\TextInput::make('autosave_interval_seconds')
                            ->label(__('seo-content-ai::filament.settings_editor.autosave_interval'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(30)
                            ->required()
                            ->default(ArticleEditorHistoryService::DEFAULT_AUTOSAVE_INTERVAL_SECONDS)
                            ->suffix(__('seo-content-ai::filament.settings_editor.seconds_suffix')),
                    ])
                    ->columns(2),
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_editor.wiki_trust_section'))
                    ->headerActions([HelpUi::fieldHintAction('settings.editor.wiki_trust')])
                    ->schema([
                        SettingsTagsInput::make('wiki_trust_domains')
                            ->label(__('seo-content-ai::filament.settings_editor.wiki_trust_domains'))
                            ->placeholder(__('seo-content-ai::filament.settings_editor.trusted_domains_placeholder'))
                            ->helperText(__('seo-content-ai::filament.settings_editor.wiki_trust_domains_hint'))
                            ->normalizer(SettingsTagsInput::TRUSTED_DOMAIN)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_overview.faq_catch'))
                    ->headerActions([HelpUi::fieldHintAction('settings.editor.faq_catch')])
                    ->schema([
                        SettingsTagsInput::make(SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS)
                            ->label(__('seo-content-ai::filament.settings_overview.faq_keywords_label'))
                            ->placeholder(__('seo-content-ai::filament.settings_editor.faq_keywords_placeholder'))
                            ->normalizer(SettingsTagsInput::PHRASE)
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('editorSettingsData');
    }

    public function saveEditorSettings(
        ArticleEditorHistoryService $editorSettings,
        SeoOverviewSettingsService $overviewSettings,
    ): void {
        $data = $this->form->getState();

        $trustDomains = $data['wiki_trust_domains'] ?? [];
        if (! is_array($trustDomains)) {
            $trustDomains = [];
        }

        foreach ($trustDomains as $domain) {
            if (SettingsTagNormalizer::normalizeTrustedDomainTag((string) $domain) === null) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'wiki_trust_domains' => __('seo-content-ai::filament.settings_editor.invalid_trusted_domain', ['domain' => (string) $domain]),
                ]);
            }
        }

        $editorSettings->saveSettings([
            'history_step' => $data['history_step'] ?? ArticleEditorHistoryService::DEFAULT_HISTORY_STEP,
            'autosave_interval_seconds' => $data['autosave_interval_seconds'] ?? ArticleEditorHistoryService::DEFAULT_AUTOSAVE_INTERVAL_SECONDS,
            'wiki_trust_domains' => $trustDomains,
        ]);

        $faqKeywords = $data[SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS] ?? [];
        $overviewSettings->saveSettings([
            SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS => is_array($faqKeywords) ? $faqKeywords : [],
        ]);

        $this->editorSettingsData = array_merge(
            $editorSettings->getSettings(),
            [
                'wiki_trust_domains' => $editorSettings->getWikiTrustDomains(),
                SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS => $overviewSettings->getFaqCatchKeywords(),
            ],
        );
        $this->form->fill($this->editorSettingsData);

        Notification::make()
            ->title(__('seo-content-ai::filament.settings_editor.saved'))
            ->success()
            ->send();
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
