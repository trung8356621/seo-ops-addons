<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalAlertHookService;

/**
 * Shared high-visibility UI for active Operational Alert Hook items.
 * Query logic lives in OperationalAlertHookService — not in this view component.
 */
final class OperationalAlertHook extends Component
{
    /** @var list<array<string, mixed>> */
    public array $alerts = [];

    public function mount(OperationalAlertHookService $hook): void
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            $this->alerts = [];

            return;
        }

        $this->alerts = array_map(
            static fn ($alert): array => $alert->toArray(),
            $hook->forUser($user),
        );
    }

    public function render(): View
    {
        return view('seo::livewire.operational-alert-hook');
    }
}
