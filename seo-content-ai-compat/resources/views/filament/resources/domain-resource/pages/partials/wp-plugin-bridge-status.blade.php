@props([
    'status',
    'site',
])

@php
    $installed = $status['installed_version'] ?? null;
    $latest = $status['latest_version'] ?? null;
    $updateAvailable = (bool) ($status['update_available'] ?? false);
    $unsupported = (bool) ($status['unsupported'] ?? false);
    $canUpdate = (bool) ($status['can_update'] ?? false);
    $lastStatus = (string) ($status['last_update_status'] ?? '');
    $domainLabel = preg_replace('#^https?://#i', '', rtrim((string) ($site->domain ?? ''), '/'));
    $checking = $this->wpPluginPhase === 'checking';
    $updating = in_array($this->wpPluginPhase, ['updating', 'verifying'], true);
@endphp

<div class="seo-wp-plugin-compact self-start h-auto" wire:key="wp-plugin-bridge-{{ $site->getKey() }}">
    <div class="flex items-start gap-3">
    <div class="seo-wp-plugin-compact__icon" aria-hidden="true">
        <x-filament::icon icon="heroicon-o-puzzle-piece" class="h-6 w-6" />
    </div>

    <div class="seo-wp-plugin-compact__body min-w-0">
        <p class="seo-wp-plugin-compact__title">WP Bridge</p>

        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
            <dt class="text-gray-500 dark:text-gray-400">Đang cài</dt>
            <dd class="font-semibold text-gray-950 dark:text-white">{{ $installed ?: '—' }}</dd>
            <dt class="text-gray-500 dark:text-gray-400">Mới nhất</dt>
            <dd class="font-semibold text-gray-950 dark:text-white">
                @if($latest)
                    {{ $latest }}
                @else
                    Chưa kiểm tra
                @endif
            </dd>
        </dl>

        @if($unsupported)
            <p class="text-[12px] font-medium text-warning-700 dark:text-warning-400">
                ⚠ Plugin hiện tại chưa hỗ trợ cập nhật từ Laravel
            </p>
        @elseif($updateAvailable && $installed && $latest)
            <p class="text-[12px] font-semibold text-warning-700 dark:text-warning-400">
                ⚠ Có bản cập nhật<br>{{ $installed }} → {{ $latest }}
            </p>
        @elseif($lastStatus === 'completed' || $lastStatus === 'reconciled' || ($installed && $latest && ! $updateAvailable))
            <p class="text-[12px] font-semibold text-success-700 dark:text-success-400">
                ✓ Đã cập nhật
            </p>
        @endif

        @if(filled($this->wpPluginFlash))
            <p class="text-xs text-gray-600 dark:text-gray-300">{{ $this->wpPluginFlash }}</p>
        @endif

        <div class="flex flex-wrap items-center gap-2" x-data="{ confirmOpen: false }">
            <x-filament::button
                type="button"
                color="gray"
                size="sm"
                wire:click="checkWpPluginVersion"
                wire:loading.attr="disabled"
                wire:target="checkWpPluginVersion"
            >
                <span wire:loading.remove wire:target="checkWpPluginVersion">
                    {{ $latest ? 'Kiểm tra lại' : 'Kiểm tra phiên bản' }}
                </span>
                <span wire:loading wire:target="checkWpPluginVersion">Đang kiểm tra...</span>
            </x-filament::button>

            @if($canUpdate)
                <x-filament::button
                    type="button"
                    color="primary"
                    size="sm"
                    x-on:click="confirmOpen = true"
                    wire:loading.attr="disabled"
                    wire:target="installWpPlugin"
                >
                    <span wire:loading.remove wire:target="installWpPlugin">Cập nhật plugin</span>
                    <span wire:loading wire:target="installWpPlugin">
                        {{ $this->wpPluginPhase === 'verifying' ? 'Đang xác minh phiên bản...' : 'Đang cập nhật plugin...' }}
                    </span>
                </x-filament::button>

                <div
                    x-show="confirmOpen"
                    x-cloak
                    class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/40 p-4"
                    x-on:keydown.escape.window="confirmOpen = false"
                >
                    <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl dark:bg-gray-900" @click.stop>
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Cập nhật WP Bridge</h3>
                        <p class="mt-2 text-sm text-gray-700 dark:text-gray-200">{{ $installed }} → {{ $latest }}</p>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Website: {{ $domainLabel }}</p>
                        <div class="mt-4 flex justify-end gap-2">
                            <x-filament::button type="button" color="gray" size="sm" x-on:click="confirmOpen = false">
                                Hủy
                            </x-filament::button>
                            <x-filament::button
                                type="button"
                                color="primary"
                                size="sm"
                                wire:click="installWpPlugin"
                                x-on:click="confirmOpen = false"
                            >
                                Cập nhật
                            </x-filament::button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
    </div>
</div>
