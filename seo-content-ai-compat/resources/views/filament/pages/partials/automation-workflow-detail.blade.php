@php
    /** @var array<string, mixed> $workflow */
    $edgeByFrom = [];
    foreach (($workflow['edges'] ?? []) as $edge) {
        $edgeByFrom[$edge['from'] ?? ''][] = $edge;
    }
@endphp

<div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                {{ $workflow['name'] ?? '' }}
            </h2>
            <p class="mt-1 font-mono text-xs text-gray-500">{{ $workflow['id'] ?? '' }}</p>
            @if (! empty($workflow['description']))
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $workflow['description'] }}</p>
            @endif
        </div>
        <button type="button" wire:click="clearSelection" class="text-sm text-gray-500 hover:text-gray-800">
            {{ __('seo-content-ai::filament.automation.flows.close_detail') }}
        </button>
    </div>

    <div class="mt-4 flex flex-wrap gap-2 text-xs">
        <span class="rounded-full bg-gray-100 px-2 py-1 dark:bg-white/10">{{ $workflow['category_label'] ?? '' }}</span>
        <span class="rounded-full bg-gray-100 px-2 py-1 dark:bg-white/10">{{ $workflow['status_label'] ?? '' }}</span>
        <span class="rounded-full bg-gray-100 px-2 py-1 dark:bg-white/10">{{ $workflow['mapping_label'] ?? '' }}</span>
        <span class="rounded-full bg-gray-100 px-2 py-1 dark:bg-white/10">
            {{ __('seo-content-ai::filament.automation.flows.steps_count', ['count' => (int) ($workflow['step_count'] ?? 0)]) }}
        </span>
    </div>

    @if (! empty($workflow['definition_sources']))
        <div class="mt-4">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                {{ __('seo-content-ai::filament.automation.flows.definition_sources') }}
            </div>
            <ul class="mt-1 list-disc space-y-1 ps-4 text-xs text-gray-600 dark:text-gray-300">
                @foreach ($workflow['definition_sources'] as $source)
                    <li>{{ $source }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mt-6 space-y-0">
        <div class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500">
            {{ __('seo-content-ai::filament.automation.flows.workflow_map') }}
        </div>
        @foreach (($workflow['nodes'] ?? []) as $index => $node)
            <div class="relative flex gap-3 pb-6 last:pb-0">
                @if (! $loop->last)
                    <div class="absolute start-[11px] top-6 bottom-0 w-px bg-gray-200 dark:bg-white/10"></div>
                @endif
                <div @class([
                    'relative z-10 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[10px] font-bold text-white',
                    'bg-sky-500' => ($node['type'] ?? '') === 'event',
                    'bg-violet-500' => in_array($node['type'] ?? '', ['capability', 'command', 'action', 'pipeline', 'pipeline_step'], true),
                    'bg-gray-400' => ! in_array($node['type'] ?? '', ['event', 'capability', 'command', 'action', 'pipeline', 'pipeline_step'], true),
                    'opacity-50' => ($node['optional'] ?? false) && ! ($node['registered'] ?? false),
                ])>
                    {{ $index + 1 }}
                </div>
                <div class="min-w-0 flex-1 rounded-lg border border-gray-100 bg-gray-50 p-3 dark:border-white/5 dark:bg-white/5">
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="text-sm font-medium text-gray-900 dark:text-white">
                            {{ $node['label'] ?? '' }}
                        </div>
                        @if ($node['optional'] ?? false)
                            <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] text-amber-800">{{ __('seo-content-ai::filament.automation.flows.optional') }}</span>
                        @endif
                        @if (! ($node['registered'] ?? false))
                            <span class="rounded bg-rose-100 px-1.5 py-0.5 text-[10px] text-rose-700">{{ __('seo-content-ai::filament.automation.flows.node_not_registered') }}</span>
                        @endif
                    </div>
                    <div class="mt-1 font-mono text-[11px] text-gray-500">
                        {{ $node['canonical'] ?? '' }}
                        @if (! empty($node['type'])) · {{ $node['type'] }} @endif
                        @if (! empty($node['run_mode'])) · {{ $node['run_mode'] }} @endif
                    </div>
                    @if (! empty($node['evidence']))
                        <div class="mt-2 text-[11px] text-gray-500">
                            <span class="font-medium">{{ __('seo-content-ai::filament.automation.flows.evidence') }}:</span>
                            {{ $node['evidence'] }}
                        </div>
                    @endif
                    @if (! empty($edgeByFrom[$node['id'] ?? '']))
                        <div class="mt-2 flex flex-wrap gap-1">
                            @foreach ($edgeByFrom[$node['id']] as $edge)
                                <span class="rounded-full bg-white px-2 py-0.5 text-[10px] text-gray-600 ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
                                    {{ $edge['type_label'] ?? $edge['type'] }} → {{ $edge['to'] }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                    @if (! empty($node['matched_components']))
                        <details class="mt-2">
                            <summary class="cursor-pointer text-[11px] text-primary-600">
                                {{ __('seo-content-ai::filament.automation.flows.matched_components', ['count' => count($node['matched_components'])]) }}
                            </summary>
                            <ul class="mt-1 space-y-1 ps-3 text-[11px] text-gray-500">
                                @foreach ($node['matched_components'] as $match)
                                    <li class="font-mono">
                                        {{ $match['id'] ?? '' }}
                                        @if (! empty($match['last_status']))
                                            · {{ $match['last_status'] }}
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </details>
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
