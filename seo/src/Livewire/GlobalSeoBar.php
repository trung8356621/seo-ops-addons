<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Livewire;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

class GlobalSeoBar extends Component
{
    public ?int $globalSiteId = null;

    public ?int $globalContentProjectId = null;

    public string $simulatedRole = '';

    public function mount(): void
    {
        if ($this->shouldForceAllDomainsScope()) {
            SeoAccessControl::setGlobalSiteId(null);
            $this->globalSiteId = null;
        } else {
            $this->restoreGlobalSiteFromStorage();
        }

        $actualRole = SeoAccessControl::actualRole();
        $current = SeoAccessControl::normalizeRole((string) session('seo_simulated_role', $actualRole));
        $allowed = SeoAccessControl::allowedSimulationTargets($actualRole);

        $this->simulatedRole = in_array($current, $allowed, true) ? $current : $actualRole;

        session(['seo_simulated_role' => $this->simulatedRole]);
        $this->syncGlobalContentProjectSelection();
        $this->bootstrapDatabaseForCurrentSite();
    }

    public function updatedGlobalSiteId($value): void
    {
        $siteId = filled($value) ? (int) $value : null;
        if ($siteId !== null && $siteId <= 0) {
            $siteId = null;
        }

        if ($siteId !== null && ! $this->resolveSitesQuery()->whereKey($siteId)->exists()) {
            $this->globalSiteId = SeoAccessControl::globalSiteId();
            $this->syncGlobalContentProjectSelection();

            return;
        }

        $this->globalSiteId = $siteId;
        SeoAccessControl::setGlobalSiteId($siteId);
        $this->bootstrapDatabaseForSite($siteId);
        $this->syncGlobalContentProjectSelection();
        session()->save();

        $this->dispatch('seoGlobalSiteChanged', siteId: $siteId);

        $this->redirect($this->resolveReturnUrl(), navigate: false);
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

        return view('seo::livewire.global-seo-bar', [
            'sites' => $this->resolveSitesQuery()->get(),
            'roleOptions' => $roleOptions,
            'showDomainPicker' => SeoAccessControl::shouldShowGlobalSitePicker(),
            'showContentProjectPicker' => $showContentProjectPicker && SeoAccessControl::shouldShowGlobalSitePicker(),
            'contentProjectOptions' => $contentProjectOptions,
            'isAdminViewer' => SeoAccessControl::isSeoPanelReadOnly(),
        ]);
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

    private function restoreGlobalSiteFromStorage(): void
    {
        $hasStoredSelection = SeoAccessControl::hasGlobalSiteSelection();
        $this->globalSiteId = SeoAccessControl::globalSiteId();

        if (
            $this->globalSiteId !== null
            && ! $this->resolveSitesQuery()->whereKey($this->globalSiteId)->exists()
        ) {
            SeoAccessControl::clearGlobalSiteSelection();
            $this->globalSiteId = null;
            $hasStoredSelection = false;
        }

        if ($this->globalSiteId === null && ! $hasStoredSelection) {
            $this->globalSiteId = $this->resolveSitesQuery()->value('id');
            if ($this->globalSiteId !== null) {
                SeoAccessControl::setGlobalSiteId($this->globalSiteId);
            }
        }
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
        $siteId = $this->globalSiteId ?? SeoAccessControl::globalSiteId();
        $this->bootstrapDatabaseForSite($siteId);
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
