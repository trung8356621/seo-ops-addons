<x-filament-panels::page>
    @php
        $status = $this->statusPayload();
        $metrics = $this->metrics();
        $db = is_array($status['database'] ?? null) ? $status['database'] : [];
    @endphp

    <div class="space-y-6 max-w-4xl">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __('social-automation::filament.dashboard.intro') }}
        </p>

        <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <dt class="text-xs uppercase text-gray-500">Service</dt>
                <dd class="mt-1 font-medium">{{ !empty($status['active']) ? 'Active' : 'Inactive' }}</dd>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <dt class="text-xs uppercase text-gray-500">Agents online</dt>
                <dd class="mt-1 font-medium">{{ (int) ($metrics['agents_online'] ?? 0) }} / {{ (int) ($metrics['agents_total'] ?? 0) }}</dd>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <dt class="text-xs uppercase text-gray-500">Sessions ready</dt>
                <dd class="mt-1 font-medium">{{ (int) ($metrics['sessions_ready'] ?? 0) }}</dd>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <dt class="text-xs uppercase text-gray-500">Jobs running</dt>
                <dd class="mt-1 font-medium">{{ (int) ($metrics['jobs_running'] ?? 0) }}</dd>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <dt class="text-xs uppercase text-gray-500">Packages</dt>
                <dd class="mt-1 font-medium">{{ (int) ($metrics['packages'] ?? 0) }}</dd>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <dt class="text-xs uppercase text-gray-500">Tasks active</dt>
                <dd class="mt-1 font-medium">{{ (int) ($metrics['tasks_active'] ?? 0) }}</dd>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <dt class="text-xs uppercase text-gray-500">Jobs completed</dt>
                <dd class="mt-1 font-medium">{{ (int) ($metrics['jobs_completed'] ?? 0) }}</dd>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <dt class="text-xs uppercase text-gray-500">DB plane</dt>
                <dd class="mt-1 font-medium text-xs">
                    {{ $db['connection'] ?? 'omi_social_automation' }}
                    @if (!empty($db['reachable'])) · reachable @elseif (!empty($db['configured'])) · configured @else · not configured @endif
                </dd>
            </div>
        </dl>

        @if (!empty($metrics['empty']))
            <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-600">
                {{ __('social-automation::filament.dashboard.empty_schema') }}
            </div>
        @endif
    </div>
</x-filament-panels::page>
