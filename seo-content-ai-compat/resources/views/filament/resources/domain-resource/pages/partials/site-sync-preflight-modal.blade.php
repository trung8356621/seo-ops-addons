@php
    $confirm = is_array($siteSyncConfirm ?? null) ? $siteSyncConfirm : null;
    $open = (bool) (($siteSyncConfirmOpen ?? false) || (($siteSyncPreflightOpen ?? false) && $confirm !== null));
@endphp

<div
    class="site-sync-confirm"
    wire:key="site-sync-confirm-{{ md5(json_encode([
        $confirm['language'] ?? '',
        $confirm['language_role'] ?? '',
        $confirm['mode'] ?? '',
        $confirm['estimated_count'] ?? 0,
        $open ? 1 : 0,
    ])) }}"
>
    @if ($open && is_array($confirm))
    <style>
        .site-sync-confirm__overlay {
            position: fixed;
            inset: 0;
            z-index: 50;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgb(0 0 0 / 0.4);
            padding: max(0.75rem, env(safe-area-inset-top, 0px))
                max(0.75rem, env(safe-area-inset-right, 0px))
                max(0.75rem, env(safe-area-inset-bottom, 0px))
                max(0.75rem, env(safe-area-inset-left, 0px));
            box-sizing: border-box;
        }
        .site-sync-confirm__shell {
            display: flex;
            flex-direction: column;
            width: min(100%, 28rem);
            max-height: calc(100vh - 24px);
            max-height: calc(100dvh - 24px);
            overflow: hidden;
            border-radius: 0.75rem;
            border: 1px solid rgb(229 231 235);
            background: #fff;
            box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        }
        .dark .site-sync-confirm__shell {
            border-color: rgb(55 65 81);
            background: rgb(17 24 39);
        }
        .site-sync-confirm__footer-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 0.5rem;
        }
    </style>
    <div
        class="site-sync-confirm__overlay"
        role="dialog"
        aria-modal="true"
        aria-labelledby="site-sync-confirm-title"
    >
        <div class="site-sync-confirm__shell">
            <div class="border-b border-gray-100 px-4 py-3 sm:px-5 dark:border-gray-800">
                <h3 id="site-sync-confirm-title" class="text-[15px] font-semibold text-gray-900 dark:text-gray-100">
                    {{ $confirm['title'] ?? 'Xác nhận đồng bộ' }}
                </h3>
                <p class="mt-1 text-[13px] leading-relaxed text-gray-600 dark:text-gray-300">
                    {{ $confirm['body'] ?? '' }}
                </p>
                <dl class="mt-3 space-y-1 text-[12px] text-gray-600 dark:text-gray-300">
                    @if (filled($confirm['mode_label'] ?? null))
                        <div class="flex gap-2">
                            <dt class="shrink-0 text-gray-500">Chế độ:</dt>
                            <dd class="font-medium text-gray-800 dark:text-gray-100">{{ $confirm['mode_label'] }}</dd>
                        </div>
                    @endif
                    @if (filled($confirm['scope_label'] ?? $confirm['label'] ?? null))
                        <div class="flex gap-2">
                            <dt class="shrink-0 text-gray-500">Phạm vi:</dt>
                            <dd class="font-medium text-gray-800 dark:text-gray-100">{{ $confirm['scope_label'] ?? $confirm['label'] }}</dd>
                        </div>
                    @endif
                    <div class="flex gap-2">
                        <dt class="shrink-0 text-gray-500">Ước tính:</dt>
                        <dd class="font-medium text-gray-800 dark:text-gray-100">
                            @if ((int) ($confirm['estimated_count'] ?? 0) > 0)
                                {{ number_format((int) $confirm['estimated_count']) }} nội dung
                            @else
                                nhiều nội dung
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="site-sync-confirm__footer border-t border-gray-100 px-4 py-3 sm:px-5 dark:border-gray-800">
                <div class="site-sync-confirm__footer-actions">
                    <x-filament::button
                        type="button"
                        color="gray"
                        wire:click="cancelSiteSyncConfirm"
                        wire:loading.attr="disabled"
                        wire:target="cancelSiteSyncConfirm,confirmSiteSyncConfirm"
                    >
                        Hủy
                    </x-filament::button>
                    <x-filament::button
                        type="button"
                        color="{{ ($confirm['mode'] ?? '') === 'force_full' ? 'warning' : 'success' }}"
                        wire:click="confirmSiteSyncConfirm"
                        wire:loading.attr="disabled"
                        wire:target="cancelSiteSyncConfirm,confirmSiteSyncConfirm"
                    >
                        <span wire:loading.remove wire:target="confirmSiteSyncConfirm">
                            {{ $confirm['confirm_label'] ?? 'Xác nhận đồng bộ' }}
                        </span>
                        <span wire:loading wire:target="confirmSiteSyncConfirm">Đang xếp hàng…</span>
                    </x-filament::button>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>
