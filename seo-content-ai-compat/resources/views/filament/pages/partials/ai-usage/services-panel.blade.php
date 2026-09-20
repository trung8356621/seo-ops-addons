@php
    /** @var list<array<string, mixed>> $cards */
    $cards = $cards ?? [];
@endphp

<section class="h-full rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
    <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-800">
        <div>
            <h2 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">Dịch vụ nhanh</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400">Lối tắt SEO &amp; Seeding</p>
        </div>
    </div>

    <div class="space-y-2 p-3 sm:p-4">
        @forelse ($cards as $card)
            <article class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-950/30">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $card['name'] }}</div>
                        <div class="mt-0.5 text-xs text-gray-500">{{ $card['key_label'] }} · {{ $card['db_label'] }}</div>
                    </div>
                    <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                        {{ $card['badge'] }}
                    </span>
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <a
                        href="{{ $card['open_url'] }}"
                        class="inline-flex items-center rounded-lg bg-gray-950 px-3 py-1.5 text-xs font-semibold text-white hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                    >
                        Mở
                    </a>
                    <a
                        href="{{ $card['setup_url'] }}"
                        class="inline-flex items-center rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
                    >
                        Cấu hình
                    </a>
                </div>
            </article>
        @empty
            <p class="rounded-lg border border-dashed border-gray-300 px-4 py-8 text-center text-xs text-gray-400 dark:border-gray-700">Chưa có dịch vụ khả dụng.</p>
        @endforelse
    </div>
</section>
