@php
    use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
    use Omnichannel\Addons\Content\Models\SeoArticle;
    use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchive;
    use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchiveItem;
    use Illuminate\Support\Collection;

    /** @var Collection<int, SeoProjectArchive> $batches */
    $batches = $batches ?? collect();
@endphp

<div class="space-y-4">
    @if ($batches->isEmpty())
        <div class="rounded-xl border border-gray-200 bg-white px-4 py-8 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
            {{ __('seo-content-ai::filament.projects.archives_empty') }}
        </div>
    @else
        <div class="space-y-3">
            @foreach ($batches as $batch)
                @php
                    $archivedBy = trim((string) ($batch->archivedByUser?->name ?? ''));
                    $note = trim((string) ($batch->note ?? ''));
                @endphp

                <div
                    class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900"
                    x-data="{ open: {{ $loop->first ? 'true' : 'false' }} }"
                >
                    <button
                        type="button"
                        class="flex w-full items-start justify-between gap-4 px-4 py-3 text-left hover:bg-gray-50 dark:hover:bg-gray-800/60"
                        @click="open = !open"
                    >
                        <div class="min-w-0 space-y-1">
                            <p class="text-sm font-semibold text-gray-950 dark:text-white">
                                {{ $batch->created_at?->format('d/m/Y H:i') }}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('seo-content-ai::filament.projects.archive_batch_meta', [
                                    'user' => $archivedBy !== '' ? $archivedBy : '—',
                                    'count' => (int) $batch->articles_count,
                                ]) }}
                            </p>
                            @if ($note !== '')
                                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $note }}</p>
                            @endif
                        </div>
                        <x-filament::icon
                            icon="heroicon-m-chevron-down"
                            class="h-5 w-5 shrink-0 text-gray-400 transition"
                            ::class="{ 'rotate-180': open }"
                        />
                    </button>

                    <div x-show="open" x-collapse class="border-t border-gray-200 dark:border-gray-700">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-800/60">
                                    <tr>
                                        <th class="px-4 py-2 text-left font-medium text-gray-600 dark:text-gray-300">
                                            {{ __('seo-content-ai::filament.projects.archive_col_title') }}
                                        </th>
                                        <th class="px-4 py-2 text-left font-medium text-gray-600 dark:text-gray-300">
                                            {{ __('seo-content-ai::filament.projects.archive_col_author') }}
                                        </th>
                                        <th class="px-4 py-2 text-left font-medium text-gray-600 dark:text-gray-300">
                                            {{ __('seo-content-ai::filament.projects.archive_col_status') }}
                                        </th>
                                        <th class="px-4 py-2 text-left font-medium text-gray-600 dark:text-gray-300">
                                            {{ __('seo-content-ai::filament.projects.archive_col_created_at') }}
                                        </th>
                                        <th class="px-4 py-2 text-right font-medium text-gray-600 dark:text-gray-300"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    @foreach ($batch->items as $item)
                                        @php
                                            $article = $item instanceof SeoProjectArchiveItem ? $item->article : null;
                                        @endphp
                                        @if ($article instanceof SeoArticle)
                                            <tr>
                                                <td class="px-4 py-2 font-medium text-gray-950 dark:text-white">
                                                    {{ $article->title }}
                                                </td>
                                                <td class="px-4 py-2 text-gray-600 dark:text-gray-300">
                                                    {{ $article->user?->name ?? '—' }}
                                                </td>
                                                <td class="px-4 py-2 text-gray-600 dark:text-gray-300">
                                                    {{ $article->status }}
                                                </td>
                                                <td class="px-4 py-2 text-gray-600 dark:text-gray-300">
                                                    {{ $article->created_at?->format('d/m/Y H:i') ?? '—' }}
                                                </td>
                                                <td class="px-4 py-2 text-right">
                                                    <a
                                                        href="{{ ArticleResource::getUrl('edit', ['record' => $article]) }}"
                                                        class="text-primary-600 hover:underline dark:text-primary-400"
                                                    >
                                                        {{ __('seo-content-ai::filament.projects.archive_open_article') }}
                                                    </a>
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
