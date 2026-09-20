@php
    /** @var list<array<string, mixed>> $walletCards */
    $walletCards = $walletCards ?? $this->walletCards();
    $showManageLink = $showManageLink ?? false;
    $manageProvidersUrl = $manageProvidersUrl ?? null;
    $compact = $compact ?? true;
@endphp

<section class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900 h-full">
    <div class="flex flex-col gap-1 border-b border-gray-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
        <div>
            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Ví nhà cung cấp (Provider Wallet)</h2>
            <p class="text-[11px] text-gray-500 dark:text-gray-400">
                Số dư thực tế do API chính thức của provider trả về
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($showManageLink && $manageProvidersUrl)
                <a href="{{ $manageProvidersUrl }}" class="text-xs font-medium text-primary-600 hover:text-primary-500">
                    Quản lý nhà cung cấp →
                </a>
            @endif
            <x-filament::button
                wire:click="$refresh"
                size="xs"
                color="gray"
                icon="heroicon-o-arrow-path"
                wire:loading.attr="disabled"
                wire:target="$refresh"
            >
                Làm mới
            </x-filament::button>
        </div>
    </div>

    <div class="p-3 sm:p-4">
        @if (count($walletCards) === 0)
            <div class="rounded-lg border border-dashed border-gray-300 p-6 text-center text-xs text-gray-500 dark:border-gray-700">
                Chưa có connection AI nào được cấu hình.
            </div>
        @else
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($walletCards as $card)
                    <div class="flex flex-col rounded-lg border border-gray-200 bg-gray-50/60 p-3 dark:border-gray-800 dark:bg-gray-950/40">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $card['name'] }}</div>
                                <div class="text-[10px] font-medium uppercase tracking-wider text-gray-400">{{ $card['provider_label'] }}</div>
                            </div>
                            <span class="shrink-0 inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium ring-1 ring-inset {{ $card['status_badge_class'] }}">
                                {{ $card['status_label'] }}
                            </span>
                        </div>

                        <div class="mt-2.5">
                            @if ($card['supported'])
                                @if ($card['balance'] !== null)
                                    <div class="text-xl font-bold tracking-tight text-gray-900 dark:text-white">
                                        {{ $card['currency'] === 'USD' ? '$' : $card['currency'].' ' }}{{ number_format((float) $card['balance'], 2) }}
                                        <span class="text-[10px] font-medium text-gray-400">{{ $card['currency'] }}</span>
                                    </div>
                                @else
                                    <div class="text-sm font-medium text-gray-400">Không có số dư</div>
                                @endif
                            @else
                                <div class="text-xs italic text-gray-500">Không hỗ trợ balance API</div>
                            @endif
                        </div>

                        <div class="mt-2 flex items-center justify-between gap-2 border-t border-gray-200/80 pt-2 text-[10px] text-gray-500 dark:border-gray-800">
                            <span>Ngưỡng: <strong>${{ number_format($card['threshold'], 2) }}</strong></span>
                            <button
                                type="button"
                                wire:click="openEditThresholdModal({{ $card['id'] }})"
                                class="font-medium text-primary-600 hover:text-primary-500"
                            >
                                Sửa
                            </button>
                        </div>

                        @if ($card['status'] === 'check_failed' && ! empty($card['error']))
                            <div class="mt-1.5 line-clamp-2 rounded border border-red-100 bg-red-50 p-1.5 text-[10px] text-red-600 dark:border-red-900/30 dark:bg-red-950/30 dark:text-red-400" title="{{ $card['error'] }}">
                                {{ $card['error'] }}
                            </div>
                        @endif

                        <div class="mt-2 flex items-center justify-between gap-2 border-t border-gray-200/80 pt-2 dark:border-gray-800">
                            <span class="text-[10px] text-gray-400" title="{{ $card['checked_at_exact'] ?? '' }}">
                                {{ $card['checked_at'] ? $card['checked_at'] : 'Chưa kiểm tra' }}
                            </span>
                            @if ($card['supported'])
                                <x-filament::button
                                    wire:click="refreshConnectionBalance({{ $card['id'] }})"
                                    size="xs"
                                    color="gray"
                                    wire:loading.attr="disabled"
                                    wire:target="refreshConnectionBalance({{ $card['id'] }})"
                                >
                                    <span wire:loading.remove wire:target="refreshConnectionBalance({{ $card['id'] }})">Refresh</span>
                                    <span wire:loading wire:target="refreshConnectionBalance({{ $card['id'] }})">…</span>
                                </x-filament::button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</section>
