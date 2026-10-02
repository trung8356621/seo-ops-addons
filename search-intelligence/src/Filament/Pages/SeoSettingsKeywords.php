<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Pages;

use App\Core\Addon\AddonEnablement;
use App\Help\HelpUi;
use App\Models\Site;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Omnichannel\Addons\SearchFoundation\Contracts\IndustryMatchRuleProvider;
use Omnichannel\Addons\SearchFoundation\Services\CtaKeywordBlacklistDebugService;
use Omnichannel\Addons\SearchFoundation\Services\MatchRules\MatchRuleMatcher;
use Omnichannel\Addons\Seo\Services\SeoKeywordSettingsService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

class SeoSettingsKeywords extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'settings/keywords';

    protected static bool $shouldRegisterNavigation = false;

    public function getTitle(): string
    {
        return 'Match & Research';
    }

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-keywords';

    /** @var array<string, mixed> */
    public array $keywordSettingsData = [];

    /** @var array<string, mixed>|null */
    public ?array $debugReport = null;

    public string $debugPhrase = '';

    /** @var array<string, list<array<string, mixed>>> */
    public array $industryRules = [];

    /** @var array<string, mixed>|null */
    public ?array $industryProvenance = null;

    public ?string $industryContextKey = null;

    /** @var array<string, array<string, list<string>>>|null */
    public ?array $matcherReport = null;

    public function mount(SeoKeywordSettingsService $settings, IndustryMatchRuleProvider $industryProvider): void
    {
        $this->keywordSettingsData = $settings->getSettings();

        $this->form->fill($this->keywordSettingsData);
        $siteId = SeoAccessControl::globalSiteId();
        $site = $siteId !== null ? Site::query()->find($siteId) : null;
        $this->industryContextKey = trim((string) $site?->getMeta('seo_industry_context_key')) ?: null;
        $this->industryRules = $industryProvider->rulesForKey($this->industryContextKey);
        $this->industryProvenance = $industryProvider->provenanceForKey($this->industryContextKey);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Global Rules')
                    ->description('Editable language/system matching rules. CTA / Noise retains its existing behavior.')
                    ->headerActions([HelpUi::fieldHintAction('settings.keywords.cta_blacklist')])
                    ->schema(fn (SeoKeywordSettingsService $settings): array => collect($settings->definitions())
                        ->filter(fn (array $definition): bool => $definition['editable'])
                        ->map(fn (array $definition): Forms\Components\TagsInput => Forms\Components\TagsInput::make($definition['key'])
                            ->label($definition['label'])
                            ->helperText($definition['description'])
                            ->columnSpanFull())
                        ->values()->all()),
            ])
            ->statePath('keywordSettingsData');
    }

    public function saveKeywordSettings(SeoKeywordSettingsService $settings): void
    {
        $data = $this->form->getState();

        $payload = [];
        foreach ($settings->definitions() as $key => $definition) {
            if ($definition['editable']) {
                $payload[$key] = $settings->normalizeRuleValues($data[$key] ?? []);
            }
        }
        $settings->saveSettings($payload);

        Notification::make()
            ->title(__('seo-content-ai::filament.settings_keywords.saved'))
            ->success()
            ->send();
    }

    public function debugMatcher(SeoKeywordSettingsService $settings, MatchRuleMatcher $matcher): void
    {
        $phrase = trim($this->debugPhrase);
        $global = [];
        foreach ($settings->definitions() as $key => $definition) {
            $entries = array_map(static fn (string $value): array => [
                'canonical' => $value,
                'aliases' => [],
                'match_mode' => $definition['match_mode'],
            ], $settings->getSettings()[$key] ?? []);
            $matches = $matcher->matchingEntries($entries, $phrase);
            if ($matches !== []) {
                $global[$key] = $matches;
            }
        }
        $industry = [];
        foreach ($this->industryRules as $key => $entries) {
            $matchable = array_values(array_filter($entries, static fn (mixed $entry): bool => is_array($entry) && trim((string) ($entry['canonical'] ?? '')) !== ''));
            $matches = $matcher->matchingEntries($matchable, $phrase);
            if ($matches !== []) {
                $industry[$key] = $matches;
            }
        }
        $this->matcherReport = ['global_matches' => $global, 'industry_matches' => $industry];
    }

    public static function getNavigationLabel(): string
    {
        return 'Match & Research';
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
        if (! AddonEnablement::seoStackEnabled()) {
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
