<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Pages;

use Omnichannel\Addons\Content\Services\ArticleEditorHistoryService;
use Omnichannel\Addons\Seo\Services\SeoOverviewSettingsService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
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

    protected static ?string $title = 'Article editor';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-editor';

    /** @var array<string, mixed> */
    public array $editorSettingsData = [];

    public function mount(ArticleEditorHistoryService $editorSettings, SeoOverviewSettingsService $overviewSettings): void
    {
        $overviewRaw = $overviewSettings->getSettings();

        $this->editorSettingsData = array_merge(
            $editorSettings->getSettings(),
            [
                'wiki_trust_domains_text' => $editorSettings->domainsToTextarea(
                    $editorSettings->getWikiTrustDomains(),
                ),
                SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS => $overviewSettings->keywordsToTextarea(
                    $overviewRaw[SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS],
                ),
                SeoOverviewSettingsService::KEY_OUTLINE_SKIP_WORDS => $overviewRaw[SeoOverviewSettingsService::KEY_OUTLINE_SKIP_WORDS],
            ],
        );

        $this->form->fill($this->editorSettingsData);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_editor.section'))
                    ->description(__('seo-content-ai::filament.settings_editor.section_description'))
                    ->schema([
                        Forms\Components\TextInput::make('history_step')
                            ->label(__('seo-content-ai::filament.settings_editor.history_step'))
                            ->helperText(__('seo-content-ai::filament.settings_editor.history_step_hint'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->required()
                            ->default(ArticleEditorHistoryService::DEFAULT_HISTORY_STEP),
                        Forms\Components\TextInput::make('autosave_interval_seconds')
                            ->label(__('seo-content-ai::filament.settings_editor.autosave_interval'))
                            ->helperText(__('seo-content-ai::filament.settings_editor.autosave_interval_hint'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(30)
                            ->required()
                            ->default(ArticleEditorHistoryService::DEFAULT_AUTOSAVE_INTERVAL_SECONDS)
                            ->suffix(__('seo-content-ai::filament.settings_editor.seconds_suffix')),
                    ])
                    ->columns(2),
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_editor.wiki_trust_section'))
                    ->description(__('seo-content-ai::filament.settings_editor.wiki_trust_description'))
                    ->schema([
                        Forms\Components\Textarea::make('wiki_trust_domains_text')
                            ->label(__('seo-content-ai::filament.settings_editor.wiki_trust_domains'))
                            ->helperText(__('seo-content-ai::filament.settings_editor.wiki_trust_domains_hint'))
                            ->rows(8)
                            ->required()
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_overview.faq_catch'))
                    ->description(__('seo-content-ai::filament.settings_overview.faq_catch_description'))
                    ->schema([
                        Forms\Components\Textarea::make(SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS)
                            ->label(__('seo-content-ai::filament.settings_overview.faq_keywords_label'))
                            ->rows(10)
                            ->required()
                            ->columnSpanFull()
                            ->helperText(__('seo-content-ai::filament.settings_overview.faq_keywords_hint')),
                    ]),
                Forms\Components\Section::make('Dò trùng lặp Outline')
                    ->description('Cấu hình bộ lọc khi dò heading trùng lặp giữa các bài viết trong site.')
                    ->schema([
                        Forms\Components\TagsInput::make(SeoOverviewSettingsService::KEY_OUTLINE_SKIP_WORDS)
                            ->label('Các từ/tiêu đề bỏ qua khi dò trùng (Skip List)')
                            ->placeholder('Nhập tiêu đề rồi nhấn Enter')
                            ->columnSpanFull()
                            ->helperText(
                                'Nhập các tiêu đề cần bỏ qua. Hỗ trợ cú pháp SQL: '
                                . "'So sánh%' (bắt đầu bằng), '%Kết luận' (kết thúc bằng), '%FAQ%' (chứa từ). "
                                . "Nếu nhập text thường (ví dụ: 'Giới thiệu'), hệ thống tự động hiểu là '%Giới thiệu%'. "
                                . 'Nhấn Enter để thêm.',
                            ),
                    ]),
            ])
            ->statePath('editorSettingsData');
    }

    public function saveEditorSettings(
        ArticleEditorHistoryService $editorSettings,
        SeoOverviewSettingsService $overviewSettings,
    ): void {
        $data = $this->form->getState();

        $editorSettings->saveSettings([
            'history_step' => $data['history_step'] ?? ArticleEditorHistoryService::DEFAULT_HISTORY_STEP,
            'autosave_interval_seconds' => $data['autosave_interval_seconds'] ?? ArticleEditorHistoryService::DEFAULT_AUTOSAVE_INTERVAL_SECONDS,
            'wiki_trust_domains' => $editorSettings->textareaToDomains(
                (string) ($data['wiki_trust_domains_text'] ?? ''),
            ),
        ]);

        $faqRaw = (string) ($data[SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS] ?? '');
        $overviewSettings->saveSettings([
            SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS => $overviewSettings->keywordsFromTextarea($faqRaw),
            SeoOverviewSettingsService::KEY_OUTLINE_SKIP_WORDS => $data[SeoOverviewSettingsService::KEY_OUTLINE_SKIP_WORDS] ?? [],
        ]);

        Notification::make()
            ->title(__('seo-content-ai::filament.settings_editor.saved'))
            ->success()
            ->send();
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }
}
