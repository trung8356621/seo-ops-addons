<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Livewire\Concerns;

use Livewire\Attributes\On;

/**
 * Refresh this Livewire component when the Global Domain Selector changes.
 *
 * Pagination resets to page 1. Other filters (month, search, status) stay.
 */
trait RefreshesOnDomainContextChanged
{
    #[On('domain-context-changed')]
    #[On('seoGlobalSiteChanged')]
    public function onDomainContextChanged(mixed $domain = null, mixed $siteId = null): void
    {
        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }
}
