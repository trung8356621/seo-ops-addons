@php
    /** @var array{seo: array{calls: int, tokens: int}, seeding: array{calls: int, tokens: int}, total_tokens: int, total_calls: int} $tokenSummary */
    $tokenSummary = $tokenSummary ?? $this->tokenSummary();
@endphp

<div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
    <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Tổng tokens</div>
        <div class="mt-1 text-2xl font-bold tracking-tight text-sky-600 dark:text-sky-400">
            {{ number_format($tokenSummary['total_tokens']) }}
        </div>
        <div class="mt-1 text-xs text-gray-500">{{ number_format($tokenSummary['total_calls']) }} calls</div>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">SEO tokens</div>
        <div class="mt-1 text-2xl font-bold tracking-tight text-emerald-600 dark:text-emerald-400">
            {{ number_format($tokenSummary['seo']['tokens']) }}
        </div>
        <div class="mt-1 text-xs text-gray-500">{{ number_format($tokenSummary['seo']['calls']) }} calls</div>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Seeding tokens</div>
        <div class="mt-1 text-2xl font-bold tracking-tight text-violet-600 dark:text-violet-400">
            {{ number_format($tokenSummary['seeding']['tokens']) }}
        </div>
        <div class="mt-1 text-xs text-gray-500">{{ number_format($tokenSummary['seeding']['calls']) }} calls</div>
    </div>
</div>
