<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Livewire;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Support\DomainContext;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoPanelRoutes;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use App\Models\Site;
use App\Support\RuntimeLogger;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

class GlobalSeoBar extends Component
{
    public ?int $globalSiteId = null;

    public string $domainKey = DomainContext::ALL_KEY;

    public ?int $globalContentProjectId = null;

    public string $simulatedRole = '';

    public bool $writingSplitEnabled = false;

    /** @var string normal|free_only — article generation mode (user-facing FreeOnly). */
    public string $articleGenerationMode = 'normal';

    public function mount(): void
    {
        SeoAccessControl::forgetLegacyGlobalSitePersistence();

        $resolver = app(DomainContextResolver::class);
        if ($this->shouldPreferFirstAccessibleDomain() || SeoPanelRoutes::isProjectPlannerSeoAudit()) {
            $this->applyContext($this->resolvePreferredKeywordIntelligenceContext($resolver));
        } else {
            $this->applyContext($resolver->current());
        }

        $actualRole = SeoAccessControl::actualRole();
        $current = SeoAccessControl::normalizeRole((string) session('seo_simulated_role', $actualRole));
        $allowed = SeoAccessControl::allowedSimulationTargets($actualRole);

        $this->simulatedRole = in_array($current, $allowed, true) ? $current : $actualRole;

        session(['seo_simulated_role' => $this->simulatedRole]);
        $this->syncGlobalContentProjectSelection();
        $this->bootstrapDatabaseForCurrentSite();
        // Legacy preference sync kept for BC data only — UI no longer exposes the toggle.
        $this->syncWritingSplitPreference();
        $this->syncArticleGenerationModePreference();
    }

    /**
     * @deprecated Preference no longer controls execution shape (route_cost_auto).
     */
    public function updatedWritingSplitEnabled($value): void
    {
        $enabled = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        $this->writingSplitEnabled = $enabled;

        $userId = (int) (auth()->id() ?? 0);
        if ($userId <= 0) {
            return;
        }

        \Omnichannel\Addons\Content\Support\WritingSplitPreference::persistForUserId($userId, $enabled);
    }

    public function updatedArticleGenerationMode($value): void
    {
        $policy = \Omnichannel\Addons\AiPrompt\Support\AiCostPolicy::tryFromMixed($value);
        $this->articleGenerationMode = $policy->generationModeValue();

        $userId = (int) (auth()->id() ?? 0);
        if ($userId <= 0) {
            return;
        }

        \Omnichannel\Addons\AiPrompt\Support\ArticleGenerationModePreference::persistForUserId($userId, $policy);
    }

    public function updatedDomainKey($value): void
    {
        $context = app(DomainContextResolver::class)->resolveKey(is_string($value) ? $value : null);

        if (
            ! $context->isAllDomains
            && ($context->siteId === null || ! $this->resolveSitesQuery()->whereKey($context->siteId)->exists())
        ) {
            $context = DomainContext::all();
        }

        if ($context->isAllDomains && SeoPanelRoutes::isProjectPlannerSeoAudit()) {
            $context = $this->resolvePreferredKeywordIntelligenceContext(app(DomainContextResolver::class));
        }

        $previousKey = $this->domainKey;
        $this->applyContext($context);
        $this->syncGlobalContentProjectSelection();
        $this->dispatchDomainContextChanged($context, $previousKey);
    }

    public function updatedGlobalContentProjectId($value): void
    {
        $projectId = filled($value) ? (int) $value : null;
        if ($projectId !== null && $projectId <= 0) {
            $projectId = null;
        }

        SeoAccessControl::setGlobalContentProjectId($projectId);
        $this->syncGlobalContentProjectSelection();
        session()->save();
    }

    public function updatedSimulatedRole($value): void
    {
        $actualRole = SeoAccessControl::actualRole();
        $role = SeoAccessControl::normalizeRole((string) $value);
        $allowed = SeoAccessControl::allowedSimulationTargets($actualRole);

        if (! in_array($role, $allowed, true)) {
            $role = $actualRole;
        }

        $this->simulatedRole = $role;
        session(['seo_simulated_role' => $role]);

        if ($role !== SeoAccessControl::ROLE_PLANNER) {
            SeoAccessControl::clearGlobalContentProjectSelection();
            $this->globalContentProjectId = null;
        } else {
            $this->syncGlobalContentProjectSelection();
        }

        session()->save();

        $this->redirect($this->resolveReturnUrl(), navigate: false);
    }

