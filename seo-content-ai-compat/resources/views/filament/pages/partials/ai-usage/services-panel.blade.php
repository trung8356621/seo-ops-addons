@php
    /** @var list<array<string, mixed>> $cards */
    $cards = $cards ?? [];
@endphp

<section class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900 h-full">
    <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-800">
        <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Dịch vụ</h2>
        <p class="text-[11px] text-gray-500">Lối tắt SEO &amp; Seeding</p>
    </div>

    <div class="space-y-2.5 p-3 sm:p-4">
        @forelse ($cards as $card)
            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold text-gray-900 dark:text-white">{{ $card['name'] }}</div>
                        <div class="mt-0.5 text-[11px] text-gray-500">{{ $card['key_label'] }} · {{ $card['db_label'] }}</div>
                    </div>
                    <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                        {{ $card['badge'] }}
                    </span>
                </div>
                <div class="mt-2.5 flex flex-wrap gap-2">
                    <a
                        href="{{ $card['open_url'] }}"
                        class="inline-flex items-center rounded-lg bg-primary-600 px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-primary-500"
                    >
                        Mở
                    </a>
                    <a
                        href="{{ $card['setup_url'] }}"
                        class="inline-flex items-center rounded-lg border border-gray-300 px-2.5 py-1 text-[11px] font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800"
                    >
                        Cấu hình
                    </a>
                </div>
            </div>
        @empty
            <p class="py-6 text-center text-xs text-gray-400">Chưa có dịch vụ khả dụng.</p>
        @endforelse
    </div>
</section>
