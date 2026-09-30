<div data-site-health-notice>
    @if ($alert)
        @php($items = $alert['incidents'])
        @php($critical = $alert['severity'] === 'critical')
        <div
            x-data="{ hidden: false, open: false, selected: @js($items[0] ?? null), retrying: false, retryError: null, key: 'seo-site-health-dismissed:' + @js(auth()->id()) + ':' + @js($alert['signature']) }"
            x-init="hidden = localStorage.getItem(key) === '1'"
            @site-health-retried.window="retrying = false; retryError = null; if (Number($event.detail.siteId) === Number(selected?.site_id)) { if ($event.detail.incident) { selected = $event.detail.incident } else { open = false; selected = null } }"
            x-show="! hidden"
            class="mx-auto w-full max-w-screen-2xl px-4 pt-4 sm:px-6 lg:px-8"
        >
            <section class="flex flex-col gap-3 rounded-xl border border-l-4 px-4 py-3 shadow-sm sm:flex-row sm:items-center {{ $critical ? 'border-red-300 border-l-red-600 bg-red-100 dark:border-red-800 dark:border-l-red-500 dark:bg-red-950/60' : 'border-amber-200 border-l-amber-500 bg-amber-100 dark:border-amber-800 dark:border-l-amber-600 dark:bg-amber-950/35' }}" style="{{ $critical ? 'background-color: #fee2e2; border-color: #fca5a5; border-left-color: #dc2626; color: #111827;' : 'background-color: #fef3c7; border-color: #fde68a; border-left-color: #f59e0b; color: #111827;' }}" role="alert">
                <div class="flex min-w-0 flex-1 items-start gap-3 sm:items-center">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ $critical ? 'bg-red-600 text-white dark:bg-red-500' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/70 dark:text-amber-300' }}" style="{{ $critical ? 'background-color: #dc2626; color: #ffffff;' : 'background-color: #fef3c7; color: #b45309;' }}">
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-bold sm:text-base" style="color: {{ $critical ? '#7f1d1d' : '#78350f' }};">{{ trans_choice($critical ? 'site_health.banner.critical' : 'site_health.banner.warning', count($items), ['count' => count($items)]) }}</p>
                        <div class="mt-0.5 space-y-0.5 text-sm" style="color: #374151;">
                            @foreach (array_slice($items, 0, 3) as $item)
                                <p class="truncate"><span class="font-semibold" style="color: #111827;">{{ $item['domain'] }}</span><span class="mx-1" style="color: #9ca3af;">·</span><span style="color: #4b5563;">{{ $item['reason'] }}</span></p>
                            @endforeach
                            @if (count($items) === 1 && $items[0]['detected_ago'])
                                <p class="text-xs" style="color: #6b7280;">{{ __('site_health.banner.detected', ['time' => $items[0]['detected_ago']]) }}</p>
                            @elseif (count($items) > 3)
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('site_health.banner.more', ['count' => count($items) - 3]) }}</p>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-2 pl-[3.25rem] sm:pl-0">
                    <button type="button" class="inline-flex items-center justify-center rounded-lg border px-3 py-1.5 text-sm font-semibold shadow-sm transition focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2" style="{{ $critical ? 'background-color: #dc2626; border-color: #dc2626; color: #ffffff;' : 'background-color: #ffffff; border-color: #f59e0b; color: #92400e;' }}" @click="selected = @js($items[0]); open = true">{{ count($items) > 1 ? __('site_health.actions.view_all') : __('site_health.actions.view_details') }}</button>
                    <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-lg transition focus:outline-none focus:ring-2 focus:ring-primary-500 {{ $critical ? 'text-red-500 hover:bg-red-100 hover:text-red-800 dark:text-red-300 dark:hover:bg-red-950' : 'text-amber-500 hover:bg-amber-100 hover:text-amber-800 dark:text-amber-300 dark:hover:bg-amber-950' }}" aria-label="{{ __('site_health.actions.dismiss') }}" @click="localStorage.setItem(key, '1'); hidden = true"><x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" /></button>
                </div>
            </section>

            <div x-show="open" x-cloak class="fixed inset-0 z-50" @keydown.escape.window="open = false">
                <div class="absolute inset-0 bg-gray-950/50 backdrop-blur-[1px]" @click="open = false"></div>
                <aside class="absolute inset-y-0 right-0 flex w-full max-w-xl flex-col bg-white shadow-2xl dark:bg-gray-900" role="dialog" aria-modal="true">
                    <header class="flex items-start justify-between gap-4 border-b border-gray-200 py-4 dark:border-gray-700" style="padding-left: 1.5rem; padding-right: 1.25rem;">
                        <div><p class="text-xs font-semibold uppercase tracking-wide {{ $critical ? 'text-danger-600 dark:text-danger-400' : 'text-warning-600 dark:text-warning-400' }}">{{ ucfirst($alert['severity']) }}</p><h2 class="mt-1 text-lg font-bold text-gray-950 dark:text-white">{{ __('site_health.drawer.title') }}</h2></div>
                        <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:hover:bg-gray-800 dark:hover:text-white" @click="open = false" aria-label="{{ __('site_health.actions.dismiss') }}"><x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" /></button>
                    </header>
                    <div class="flex-1 overflow-y-auto py-5" style="padding-left: 1.5rem; padding-right: 1.25rem;">
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
                    <footer class="flex flex-wrap justify-end gap-2 border-t border-gray-200 py-4 dark:border-gray-700" style="padding-left: 1.5rem; padding-right: 1.25rem;">
                        <p x-show="retryError" x-cloak class="w-full text-sm font-medium text-red-700 dark:text-red-300" x-text="retryError"></p>
                        <button type="button" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:cursor-wait disabled:opacity-70" :disabled="retrying || ! selected" @click="if (retrying || ! selected) return; retrying = true; retryError = null; $wire.retrySiteHealth(Number(selected.site_id)).catch(() => { retryError = 'Không thể kiểm tra lại lúc này. Vui lòng thử lại.'; retrying = false })">
                            <x-filament::loading-indicator x-show="retrying" x-cloak class="h-4 w-4" />
                            <span x-text="retrying ? 'Đang kiểm tra...' : @js(__('site_health.actions.retry'))"></span>
                        </button>
                        <a class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:text-gray-100 dark:hover:bg-gray-800" :href="selected?.website_url || '#'" target="_blank" rel="noopener noreferrer">{{ __('site_health.actions.open_site') }}</a>
                    </footer>
                </aside>
            </div>
        </div>
    @endif
</div>
