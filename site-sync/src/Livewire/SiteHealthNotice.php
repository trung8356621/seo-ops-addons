<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Livewire;

use App\Core\Sites\SiteAccess;
use App\Models\Site;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Omnichannel\Addons\SiteSync\Services\SiteHealth\GlobalSiteHealthReadModel;
use Omnichannel\Addons\SiteSync\Services\SiteHealth\SiteHealthMonitor;

final class SiteHealthNotice extends Component
{
    /** @var array{severity: string, signature: string, incidents: list<array<string, mixed>>}|null */
    public ?array $alert = null;

    public ?int $selectedIncidentId = null;

    public function mount(GlobalSiteHealthReadModel $readModel): void
    {
        $user = Auth::user();
        $this->alert = $user instanceof User ? $readModel->forUser($user) : null;
    }

    public function retrySiteHealth(int $siteId, SiteAccess $access, SiteHealthMonitor $monitor, GlobalSiteHealthReadModel $readModel): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $access->canAccessSite($siteId, $user), 403);
        $site = Site::query()->findOrFail($siteId);
        $monitor->check($site);
        $this->alert = $readModel->forUser($user);

        $this->dispatch(
            'site-health-retried',
            siteId: $siteId,
            incident: collect($this->alert['incidents'] ?? [])->firstWhere('site_id', $siteId),
        );
    }

    public function render(): View
    {
        return view('site-sync::livewire.site-health-notice');
    }
}
