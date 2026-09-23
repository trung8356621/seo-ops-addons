@props([
    'site',
])

@php
    use Omnichannel\Addons\SearchFoundation\Support\DomainListPresentation;

    $card = $this->getSiteHealthCard();
    $sections = $card['sections'] ?? [];
    $wpSection = $sections['wordpress'] ?? [];
    $wpOk = (bool) ($wpSection['ok'] ?? false);
    $connectionLabel = $wpOk ? 'Healthy' : 'Offline / Degraded';

    $lastChecked = null;
    foreach (($wpSection['lines'] ?? []) as $line) {
        if (is_string($line) && str_starts_with($line, 'Checked ')) {
            $lastChecked = substr($line, strlen('Checked '));
            break;
        }
    }

    $bridgeLine = DomainListPresentation::bridgeVersion($site)['line'];
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
        </div>
        <div>
            <p class="seo-connection-summary__kicker">Last checked</p>
            <p class="seo-connection-summary__value">{{ $lastChecked ?: '—' }}</p>
        </div>
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-2">
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
    </div>
</div>
