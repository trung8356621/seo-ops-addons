<div data-site-health-notice>
    @if ($alert)
        @php($items = $alert['incidents'])
        @php($critical = $alert['severity'] === 'critical')
        <div
            x-data="{ hidden: false, open: false, selected: @js($items[0] ?? null), key: 'seo-site-health-dismissed:' + @js(auth()->id()) + ':' + @js($alert['signature']) }"
            x-init="hidden = localStorage.getItem(key) === '1'"
            x-show="! hidden"
            class="mx-auto w-full max-w-screen-2xl px-4 pt-4 sm:px-6 lg:px-8"
        >
            <section class="flex flex-col gap-3 rounded-xl border px-4 py-3 shadow-sm sm:flex-row sm:items-center {{ $critical ? 'border-danger-200 bg-danger-50/80 text-danger-950 dark:border-danger-800 dark:bg-danger-950/35 dark:text-danger-100' : 'border-warning-200 bg-warning-50/80 text-warning-950 dark:border-warning-800 dark:bg-warning-950/35 dark:text-warning-100' }}" role="alert">
                <div class="flex min-w-0 flex-1 items-start gap-3 sm:items-center">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ $critical ? 'bg-danger-100 text-danger-700 dark:bg-danger-900/70 dark:text-danger-300' : 'bg-warning-100 text-warning-700 dark:bg-warning-900/70 dark:text-warning-300' }}">
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold sm:text-base">{{ trans_choice($critical ? 'site_health.banner.critical' : 'site_health.banner.warning', count($items), ['count' => count($items)]) }}</p>
                        <div class="mt-0.5 space-y-0.5 text-sm text-gray-700 dark:text-gray-200">
                            @foreach (array_slice($items, 0, 3) as $item)
                                <p class="truncate"><span class="font-medium text-gray-950 dark:text-white">{{ $item['domain'] }}</span><span class="mx-1 text-gray-400">·</span>{{ $item['reason'] }}</p>
                            @endforeach
                            @if (count($items) === 1 && $items[0]['detected_ago'])
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('site_health.banner.detected', ['time' => $items[0]['detected_ago']]) }}</p>
                            @elseif (count($items) > 3)
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('site_health.banner.more', ['count' => count($items) - 3]) }}</p>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-2 pl-[3.25rem] sm:pl-0">
                    <button type="button" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white/80 px-3 py-1.5 text-sm font-semibold text-gray-800 shadow-sm transition hover:bg-white focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700" @click="selected = @js($items[0]); open = true">{{ count($items) > 1 ? __('site_health.actions.view_all') : __('site_health.actions.view_details') }}</button>
                    <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-500 transition hover:bg-black/5 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white" aria-label="{{ __('site_health.actions.dismiss') }}" @click="localStorage.setItem(key, '1'); hidden = true"><x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" /></button>
                </div>
            </section>

            <div x-show="open" x-cloak class="fixed inset-0 z-50" @keydown.escape.window="open = false">
                <div class="absolute inset-0 bg-gray-950/50 backdrop-blur-[1px]" @click="open = false"></div>
                <aside class="absolute inset-y-0 right-0 flex w-full max-w-xl flex-col bg-white shadow-2xl dark:bg-gray-900" role="dialog" aria-modal="true">
                    <header class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                        <div><p class="text-xs font-semibold uppercase tracking-wide {{ $critical ? 'text-danger-600 dark:text-danger-400' : 'text-warning-600 dark:text-warning-400' }}">{{ ucfirst($alert['severity']) }}</p><h2 class="mt-1 text-lg font-bold text-gray-950 dark:text-white">{{ __('site_health.drawer.title') }}</h2></div>
                        <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:hover:bg-gray-800 dark:hover:text-white" @click="open = false" aria-label="{{ __('site_health.actions.dismiss') }}"><x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" /></button>
                    </header>
                    <div class="flex-1 overflow-y-auto px-5 py-5">
                        @if (count($items) > 1)
                            <div class="mb-5 flex gap-2 overflow-x-auto pb-1">@foreach($items as $item)<button type="button" class="shrink-0 rounded-full border px-3 py-1.5 text-xs font-semibold transition" :class="selected?.id === {{ (int) $item['id'] }} ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-950/50 dark:text-primary-300' : 'border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800'" @click="selected = @js($item)">{{ $item['domain'] }}</button>@endforeach</div>
                        @endif
                        <template x-if="selected">
                            <div class="space-y-5 text-sm">
                                <section class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/60">
                                    <div class="flex flex-wrap items-center gap-2"><span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="selected.severity === 'critical' ? 'bg-danger-100 text-danger-700 dark:bg-danger-900/60 dark:text-danger-300' : 'bg-warning-100 text-warning-700 dark:bg-warning-900/60 dark:text-warning-300'" x-text="selected.status"></span><h3 class="font-semibold text-gray-950 dark:text-white" x-text="selected.domain"></h3></div>
                                    <p class="mt-2 text-gray-700 dark:text-gray-200" x-text="selected.reason"></p>
                                    <dl class="mt-4 grid grid-cols-1 gap-3 text-xs sm:grid-cols-2">
                                        <div><dt class="text-gray-500 dark:text-gray-400">{{ __('site_health.fields.error_code') }}</dt><dd class="mt-0.5 font-mono font-medium" x-text="selected.error_code"></dd></div><div><dt class="text-gray-500 dark:text-gray-400">{{ __('site_health.fields.detected_at') }}</dt><dd class="mt-0.5" x-text="selected.detected_at"></dd></div><div><dt class="text-gray-500 dark:text-gray-400">{{ __('site_health.fields.duration') }}</dt><dd class="mt-0.5" x-text="selected.duration"></dd></div><div><dt class="text-gray-500 dark:text-gray-400">{{ __('site_health.fields.failures') }}</dt><dd class="mt-0.5" x-text="selected.consecutive_failures"></dd></div><div><dt class="text-gray-500 dark:text-gray-400">{{ __('site_health.fields.last_checked_at') }}</dt><dd class="mt-0.5" x-text="selected.last_checked_at"></dd></div><div><dt class="text-gray-500 dark:text-gray-400">{{ __('site_health.fields.last_success_at') }}</dt><dd class="mt-0.5" x-text="selected.last_success_at || '—'"></dd></div>
                                    </dl>
                                </section>
                                <section><h3 class="mb-2 font-semibold text-gray-950 dark:text-white">{{ __('site_health.fields.diagnostics') }}</h3><div class="space-y-2"><template x-for="(stage, name) in selected.stages" :key="name"><div class="flex items-start justify-between gap-3 rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700"><div class="min-w-0"><p class="font-medium capitalize text-gray-900 dark:text-gray-100" x-text="name.replaceAll('_', ' ')"></p><p class="truncate text-xs text-gray-500 dark:text-gray-400" x-text="stage.detail || ''"></p></div><span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold" :class="stage.status === 'ok' ? 'bg-success-100 text-success-700 dark:bg-success-900/50 dark:text-success-300' : 'bg-danger-100 text-danger-700 dark:bg-danger-900/50 dark:text-danger-300'" x-text="stage.status"></span></div></template></div></section>
                                <section x-show="selected.technical_error"><h3 class="mb-2 font-semibold text-gray-950 dark:text-white">{{ __('site_health.fields.technical_error') }}</h3><pre class="whitespace-pre-wrap break-words rounded-lg bg-gray-950 p-3 font-mono text-xs text-gray-200" x-text="selected.technical_error"></pre></section>
                            </div>
                        </template>
                    </div>
                    <footer class="flex flex-wrap justify-end gap-2 border-t border-gray-200 px-5 py-4 dark:border-gray-700"><button type="button" class="rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2" @click="$wire.retry(selected.site_id)">{{ __('site_health.actions.retry') }}</button><a class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:text-gray-100 dark:hover:bg-gray-800" :href="selected.website_url" target="_blank" rel="noopener noreferrer">{{ __('site_health.actions.open_site') }}</a></footer>
                </aside>
            </div>
        </div>
    @endif
</div>
