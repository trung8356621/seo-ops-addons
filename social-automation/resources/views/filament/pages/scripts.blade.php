<x-filament-panels::page>
    @php
        $scripts = $this->scripts();
        $detail = $this->selectedDetail();
    @endphp

    <div class="w-full space-y-6">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __('social-automation::filament.scripts.intro') }}
        </p>

        @if ($scripts === [])
            <div class="rounded-xl border border-dashed border-gray-300 p-6 text-sm text-gray-500 dark:border-gray-600">
                {{ __('social-automation::filament.scripts.empty') }}
            </div>
        @else
            <div class="grid grid-cols-1 gap-5 md:grid-cols-2 lg:grid-cols-3 items-stretch">
                @foreach ($scripts as $row)
                    @php
                        $thumbId = $row['thumbnail_media_id'] ?? null;
                        $videoId = $row['video_media_id'] ?? null;
                        $status = strtolower((string) ($row['status'] ?? 'published'));
                        $badge = match (true) {
                            $status === 'yanked' => ['label' => 'Disabled', 'class' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200'],
                            ! ($row['assets_complete'] ?? true) => ['label' => 'Review', 'class' => 'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-200'],
                            default => ['label' => 'Published', 'class' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'],
                        };
                        $updatedRaw = trim((string) ($row['updated_by_label'] ?? ''));
                        if ($updatedRaw === '' || $updatedRaw === '—') {
                            $updatedShort = '—';
                        } elseif (preg_match('/^(.+?)\s*<[^>]+>$/', $updatedRaw, $m) === 1) {
                            $updatedShort = trim($m[1]);
                        } elseif (str_contains($updatedRaw, '@')) {
                            $updatedShort = strstr($updatedRaw, '@', true) ?: $updatedRaw;
                        } else {
                            $updatedShort = $updatedRaw;
                        }
                        $dateLabel = '—';
                        if (! empty($row['last_sync'])) {
                            try {
                                $dateLabel = \Illuminate\Support\Carbon::parse((string) $row['last_sync'])->timezone(config('app.timezone', 'UTC'))->format('d/m/Y');
                            } catch (\Throwable) {
                                $dateLabel = (string) ($row['last_sync_label'] ?? '—');
                            }
                        }
                        $widgetCount = (int) ($row['widget_dependency_count'] ?? 0);
                    @endphp
                    <article class="flex h-full flex-col overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                        {{-- A. Media preview --}}
                        <div class="relative aspect-video w-full shrink-0 bg-gray-100 dark:bg-gray-800">
                            @if ($thumbId)
                                <img
                                    src="{{ $this->mediaUrl((int) $thumbId) }}"
                                    alt=""
                                    class="h-full w-full object-cover"
                                    loading="lazy"
                                />
                            @endif

                            @if ($videoId)
                                <button
                                    type="button"
                                    wire:click="openVideoPlayer({{ (int) $videoId }})"
                                    class="absolute inset-0 flex items-center justify-center {{ $thumbId ? 'bg-black/15 hover:bg-black/30' : '' }} transition"
                                    aria-label="{{ __('social-automation::filament.scripts.play_demo') }}"
                                >
                                    <span class="inline-flex h-12 w-12 items-center justify-center rounded-full {{ $thumbId ? 'bg-black/65 text-white' : 'bg-gray-300 text-gray-700 dark:bg-gray-600 dark:text-gray-100' }}">
                                        <svg class="ml-0.5 h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.5 4.5v11l9-5.5-9-5.5z"/></svg>
                                    </span>
                                </button>
                            @else
                                <div class="absolute inset-0 flex items-center justify-center pointer-events-none" aria-hidden="true">
                                    <span class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-gray-300 text-gray-600 dark:bg-gray-600 dark:text-gray-200">
                                        <svg class="ml-0.5 h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M6.5 4.5v11l9-5.5-9-5.5z"/></svg>
                                    </span>
                                </div>
                            @endif
                        </div>

                        {{-- B. Main content --}}
                        <div class="flex flex-1 flex-col px-4 pt-4 pb-0">
                            <div class="flex items-start gap-2">
                                <h2 class="min-w-0 flex-1 text-[15px] font-semibold leading-snug text-gray-900 line-clamp-2 dark:text-gray-100">
                                    {{ $row['name'] }}
                                </h2>
                                <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $badge['class'] }}">
                                    {{ $badge['label'] }}
                                </span>
                            </div>

                            <p class="mt-1 text-[12px] text-gray-500 dark:text-gray-400">
                                Version {{ $row['version'] }}
                            </p>

                            <div class="mt-3 flex items-center gap-4 text-[12px] text-gray-500 dark:text-gray-400">
                                <span class="inline-flex min-w-0 items-center gap-1.5 truncate" title="{{ $updatedRaw }}">
                                    <svg class="h-3.5 w-3.5 shrink-0 opacity-70" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/></svg>
                                    <span class="truncate">{{ $updatedShort }}</span>
                                </span>
                                <span class="inline-flex items-center gap-1.5 shrink-0">
                                    <svg class="h-3.5 w-3.5 opacity-70" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4 4h4v4H4V4zm8 0h4v4h-4V4zM4 12h4v4H4v-4zm8 0h4v4h-4v-4z"/></svg>
                                    {{ $widgetCount }} {{ $widgetCount === 1 ? 'Widget' : 'Widgets' }}
                                </span>
                            </div>

                            {{-- C. Footer --}}
                            <div class="mt-auto flex items-center justify-between gap-3 border-t border-gray-100 pt-3 pb-4 dark:border-gray-800">
                                <span class="text-[12px] text-gray-400 dark:text-gray-500">{{ $dateLabel }}</span>
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        wire:click="selectScript(@js($row['package_key']))"
                                        class="rounded-full border border-gray-200 bg-white px-3 py-1.5 text-[12px] font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800"
                                    >
                                        {{ __('social-automation::filament.scripts.details') }}
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="openRemoveConfirm(@js($row['package_key']))"
                                        class="rounded-full border border-gray-200 bg-white px-3 py-1.5 text-[12px] font-medium text-red-600 hover:bg-red-50 dark:border-gray-600 dark:bg-gray-900 dark:text-red-400 dark:hover:bg-red-950/40"
                                    >
                                        {{ __('social-automation::filament.scripts.remove') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        @if ($detail)
            <div class="rounded-xl border border-gray-200 p-4 space-y-3 dark:border-gray-700">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-base font-semibold">{{ $detail['name'] }} <span class="font-mono text-xs text-gray-500">{{ $detail['package_key'] }}</span></h2>
                    <button type="button" wire:click="clearSelection" class="text-sm text-gray-500">Đóng</button>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $detail['description'] ?: '—' }}</p>

                <div>
                    <h3 class="font-medium text-sm mb-1">Widget dependencies</h3>
                    @forelse ($detail['widget_dependencies'] as $w)
                        <div class="text-sm">
                            {{ $w['name'] }} ({{ $w['package_key'] }})
                            @unless ($w['is_enabled'])
                                <span class="text-amber-600">disabled: {{ $w['disabled_reason'] }}</span>
                            @endunless
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">Chưa có Widget phụ thuộc.</p>
                    @endforelse
                </div>

                @foreach ($detail['releases'] as $i => $release)
                    <div class="rounded border border-gray-100 p-3 dark:border-gray-800 space-y-2">
                        <div class="text-sm font-medium">Release {{ $release['version'] }} · {{ $release['published_at'] ?? '—' }}</div>
                        <div class="text-xs font-mono">digest {{ $release['content_digest'] }}</div>
                        <div class="text-sm">
                            Video demo:
                            @if (($release['video_demo_status'] ?? 'missing') === 'ready' && ! empty($release['video_media_id']))
                                <button type="button" wire:click="openVideoPlayer({{ (int) $release['video_media_id'] }})" class="text-teal-700 underline">Play</button>
                            @elseif (($release['video_demo_status'] ?? '') === 'thumbnail_unavailable')
                                {{ __('social-automation::filament.scripts.thumbnail_unavailable') }}
                            @elseif ($release['video_demo'])
                                {{ $release['video_demo']['artifact_ref'] ?? $release['video_demo']['path'] ?? 'ref' }}
                                <span class="text-gray-500">({{ __('social-automation::filament.scripts.missing_demo') }})</span>
                            @else
                                {{ __('social-automation::filament.scripts.missing_demo') }}
                            @endif
                        </div>
                        <details>
                            <summary class="text-sm cursor-pointer">Variables / Steps / Assets</summary>
                            <pre class="mt-2 overflow-auto text-xs bg-gray-50 dark:bg-gray-900 p-2 rounded">{{ json_encode([
                                'variables' => $release['variables'],
                                'steps' => $release['steps'],
                                'assets' => $release['assets'],
                                'runtime_requirements' => $release['runtime_requirements'],
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </details>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    @if ($this->confirmRemoveKey)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:key="remove-modal">
            <div class="w-full max-w-md rounded-xl bg-white p-4 shadow-lg dark:bg-gray-900" role="dialog" aria-modal="true">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('social-automation::filament.scripts.remove') }}</h3>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                    {{ __('social-automation::filament.scripts.remove_confirm') }}
                </p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="cancelRemoveConfirm" class="rounded-full px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-100 dark:hover:bg-gray-800">
                        {{ __('social-automation::filament.scripts.cancel') }}
                    </button>
                    <button
                        type="button"
                        wire:click="removeScript(@js($this->confirmRemoveKey))"
                        wire:loading.attr="disabled"
                        wire:target="removeScript"
                        class="rounded-full bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="removeScript">{{ __('social-automation::filament.scripts.remove') }}</span>
                        <span wire:loading wire:target="removeScript">…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if ($this->playingMediaId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" wire:key="video-modal">
            <div class="w-full max-w-3xl rounded-xl bg-black p-3 shadow-lg">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <span class="text-sm text-white">{{ __('social-automation::filament.scripts.play_demo') }}</span>
                    <button type="button" wire:click="closeVideoPlayer" class="text-sm text-gray-300 hover:text-white">Đóng</button>
                </div>
                <video
                    controls
                    playsinline
                    preload="metadata"
                    class="max-h-[70vh] w-full rounded bg-black"
                    src="{{ $this->mediaUrl((int) $this->playingMediaId) }}"
                >
                    {{ __('social-automation::filament.scripts.video_unsupported') }}
                </video>
            </div>
        </div>
    @endif
</x-filament-panels::page>
