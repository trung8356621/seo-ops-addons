<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Pages;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordReviewStatus;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordReviewReason;
use Omnichannel\Addons\SearchFoundation\Services\CtaKeywordBlacklistDebugService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordReviewReasonService;
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

    /** @var list<array<string, mixed>> */
    public array $reviewReasonRows = [];

    public function mount(SeoKeywordSettingsService $settings, KeywordReviewReasonService $reviewReasons): void
    {
        $this->keywordSettingsData = [
            SeoKeywordSettingsService::KEY_CTA_BLACKLIST => $settings->getCtaBlacklist(),
        ];

        $this->form->fill($this->keywordSettingsData);
        $reviewReasons->ensureDefaultReasons();
        $this->loadReviewReasonRows();
    }

    public function loadReviewReasonRows(): void
    {
        $this->reviewReasonRows = app(KeywordReviewReasonService::class)
            ->allReasonsForWorkspace()
            ->map(static fn (KeywordReviewReason $reason): array => [
                'id' => (int) $reason->id,
                'name' => (string) $reason->name,
                'default_severity' => (string) $reason->default_severity,
                'description' => (string) ($reason->description ?? ''),
                'is_active' => (bool) $reason->is_active,
                'sort_order' => (int) $reason->sort_order,
                'is_used' => $reason->isUsed(),
            ])
            ->values()
            ->all();
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

    public function saveReviewReasons(KeywordReviewReasonService $reasonService): void
    {
        abort_unless(SeoAccessControl::canManageKeywordReviewReasons(), 403);

        foreach ($this->reviewReasonRows as $row) {
            $reasonId = (int) ($row['id'] ?? 0);
            if ($reasonId <= 0) {
                $reasonService->createReason([
                    'name' => (string) ($row['name'] ?? ''),
                    'default_severity' => (string) ($row['default_severity'] ?? KeywordReviewStatus::Warning->value),
                    'description' => $row['description'] ?? null,
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                ]);

                continue;
            }

            $reason = $reasonService->findAccessibleReason($reasonId);
            if (! $reason instanceof KeywordReviewReason) {
                continue;
            }

            $reasonService->updateReason($reason, [
                'name' => (string) ($row['name'] ?? ''),
                'default_severity' => (string) ($row['default_severity'] ?? KeywordReviewStatus::Warning->value),
                'description' => $row['description'] ?? null,
                'is_active' => (bool) ($row['is_active'] ?? true),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ]);
        }

        $this->loadReviewReasonRows();

        Notification::make()
            ->title(__('seo-content-ai::filament.settings_keywords.review_reasons_saved'))
            ->success()
            ->send();
    }

    public function addReviewReasonRow(): void
    {
        abort_unless(SeoAccessControl::canManageKeywordReviewReasons(), 403);

        $this->reviewReasonRows[] = [
            'id' => 0,
            'name' => '',
            'default_severity' => KeywordReviewStatus::Warning->value,
            'description' => '',
            'is_active' => true,
            'sort_order' => count($this->reviewReasonRows),
            'is_used' => false,
        ];
    }

    public function removeReviewReasonRow(int $index): void
    {
        abort_unless(SeoAccessControl::canManageKeywordReviewReasons(), 403);

        $row = $this->reviewReasonRows[$index] ?? null;
        if (! is_array($row)) {
            return;
        }

        $reasonId = (int) ($row['id'] ?? 0);
        if ($reasonId > 0) {
            $reason = app(KeywordReviewReasonService::class)->findAccessibleReason($reasonId);
            if ($reason instanceof KeywordReviewReason) {
                if ($reason->isUsed()) {
                    app(KeywordReviewReasonService::class)->updateReason($reason, ['is_active' => false]);
                } else {
                    app(KeywordReviewReasonService::class)->deleteReason($reason);
                }
            }
        }

        unset($this->reviewReasonRows[$index]);
        $this->reviewReasonRows = array_values($this->reviewReasonRows);
        $this->loadReviewReasonRows();
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

    public function canManageReviewReasons(): bool
    {
        return SeoAccessControl::canManageKeywordReviewReasons();
    }
}
