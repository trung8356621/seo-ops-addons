@php
    /** @var array<string, mixed> $flow */
@endphp

<div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                {{ $flow['name'] ?? '' }}
            </h2>
            <p class="mt-1 font-mono text-xs text-gray-500">{{ $flow['id'] ?? '' }}</p>
            @if (! empty($flow['description']))
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $flow['description'] }}</p>
            @endif
        </div>
        <button type="button" wire:click="clearSelection" class="text-sm text-gray-500 hover:text-gray-800">
            {{ __('seo-content-ai::filament.automation.flows.close_detail') }}
        </button>
    </div>

    <div class="mt-4 flex flex-wrap gap-2 text-xs">
        <span class="rounded-full bg-gray-100 px-2 py-1 dark:bg-white/10">{{ $flow['category_label'] ?? $flow['category'] ?? '' }}</span>
        <span class="rounded-full bg-gray-100 px-2 py-1 dark:bg-white/10">{{ $flow['status_label'] ?? '' }}</span>
        <span class="rounded-full bg-gray-100 px-2 py-1 dark:bg-white/10">{{ $flow['run_mode'] ?? '' }}</span>
        <span class="rounded-full bg-gray-100 px-2 py-1 dark:bg-white/10">{{ $flow['source'] ?? '' }}</span>
    </div>

    <div class="mt-6 space-y-0">
        @foreach (($flow['steps'] ?? []) as $index => $step)
            <div class="relative flex gap-3 pb-6 last:pb-0">
                @if (! $loop->last)
                    <div class="absolute start-[11px] top-6 bottom-0 w-px bg-gray-200 dark:bg-white/10"></div>
                @endif
                <div @class([
                    'relative z-10 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[10px] font-bold text-white',
                    'bg-sky-500' => ($step['type'] ?? '') === 'event',
                    'bg-amber-500' => ($step['type'] ?? '') === 'condition',
                    'bg-violet-500' => in_array($step['type'] ?? '', ['action', 'command', 'node'], true),
                    'bg-emerald-500' => ($step['type'] ?? '') === 'result',
                    'bg-gray-400' => ! in_array($step['type'] ?? '', ['event', 'condition', 'action', 'command', 'node', 'result'], true),
                ])>
                    {{ $index + 1 }}
                </div>
                <div class="min-w-0 flex-1 rounded-lg border border-gray-100 bg-gray-50 p-3 dark:border-white/5 dark:bg-white/5">
                    <div class="text-sm font-medium text-gray-900 dark:text-white">
                        {{ $step['label'] ?? '' }}
                    </div>
                    <div class="mt-1 font-mono text-[11px] text-gray-500">
                        {{ $step['identifier'] ?? '' }}
                        @if (! empty($step['type'])) · {{ $step['type'] }} @endif
                        @if (! empty($step['run_mode'])) · {{ $step['run_mode'] }} @endif
                    </div>
                    @if (! empty($step['handler']))
                        <div class="mt-1 font-mono text-[11px] text-gray-400">{{ $step['handler'] }}</div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-6 flex flex-wrap gap-3 border-t border-gray-100 pt-4 dark:border-white/10">
        <a href="{{ $this->executionsIndexUrl() }}" class="text-sm font-medium text-primary-600 hover:underline">
            {{ __('seo-content-ai::filament.automation.flows.link_executions') }}
        </a>
        <a href="{{ $this->operationsUrl() }}" class="text-sm font-medium text-primary-600 hover:underline">
            {{ __('seo-content-ai::filament.automation.flows.link_operations') }}
        </a>
    </div>
</div>
