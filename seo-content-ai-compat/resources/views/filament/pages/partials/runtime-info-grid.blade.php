@php
    /** @var list<array<string, mixed>> $runtimeRows */
    $runtimeRows = $runtimeRows ?? [];
@endphp

<div class="grid gap-4 md:grid-cols-2">
    @forelse ($runtimeRows as $row)
        @php
            $status = (string) ($row['status'] ?? 'healthy');
            $badge = match ($status) {
                'healthy' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
                'error' => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
                'disabled' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
                'needs_update' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
                default => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
            };
        @endphp
        <div
            wire:key="runtime-row-{{ $row['id'] }}"
            class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-950"
        >
            <div class="mb-3 flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $row['name'] }}</h3>
                    <p class="mt-0.5 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $row['id'] }}</p>
                </div>
                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $badge }}">
                    {{ __('seo-content-ai::filament.extensions.status_'.$status) }}
                </span>
            </div>
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.extensions.col_version') }}</dt>
                    <dd class="mt-0.5 font-medium text-gray-900 dark:text-gray-100">{{ $row['version'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.extensions.col_health') }}</dt>
                    <dd class="mt-0.5 font-medium text-gray-900 dark:text-gray-100">{{ __('seo-content-ai::filament.extensions.status_'.$status) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.extensions.col_driver') }}</dt>
                    <dd class="mt-0.5 font-medium text-gray-900 dark:text-gray-100">{{ $row['driver'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.extensions.col_last_check') }}</dt>
                    <dd class="mt-0.5 font-medium text-gray-900 dark:text-gray-100">{{ $row['last_check'] ?? '—' }}</dd>
                </div>
                <div class="col-span-2">
                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.extensions.col_capability') }}</dt>
                    <dd class="mt-0.5 text-gray-800 dark:text-gray-200">{{ $row['capability_summary'] ?? '—' }}</dd>
                </div>
            </dl>
        </div>
    @empty
        <div class="col-span-full rounded-xl border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500 dark:border-gray-600 dark:text-gray-400">
            {{ __('seo-content-ai::filament.extensions.empty') }}
        </div>
    @endforelse
</div>
