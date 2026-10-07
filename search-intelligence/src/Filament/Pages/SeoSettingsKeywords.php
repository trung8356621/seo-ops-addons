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
use Omnichannel\Addons\SearchFoundation\Contracts\IndustryGroup\IndustryGroupProvider;
use Omnichannel\Addons\SearchFoundation\Contracts\IndustryMatchRuleProvider;
use Omnichannel\Addons\SearchFoundation\Contracts\MatchResearch\MatchResearchRegistry;
use Omnichannel\Addons\SearchFoundation\Enums\IndustryGroupType;
use Omnichannel\Addons\SearchFoundation\Enums\MatchResearchOrigin;
use Omnichannel\Addons\SearchFoundation\Services\MatchResearch\CustomMatchResearchStore;
use Omnichannel\Addons\SearchFoundation\Services\MatchResearch\MatchResearchLocalizationImporter;
use Omnichannel\Addons\SearchFoundation\Services\MatchResearch\MatchResearchLocalizationPromptBuilder;
use Omnichannel\Addons\SearchFoundation\Services\MatchResearch\MatchResearchLocaleOverlayStore;
use Omnichannel\Addons\SearchFoundation\Services\MatchRules\MatchRuleMatcher;
use Omnichannel\Addons\SearchFoundation\Services\CtaKeywordBlacklistDebugService;
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

    public string $activeOriginTab = 'system';

    /** @var list<array<string, mixed>> */
    public array $registrySystem = [];

    /** @var list<array<string, mixed>> */
    public array $registryIndustry = [];

    /** @var list<array<string, mixed>> */
    public array $registryCustom = [];

    /**
     * Industry Groups keyed by group_type (products, services, …).
     *
     * @var array<string, list<array<string, mixed>>>
     */
    public array $industryGroupsByType = [];

    /** @var list<array<string, mixed>> */
    public array $industryNonGroupResources = [];

    public string $localizationResourceKey = '';

    public string $localizationTargetLocale = 'en';

    public string $localizationPrompt = '';

    public string $localizationImportJson = '';

    /** @var array{name:string,description:string,source_locale:string,positive_examples:string,negative_examples:string,enabled:bool} */
    public array $customForm = [
        'name' => '',
        'description' => '',
        'source_locale' => 'vi',
        'positive_examples' => '',
        'negative_examples' => '',
        'enabled' => true,
    ];

    public ?string $editingCustomKey = null;

    public function mount(
        SeoKeywordSettingsService $settings,
        IndustryMatchRuleProvider $industryProvider,
        MatchResearchRegistry $registry,
        IndustryGroupProvider $industryGroups,
    ): void {
        $this->keywordSettingsData = $settings->getSettings();
        $this->form->fill($this->keywordSettingsData);

        $siteId = SeoAccessControl::globalSiteId();
        $site = $siteId !== null ? Site::query()->find($siteId) : null;
        $this->industryContextKey = trim((string) $site?->getMeta('seo_industry_context_key')) ?: null;
        $this->industryRules = $industryProvider->rulesForKey($this->industryContextKey);
        $this->industryProvenance = $industryProvider->provenanceForKey($this->industryContextKey);
        $this->refreshRegistry($registry, $industryGroups, $siteId);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('System Rules')
                    ->description('Immutable system matching rule sets. Identity keys cannot be deleted. CTA / Noise retains existing behavior.')
                    ->headerActions([HelpUi::fieldHintAction('settings.keywords.cta_blacklist')])
                    ->schema(fn (SeoKeywordSettingsService $settings): array => collect($settings->definitions())
                        ->filter(fn (array $definition): bool => $definition['editable'])
                        ->map(fn (array $definition): Forms\Components\TagsInput => Forms\Components\TagsInput::make($definition['key'])
                            ->label($definition['label'].' (system.'.$definition['key'].')')
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

    public function setOriginTab(string $tab): void
    {
        if (in_array($tab, ['system', 'industry', 'custom'], true)) {
            $this->activeOriginTab = $tab;
        }
    }

    public function saveCustomConcept(
        CustomMatchResearchStore $store,
        MatchResearchRegistry $registry,
        IndustryGroupProvider $industryGroups,
    ): void {
        $siteId = SeoAccessControl::globalSiteId();
        if ($siteId === null || $siteId <= 0) {
            Notification::make()->title('Select a site before creating custom concepts.')->warning()->send();

            return;
        }

        $positive = $this->linesToList($this->customForm['positive_examples'] ?? '');
        $negative = $this->linesToList($this->customForm['negative_examples'] ?? '');

        try {
            if ($this->editingCustomKey !== null) {
                $store->update($this->editingCustomKey, $siteId, [
                    'name' => (string) $this->customForm['name'],
                    'description' => (string) ($this->customForm['description'] ?? ''),
                    'positive_examples' => $positive,
                    'negative_examples' => $negative,
                    'enabled' => (bool) ($this->customForm['enabled'] ?? true),
                ]);
            } else {
                $store->create($siteId, [
                    'name' => (string) $this->customForm['name'],
                    'description' => (string) ($this->customForm['description'] ?? ''),
                    'source_locale' => (string) $this->customForm['source_locale'],
                    'positive_examples' => $positive,
                    'negative_examples' => $negative,
                    'enabled' => (bool) ($this->customForm['enabled'] ?? true),
                ]);
            }
        } catch (\Throwable $e) {
            Notification::make()->title('Custom concept failed')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->resetCustomForm();
        $this->refreshRegistry($registry, $industryGroups, $siteId);
        Notification::make()->title('Custom concept saved')->success()->send();
    }

    public function editCustomConcept(string $key): void
    {
        foreach ($this->registryCustom as $row) {
            if (($row['key'] ?? '') !== $key) {
                continue;
            }
            $payload = (array) ($row['payload'] ?? []);
            $this->editingCustomKey = $key;
            $this->customForm = [
                'name' => (string) ($payload['name'] ?? $row['label'] ?? ''),
                'description' => (string) ($payload['description'] ?? $row['description'] ?? ''),
                'source_locale' => (string) ($row['source_locale'] ?? 'vi'),
                'positive_examples' => implode("\n", (array) ($payload['positive_examples'] ?? [])),
                'negative_examples' => implode("\n", (array) ($payload['negative_examples'] ?? [])),
                'enabled' => (bool) ($row['enabled'] ?? true),
            ];
            $this->activeOriginTab = 'custom';

            return;
        }
    }

    public function deleteCustomConcept(
        string $key,
        CustomMatchResearchStore $store,
        MatchResearchRegistry $registry,
        IndustryGroupProvider $industryGroups,
    ): void {
        $siteId = SeoAccessControl::globalSiteId();
        if ($siteId === null) {
            return;
        }
        try {
            $store->delete($key, $siteId);
        } catch (\Throwable $e) {
            Notification::make()->title('Delete failed')->body($e->getMessage())->danger()->send();

            return;
        }
        if ($this->editingCustomKey === $key) {
            $this->resetCustomForm();
        }
        $this->refreshRegistry($registry, $industryGroups, $siteId);
        Notification::make()->title('Custom concept deleted')->success()->send();
    }

    public function exportLocalizationPrompt(
        MatchResearchRegistry $registry,
        MatchResearchLocalizationPromptBuilder $builder,
    ): void {
        $siteId = SeoAccessControl::globalSiteId();
        $resource = $registry->find($this->localizationResourceKey, $siteId, $this->industryContextKey);
        if ($resource === null) {
            Notification::make()->title('Resource not found')->warning()->send();

            return;
        }
        try {
            $this->localizationPrompt = $builder->build($resource, $this->localizationTargetLocale);
            Notification::make()->title('Localization prompt ready — copy to an external agent')->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Export failed')->body($e->getMessage())->danger()->send();
        }
    }

    public function importLocalizationResult(
        MatchResearchRegistry $registry,
        MatchResearchLocalizationImporter $importer,
        MatchResearchLocaleOverlayStore $overlays,
        IndustryGroupProvider $industryGroups,
    ): void {
        $siteId = SeoAccessControl::globalSiteId();
        $resource = $registry->find($this->localizationResourceKey, $siteId, $this->industryContextKey);
        if ($resource === null) {
            Notification::make()->title('Resource not found')->warning()->send();

            return;
        }
        $result = $importer->validateAndNormalize($this->localizationImportJson, $resource, $this->localizationTargetLocale);
        if (! ($result['ok'] ?? false)) {
            Notification::make()
                ->title('Localization import rejected')
                ->body(implode("\n", $result['errors'] ?? ['Unknown validation error']))
                ->danger()
                ->send();

            return;
        }

        $overlaySiteId = $resource->origin === MatchResearchOrigin::Custom ? $siteId : 0;
        $overlays->put($resource->key, $this->localizationTargetLocale, $result['payload'], $overlaySiteId);
        $this->refreshRegistry($registry, $industryGroups, $siteId);
        $this->localizationImportJson = '';
        Notification::make()->title('Localized result imported')->success()->send();
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

    private function refreshRegistry(
        MatchResearchRegistry $registry,
        IndustryGroupProvider $industryGroups,
        ?int $siteId,
    ): void {
        $this->registrySystem = array_map(
            static fn ($r) => $r->toArray(),
            $registry->list($siteId, MatchResearchOrigin::System, $this->industryContextKey),
        );
        $industryResources = $registry->list($siteId, MatchResearchOrigin::Industry, $this->industryContextKey);
        $this->registryIndustry = array_map(static fn ($r) => $r->toArray(), $industryResources);
        $this->registryCustom = array_map(
            static fn ($r) => $r->toArray(),
            $registry->list($siteId, MatchResearchOrigin::Custom, $this->industryContextKey),
        );

        $byType = [];
        foreach (IndustryGroupType::cases() as $type) {
            $byType[$type->value] = [];
        }
        foreach ($industryGroups->list($siteId, $this->industryContextKey) as $group) {
            $byType[$group->groupType->value][] = $group->toArray();
        }
        $this->industryGroupsByType = $byType;

        $this->industryNonGroupResources = [];
        foreach ($industryResources as $resource) {
            $group = (string) ($resource->provenance['group'] ?? '');
            if (IndustryGroupType::tryFromGroup($group) !== null) {
                continue;
            }
            $this->industryNonGroupResources[] = $resource->toArray();
        }
    }

    private function resetCustomForm(): void
    {
        $this->editingCustomKey = null;
        $this->customForm = [
            'name' => '',
            'description' => '',
            'source_locale' => 'vi',
            'positive_examples' => '',
            'negative_examples' => '',
            'enabled' => true,
        ];
    }

    /** @return list<string> */
    private function linesToList(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn (string $v): bool => $v !== ''));
    }
}