    public function render()
    {
        $actualRole = SeoAccessControl::actualRole();
        $allowedRoles = SeoAccessControl::allowedSimulationTargets($actualRole);

        $roleLabels = [
            SeoAccessControl::ROLE_CONTENT_MANAGER => 'Quản lý nội dung',
            SeoAccessControl::ROLE_PLANNER => 'Kế hoạch viên',
            SeoAccessControl::ROLE_MANAGER => 'Quản lý (Manager)',
        ];

        $roleOptions = [];
        foreach ($allowedRoles as $role) {
            $roleOptions[$role] = $roleLabels[$role] ?? $role;
        }

        $showContentProjectPicker = SeoAccessControl::canUseGlobalContentProjectPicker();
        $contentProjectOptions = $showContentProjectPicker
            ? ArticleResource::contentProjectOptions($this->globalSiteId)
            : [];

        if (
            $this->globalContentProjectId !== null
            && ! array_key_exists($this->globalContentProjectId, $contentProjectOptions)
        ) {
            SeoAccessControl::clearGlobalContentProjectSelection();
            $this->globalContentProjectId = null;
        }

        $sites = $this->resolveSitesQuery()->get();
        $resolver = app(DomainContextResolver::class);

        return view('seo::livewire.global-seo-bar', [
            'sites' => $sites,
            'domainKeys' => $sites
                ->mapWithKeys(fn (Site $site): array => [(int) $site->getKey() => $resolver->domainKeyForSite($site)])
                ->all(),
            'roleOptions' => $roleOptions,
            'showDomainPicker' => SeoAccessControl::shouldShowGlobalSitePicker(),
            'hideAllDomainsOption' => SeoAccessControl::shouldRequireConcreteGlobalDomain(),
            'showContentProjectPicker' => $showContentProjectPicker && SeoAccessControl::shouldShowGlobalSitePicker(),
            'contentProjectOptions' => $contentProjectOptions,
            'writingSplitEnabled' => $this->writingSplitEnabled,
            'articleGenerationMode' => $this->articleGenerationMode,
        ]);
    }

    private function syncWritingSplitPreference(): void
    {
        $userId = (int) (auth()->id() ?? 0);
        $this->writingSplitEnabled = \Omnichannel\Addons\Content\Support\WritingSplitPreference::enabledForUserId(
            $userId > 0 ? $userId : null,
        );
    }

    private function syncArticleGenerationModePreference(): void
    {
        $userId = (int) (auth()->id() ?? 0);
        $policy = \Omnichannel\Addons\AiPrompt\Support\ArticleGenerationModePreference::forUserId(
            $userId > 0 ? $userId : null,
        );
        $this->articleGenerationMode = $policy->generationModeValue();
    }

    private function applyContext(DomainContext $context): void
    {
        app(DomainContextResolver::class)->bind($context);
        $this->domainKey = $context->domainKey;
        $this->globalSiteId = $context->siteId;
        $this->bootstrapDatabaseForSite($context->siteId);
    }

    private function dispatchDomainContextChanged(DomainContext $context, string $previousKey): void
    {
        if (config('app.debug')) {
            RuntimeLogger::debug('domain_context_changed', [
                'from' => DomainContext::normalizeKey($previousKey),
                'to' => $context->domainKey,
                'page' => request()->path(),
            ]);
        }

        $this->dispatch('domain-context-changed', domain: $context->domainKey, siteId: $context->siteId);
        $this->dispatch('seoGlobalSiteChanged', siteId: $context->siteId);
    }

    private function resolveSitesQuery(): Builder
    {
        $query = Site::query()->orderBy('domain');

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $query->where('user_id', SeoAccessControl::accountSiteOwnerId());
        }

        return $query;
    }

    private function shouldPreferFirstAccessibleDomain(): bool
    {
        return SeoPanelRoutes::is(
            'filament.seo.resources.keywords.index',
            'filament.seo.resources.keywords.clusters',
            'filament.seo.resources.keywords.cluster',
            'filament.seo.resources.keywords.focus',
            'filament.seo.resources.keywords.anchor-audit',
            'filament.seo.resources.keywords.workspace-2',
        );
    }

    /**
     * Keyword Intelligence domain-scoped pages: never default to All domains.
     * Explicit URL/header domain wins; otherwise first accessible site.
     */
    private function resolvePreferredKeywordIntelligenceContext(DomainContextResolver $resolver): DomainContext
    {
        if ($resolver->hasExplicitRequestKey()) {
            $explicit = $resolver->current();
            if (! $explicit->isAllDomains && $explicit->siteId !== null && $explicit->siteId > 0) {
                return $explicit;
            }
        }

        $first = $this->resolveSitesQuery()->orderBy('domain')->first();
        if (! $first instanceof Site) {
            return DomainContext::all();
        }

        return DomainContext::forSite((int) $first->getKey(), $resolver->domainKeyForSite($first));
    }

    private function syncGlobalContentProjectSelection(): void
    {
        $this->globalContentProjectId = SeoAccessControl::globalContentProjectId();
    }

    private function resolveReturnUrl(): string
    {
        $fallback = SeoConnectionContext::panelUrl();

        $referer = (string) request()->headers->get('referer', '');
        if ($referer === '') {
            return $fallback;
        }

        $path = (string) parse_url($referer, PHP_URL_PATH);
        if ($path === '' || str_starts_with($path, '/livewire/')) {
            return $fallback;
        }

        return $referer;
    }

    private function bootstrapDatabaseForCurrentSite(): void
    {
        $this->bootstrapDatabaseForSite($this->globalSiteId);
    }

    private function bootstrapDatabaseForSite(?int $siteId): void
    {
        $service = app(SeoDatabaseConnectionService::class);
        $hash = SeoConnectionContext::hash();

        if ($hash !== null) {
            try {
                $service->bootstrapByHash($hash);

                return;
            } catch (\RuntimeException) {
                // fallback below
            }
        }

        if ($siteId !== null && $siteId > 0) {
            $service->bootstrapBySiteId($siteId);

            return;
        }

        $service->bootstrapLegacySharedConnection();
    }
}
