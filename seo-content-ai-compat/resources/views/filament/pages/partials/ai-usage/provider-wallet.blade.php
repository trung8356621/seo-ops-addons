@php
    /** @var list<array<string, mixed>> $walletCards */
    $walletCards = $walletCards ?? $this->walletCards();
    $showManageLink = $showManageLink ?? false;
    $manageProvidersUrl = $manageProvidersUrl ?? null;
    $compact = $compact ?? true;
@endphp

<section class="h-full rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
    <div class="flex flex-col gap-3 border-b border-gray-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
        <div class="flex items-start gap-2.5">
            <span class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300">
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.75V18a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18V6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v1.5M16.5 12h5.25v4.5H16.5a2.25 2.25 0 0 1 0-4.5Z" />
                </svg>
            </span>
            <div>
                <h2 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">Ví nhà cung cấp (Provider Wallet)</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">Số dư thực tế do API chính thức của provider trả về.</p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($showManageLink && $manageProvidersUrl)
                <a href="{{ $manageProvidersUrl }}" class="inline-flex items-center gap-1 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">
                    Quản lý nhà cung cấp
                    <span aria-hidden="true">→</span>
                </a>
            @endif
            <x-filament::button
                wire:click="refreshProviderWallets"
                size="xs"
                color="gray"
                icon="heroicon-o-arrow-path"
                wire:loading.attr="disabled"
                wire:target="refreshProviderWallets"
            >
                <span wire:loading.remove wire:target="refreshProviderWallets">Làm mới</span>
                <span wire:loading wire:target="refreshProviderWallets">Đang kiểm tra...</span>
            </x-filament::button>
        </div>
    </div>

    <div class="p-3 sm:p-4">
        @if (count($walletCards) === 0)
            <div class="rounded-lg border border-dashed border-gray-300 p-6 text-center text-xs text-gray-500 dark:border-gray-700">
                Chưa có connection AI nào được cấu hình.
            </div>
        @else
            <div class="ops-wallet-grid grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($walletCards as $card)
                    @php
                        $provider = strtolower((string) ($card['provider'] ?? ''));
                        $providerTone = match ($provider) {
                            'deepseek' => 'bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300',
                            'openrouter' => 'bg-indigo-50 text-indigo-600 dark:bg-indigo-950/40 dark:text-indigo-300',
                            default => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200',
                        };
                        $providerInitial = strtoupper(substr((string) ($card['provider_label'] ?? $card['name']), 0, 1));
                    @endphp
                    <article class="flex min-h-[12rem] flex-col rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-950/30">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-sm font-bold {{ $providerTone }}">
                                    {{ $providerInitial }}
                                </span>
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $card['name'] }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $card['provider_label'] }}</div>
                                </div>
                            </div>
                            <span class="shrink-0 inline-flex items-center rounded-full px-2 py-1 text-[11px] font-semibold ring-1 ring-inset {{ $card['status_badge_class'] }}">
                                {{ $card['status_label'] }}
                            </span>
                        </div>

                        <div class="mt-4">
                            @if ($card['supported'])
                                @if ($card['balance'] !== null)
                                    <div class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                                        {{ $card['currency'] === 'USD' ? '$' : $card['currency'].' ' }}{{ number_format((float) $card['balance'], 2) }}
                                        <span class="align-baseline text-xs font-semibold text-gray-500">{{ $card['currency'] }}</span>
                                    </div>
                                @else
                                    <div class="text-sm font-semibold text-gray-400">Không có số dư</div>
                                @endif
                            @else
                                <div class="text-sm font-medium text-gray-500">Không hỗ trợ balance API</div>
                            @endif
                            <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                Ngưỡng cảnh báo: <span class="font-semibold text-gray-700 dark:text-gray-200">${{ number_format($card['threshold'], 2) }}</span>
                            </div>
                            <div class="mt-2 flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                                <span>{{ $card['checked_at'] ? __('Checked').$card['checked_at'] : __('Not checked yet') }}</span>
                            </div>
                        </div>

                        @if ($card['status'] === 'check_failed' && ! empty($card['error']))
                            <div class="mt-3 line-clamp-2 rounded-lg border border-red-100 bg-red-50 p-2 text-xs text-red-600 dark:border-red-900/30 dark:bg-red-950/30 dark:text-red-400" title="{{ $card['error'] }}">
                                {{ $card['error'] }}
                            </div>
                        @endif

                        <div class="mt-auto flex flex-wrap items-center gap-2 pt-4">
                            <button
                                type="button"
                                wire:click="openEditThresholdModal({{ $card['id'] }})"
                                class="inline-flex items-center rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
                            >
                                Sửa ngưỡng
                            </button>
                            @if ($card['supported'])
                                <button
                                    type="button"
                                    wire:click="refreshConnectionBalance({{ $card['id'] }})"
                                    wire:loading.attr="disabled"
                                    wire:target="refreshConnectionBalance({{ $card['id'] }})"
                                    class="inline-flex items-center rounded-lg bg-gray-950 px-3 py-1.5 text-xs font-semibold text-white hover:bg-gray-800 disabled:opacity-60 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                                >
                                    <span wire:loading.remove wire:target="refreshConnectionBalance({{ $card['id'] }})">Kiểm tra</span>
                                    <span wire:loading wire:target="refreshConnectionBalance({{ $card['id'] }})">Đang tải...</span>
                                </button>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</section>
