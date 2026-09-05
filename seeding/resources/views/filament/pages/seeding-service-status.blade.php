<x-filament-panels::page>
    @php
        $status = $this->statusPayload();
        $db = is_array($status['database'] ?? null) ? $status['database'] : [];
    @endphp

    <div class="space-y-4 max-w-2xl">
        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Service</dt>
                <dd class="mt-1 font-medium">{{ $status['service'] ?? 'seeding' }}</dd>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Status</dt>
                <dd class="mt-1 font-medium">{{ !empty($status['active']) ? 'Active' : 'Inactive' }}</dd>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Version</dt>
                <dd class="mt-1 font-medium">{{ $status['version'] ?? '—' }}</dd>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Persistence</dt>
                <dd class="mt-1 font-medium">{{ strtoupper($this->persistenceMode()) }} (browser localStorage)</dd>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700 sm:col-span-2">
                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Database plane</dt>
                <dd class="mt-1 font-medium">
                    {{ $db['connection'] ?? 'omi_seeding' }}
                    / {{ $db['database'] ?? 'omi_seeding' }}
                    —
                    @if (!empty($db['reachable']))
                        reachable
                    @elseif (!empty($db['configured']))
                        configured, not reachable
                    @else
                        not configured
                    @endif
                </dd>
                <p class="mt-2 text-xs text-gray-500">
                    Infrastructure-ready only. No business schema in this phase.
                </p>
            </div>
        </dl>

        <a href="{{ \Omnichannel\Addons\Seeding\Filament\Pages\SeedingTopicsPage::getUrl() }}" class="text-sm font-medium text-primary-600 hover:underline">
            ← {{ __('seeding::filament.topics.nav') }}
        </a>
    </div>
</x-filament-panels::page>
