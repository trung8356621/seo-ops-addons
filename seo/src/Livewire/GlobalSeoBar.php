<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Livewire;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Support\DomainContext;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
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

    public function mount(): void
    {
        SeoAccessControl::forgetLegacyGlobalSitePersistence();

        if ($this->shouldForceAllDomainsScope()) {
            $this->applyContext(DomainContext::all());
        } else {
            $this->applyContext(app(DomainContextResolver::class)->current());
        }

        $actualRole = SeoAccessControl::actualRole();
        $current = SeoAccessControl::normalizeRole((string) session('seo_simulated_role', $actualRole));
        $allowed = SeoAccessControl::allowedSimulationTargets($actualRole);

        $this->simulatedRole = in_array($current, $allowed, true) ? $current : $actualRole;

        session(['seo_simulated_role' => $this->simulatedRole]);
        $this->syncGlobalContentProjectSelection();
        $this->bootstrapDatabaseForCurrentSite();
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
            'showContentProjectPicker' => $showContentProjectPicker && SeoAccessControl::shouldShowGlobalSitePicker(),
            'contentProjectOptions' => $contentProjectOptions,
            'isAdminViewer' => SeoAccessControl::isSeoPanelReadOnly(),
        ]);
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

    private function shouldForceAllDomainsScope(): bool
    {
        return request()->routeIs('filament.seo.resources.keywords.*');
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
