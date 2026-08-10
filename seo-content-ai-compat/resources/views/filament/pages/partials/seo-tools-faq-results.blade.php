@php
    $faqs = is_array($faqs ?? null) ? $faqs : [];
@endphp

@if ($faqs !== [])
    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">#</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('seo-content-ai::filament.tools.faq_question') }}
                    </th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('seo-content-ai::filament.tools.faq_answer') }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white dark:divide-white/10 dark:bg-gray-900">
                @foreach ($faqs as $index => $faq)
                    <tr wire:key="faq-{{ $index }}">
                        <td class="whitespace-nowrap px-3 py-2 text-sm text-gray-500">{{ $index + 1 }}</td>
                        <td class="px-3 py-2 text-sm font-medium text-gray-950 dark:text-white">
                            {{ $faq['question'] ?? '—' }}
                        </td>
                        <td class="px-3 py-2 text-sm text-gray-700 dark:text-gray-300">
                            {!! $faq['answer'] ?? '—' !!}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
