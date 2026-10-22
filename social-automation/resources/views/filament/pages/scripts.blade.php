<x-filament-panels::page>
    @php
        $scripts = $this->scripts();
        $detail = $this->selectedDetail();
    @endphp

    <div class="space-y-6 max-w-6xl">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __('social-automation::filament.scripts.intro') }}
        </p>

        @if ($scripts === [])
            <div class="rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-500 dark:border-gray-600">
                {{ __('social-automation::filament.scripts.empty') }}
            </div>
        @else
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($scripts as $row)
                    @php
                        $demoStatus = (string) ($row['video_demo_status'] ?? 'missing');
                        $thumbId = $row['thumbnail_media_id'] ?? null;
                        $videoId = $row['video_media_id'] ?? null;
                    @endphp
                    <article class="flex flex-col overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                        <div class="relative aspect-video bg-gray-100 dark:bg-gray-800">
                            @if ($thumbId)
                                <img
                                    src="{{ $this->mediaUrl((int) $thumbId) }}"
                                    alt=""
                                    class="h-full w-full object-cover"
                                    loading="lazy"
                                />
                            @else
                                <div class="flex h-full w-full flex-col items-center justify-center gap-1 px-3 text-center text-xs text-gray-500 dark:text-gray-400">
                                    <span class="font-medium text-gray-600 dark:text-gray-300">
                                        @if ($demoStatus === 'thumbnail_unavailable')
                                            {{ __('social-automation::filament.scripts.thumbnail_unavailable') }}
                                        @else
                                            {{ __('social-automation::filament.scripts.missing_demo') }}
                                        @endif
                                    </span>
                                </div>
                            @endif

                            @if ($videoId)
                                <button
                                    type="button"
                                    wire:click="openVideoPlayer({{ (int) $videoId }})"
                                    class="absolute inset-0 flex items-center justify-center bg-black/20 text-white transition hover:bg-black/35"
                                    aria-label="{{ __('social-automation::filament.scripts.play_demo') }}"
                                >
                                    <span class="inline-flex h-11 w-11 items-center justify-center rounded-full bg-black/70 text-sm font-semibold">▶</span>
                                </button>
                            @endif
                        </div>

                        <div class="flex flex-1 flex-col gap-2 p-3">
                            <div>
                                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 line-clamp-2">{{ $row['name'] }}</h2>
                                <p class="mt-0.5 font-mono text-xs text-gray-500">{{ $row['version'] }}</p>
                            </div>

                            <dl class="grid grid-cols-2 gap-x-2 gap-y-1 text-xs text-gray-600 dark:text-gray-300">
                                <div>
                                    <dt class="text-gray-400">{{ __('social-automation::filament.scripts.col_status') }}</dt>
                                    <dd>
                                        {{ $row['status'] }}
                                        @unless ($row['assets_complete'])
                                            <span class="text-amber-600">incomplete</span>
                                        @endunless
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-gray-400">{{ __('social-automation::filament.scripts.col_source') }}</dt>
                                    <dd>{{ $row['source'] }}</dd>
                                </div>
                                <div class="col-span-2">
                                    <dt class="text-gray-400">{{ __('social-automation::filament.scripts.col_updated_by') }}</dt>
                                    <dd class="truncate" title="{{ $row['updated_by_label'] ?? '' }}">{{ $row['updated_by_label'] ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-400">{{ __('social-automation::filament.scripts.col_last_sync') }}</dt>
                                    <dd>{{ $row['last_sync_label'] ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-400">{{ __('social-automation::filament.scripts.col_widgets') }}</dt>
                                    <dd>{{ (int) ($row['widget_dependency_count'] ?? 0) }}</dd>
                                </div>
                                <div class="col-span-2">
                                    <dt class="text-gray-400">{{ __('social-automation::filament.scripts.col_video') }}</dt>
                                    <dd>
                                        @if ($demoStatus === 'ready')
                                            {{ __('social-automation::filament.scripts.demo_ready') }}
                                        @elseif ($demoStatus === 'thumbnail_unavailable')
                                            {{ __('social-automation::filament.scripts.thumbnail_unavailable') }}
                                        @else
                                            {{ __('social-automation::filament.scripts.missing_demo') }}
                                        @endif
                                    </dd>
                                </div>
                            </dl>

                            <div class="mt-auto flex items-center gap-2 pt-1">
                                <button
                                    type="button"
                                    wire:click="selectScript(@js($row['package_key']))"
                                    class="rounded bg-gray-800 px-2.5 py-1.5 text-xs font-medium text-white dark:bg-gray-200 dark:text-gray-900"
                                >
                                    {{ __('social-automation::filament.scripts.view_details') }}
                                </button>
                                <button
                                    type="button"
                                    wire:click="openRemoveConfirm(@js($row['package_key']))"
                                    class="rounded px-2.5 py-1.5 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-200 hover:bg-red-50 dark:text-red-300 dark:ring-red-900 dark:hover:bg-red-950/40"
                                >
                                    {{ __('social-automation::filament.scripts.remove') }}
                                </button>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        @if ($detail)
            <div class="rounded-lg border border-gray-200 p-4 space-y-3 dark:border-gray-700">
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
            <div class="w-full max-w-md rounded-lg bg-white p-4 shadow-lg dark:bg-gray-900" role="dialog" aria-modal="true">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('social-automation::filament.scripts.remove') }}</h3>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                    {{ __('social-automation::filament.scripts.remove_confirm') }}
                </p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="cancelRemoveConfirm" class="rounded px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-100 dark:hover:bg-gray-800">
                        {{ __('social-automation::filament.scripts.cancel') }}
                    </button>
                    <button
                        type="button"
                        wire:click="removeScript(@js($this->confirmRemoveKey))"
                        wire:loading.attr="disabled"
                        wire:target="removeScript"
                        class="rounded bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
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
            <div class="w-full max-w-3xl rounded-lg bg-black p-3 shadow-lg">
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
