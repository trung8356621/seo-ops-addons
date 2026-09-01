@props([
    'status',
    'site',
])

@php
    use Omnichannel\Addons\Content\Support\SystemDateTime;
    use Omnichannel\Addons\SearchFoundation\Support\DomainListPresentation;

    $card = $this->getSiteHealthCard();
    $sections = $card['sections'] ?? [];
    $wpSection = $sections['wordpress'] ?? [];
    $wpOk = (bool) ($wpSection['ok'] ?? false);
    $connectionLabel = $wpOk ? 'Healthy' : 'Offline / Degraded';

    $installed = $status['installed_version'] ?? null;
    $latest = $status['latest_version'] ?? null;
    $updateAvailable = (bool) ($status['update_available'] ?? false);
    $unsupported = (bool) ($status['unsupported'] ?? false);
    $canUpdate = (bool) ($status['can_update'] ?? false);
    $lastStatus = (string) ($status['last_update_status'] ?? '');
    $checkedAt = $status['version_checked_at'] ?? null;

    $lastChecked = null;
    foreach (($wpSection['lines'] ?? []) as $line) {
        if (is_string($line) && str_starts_with($line, 'Checked ')) {
            $lastChecked = substr($line, strlen('Checked '));
            break;
        }
    }
    if ($lastChecked === null && filled($checkedAt)) {
        $lastChecked = SystemDateTime::formatRelative($checkedAt);
    }

    $bridgeLine = null;
    $bridgeHint = null;
    if ($unsupported) {
        $bridgeLine = ($installed ?: '—').' · Unsupported';
        $bridgeHint = 'Plugin hiện tại chưa hỗ trợ cập nhật từ Laravel';
    } elseif ($updateAvailable && $installed && $latest) {
        $bridgeLine = $installed.' → '.$latest;
        $bridgeHint = 'Update available';
    } elseif ($installed && $latest && ! $updateAvailable) {
        $bridgeLine = $installed.' · Latest';
    } elseif ($installed) {
        $bridgeLine = (string) $installed;
    } else {
        $bridgeLine = '—';
    }

    $domainLabel = preg_replace('#^https?://#i', '', rtrim((string) ($site->domain ?? ''), '/'));
    $platform = DomainListPresentation::platformLabel((string) ($site->getMeta('seo_platform') ?? 'wordpress'));
@endphp

<div class="seo-connection-summary self-start h-auto" wire:key="wp-plugin-bridge-{{ $site->getKey() }}">
    <div class="seo-connection-summary__grid">
        <div>
            <p class="seo-connection-summary__kicker">Platform</p>
            <p class="seo-connection-summary__value">{{ $platform }}</p>
        </div>
        <div>
            <p class="seo-connection-summary__kicker">Connection</p>
            <p @class([
                'seo-connection-summary__value',
                'text-success-700 dark:text-success-400' => $wpOk,
                'text-danger-700 dark:text-danger-400' => ! $wpOk,
            ])>{{ $connectionLabel }}</p>
        </div>
        <div>
            <p class="seo-connection-summary__kicker">Bridge</p>
            <p class="seo-connection-summary__value">{{ $bridgeLine }}</p>
            @if ($bridgeHint)
                <p class="text-[12px] font-medium text-warning-700 dark:text-warning-400">{{ $bridgeHint }}</p>
            @elseif ($lastStatus === 'completed' || $lastStatus === 'reconciled')
                <p class="text-[12px] font-semibold text-success-700 dark:text-success-400">✓ Đã cập nhật</p>
            @endif
        </div>
        <div>
            <p class="seo-connection-summary__kicker">Last checked</p>
            <p class="seo-connection-summary__value">{{ $lastChecked ?: '—' }}</p>
        </div>
    </div>

    @if(filled($this->wpPluginFlash))
        <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">{{ $this->wpPluginFlash }}</p>
    @endif

    <div class="mt-3 flex flex-wrap items-center gap-2" x-data="{ confirmOpen: false }">
        <x-filament::button
            type="button"
            color="gray"
            size="sm"
            wire:click="reconcileSiteWordPressState"
            wire:loading.attr="disabled"
            wire:target="reconcileSiteWordPressState"
        >
            <span wire:loading.remove wire:target="reconcileSiteWordPressState">Check status</span>
            <span wire:loading wire:target="reconcileSiteWordPressState">Đang kiểm tra…</span>
        </x-filament::button>

        <x-filament::button
            type="button"
            color="gray"
            size="sm"
            wire:click="checkWpPluginVersion"
            wire:loading.attr="disabled"
            wire:target="checkWpPluginVersion"
        >
            <span wire:loading.remove wire:target="checkWpPluginVersion">Check version</span>
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
                <span wire:loading.remove wire:target="installWpPlugin">Update Bridge</span>
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
