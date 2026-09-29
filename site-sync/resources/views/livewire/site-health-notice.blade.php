<div data-site-health-notice>
    @if ($alert)
        @php($items = $alert['incidents'])
        <div
        x-data="{ hidden: false, open: false, selected: @js($items[0] ?? null), key: 'seo-site-health-dismissed:' + @js(auth()->id()) + ':' + @js($alert['signature']) }"
        x-init="hidden = localStorage.getItem(key) === '1'"
        x-show="! hidden"
        class="mx-auto w-full max-w-screen-2xl px-4 pt-4 sm:px-6 lg:px-8"
    >
        <section class="relative border border-l-4 p-4 shadow-sm {{ $alert['severity'] === 'critical' ? 'border-danger-400 border-l-danger-700 bg-danger-50 text-danger-950 dark:bg-danger-950/40 dark:text-danger-100' : 'border-warning-400 border-l-warning-600 bg-warning-50 text-warning-950 dark:bg-warning-950/40 dark:text-warning-100' }}" role="alert">
            <div class="flex gap-3 pr-8">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mt-0.5 h-6 w-6 shrink-0" />
                <div class="min-w-0 flex-1">
                    <p class="font-bold">{{ trans_choice($alert['severity'] === 'critical' ? 'site_health.banner.critical' : 'site_health.banner.warning', count($items), ['count' => count($items)]) }}</p>
                    <div class="mt-1 space-y-0.5 text-sm">
                        @foreach (array_slice($items, 0, 3) as $item)
                            <p><strong>{{ $item['domain'] }}</strong> — {{ $item['reason'] }}</p>
                        @endforeach
                        @if (count($items) === 1 && $items[0]['detected_ago'])<p>{{ __('site_health.banner.detected', ['time' => $items[0]['detected_ago']]) }}</p>@endif
                        @if (count($items) > 3)<p>{{ __('site_health.banner.more', ['count' => count($items) - 3]) }}</p>@endif
                    </div>
                    <button type="button" class="mt-3 font-semibold underline" @click="selected = @js($items[0]); open = true">{{ count($items) > 1 ? __('site_health.actions.view_all') : __('site_health.actions.view_details') }}</button>
                </div>
            </div>
            <button type="button" class="absolute right-3 top-3 text-2xl leading-none" aria-label="{{ __('site_health.actions.dismiss') }}" @click="localStorage.setItem(key, '1'); hidden = true">×</button>
        </section>

        <div x-show="open" x-cloak class="fixed inset-0 z-50" @keydown.escape.window="open = false">
            <div class="absolute inset-0 bg-gray-950/40" @click="open = false"></div>
            <aside class="absolute inset-y-0 right-0 w-full max-w-xl overflow-y-auto bg-white p-6 shadow-2xl dark:bg-gray-900" role="dialog" aria-modal="true">
                <div class="flex items-start justify-between gap-4"><h2 class="text-xl font-bold">{{ __('site_health.drawer.title') }}</h2><button type="button" class="text-2xl" @click="open = false">×</button></div>
                @if (count($items) > 1)
                    <div class="mt-4 flex flex-wrap gap-2">@foreach($items as $item)<button type="button" class="rounded border px-2 py-1 text-sm" @click="selected = @js($item)">{{ $item['domain'] }}</button>@endforeach</div>
                @endif
                <template x-if="selected"><div class="mt-6 space-y-4 text-sm">
                    <dl class="grid grid-cols-[10rem_1fr] gap-2"><dt>{{ __('site_health.fields.domain') }}</dt><dd x-text="selected.domain"></dd><dt>{{ __('site_health.fields.status') }}</dt><dd x-text="selected.status"></dd><dt>{{ __('site_health.fields.error_code') }}</dt><dd x-text="selected.error_code"></dd><dt>{{ __('site_health.fields.reason') }}</dt><dd x-text="selected.reason"></dd><dt>{{ __('site_health.fields.detected_at') }}</dt><dd x-text="selected.detected_at"></dd><dt>{{ __('site_health.fields.duration') }}</dt><dd x-text="selected.duration"></dd><dt>{{ __('site_health.fields.last_checked_at') }}</dt><dd x-text="selected.last_checked_at"></dd><dt>{{ __('site_health.fields.last_success_at') }}</dt><dd x-text="selected.last_success_at || '—'"></dd><dt>{{ __('site_health.fields.failures') }}</dt><dd x-text="selected.consecutive_failures"></dd></dl>
                    <div><h3 class="font-semibold">{{ __('site_health.fields.diagnostics') }}</h3><template x-for="(stage, name) in selected.stages" :key="name"><p><span x-text="name"></span>: <strong x-text="stage.status"></strong> <span x-text="stage.detail || ''"></span></p></template></div>
                    <div x-show="selected.technical_error"><h3 class="font-semibold">{{ __('site_health.fields.technical_error') }}</h3><p class="break-words" x-text="selected.technical_error"></p></div>
                    <div class="flex gap-3"><button type="button" class="rounded bg-primary-600 px-3 py-2 font-semibold text-white" @click="$wire.retry(selected.site_id)">{{ __('site_health.actions.retry') }}</button><a class="rounded border px-3 py-2 font-semibold" :href="selected.website_url" target="_blank" rel="noopener noreferrer">{{ __('site_health.actions.open_site') }}</a></div>
                </div></template>
            </aside>
        </div>
        </div>
    @endif
</div>
