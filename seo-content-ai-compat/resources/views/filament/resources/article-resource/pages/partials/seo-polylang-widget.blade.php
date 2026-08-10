@if ($this->siteHasPolylang())
    <div class="wp-postbox">
        <div class="wp-postbox-header">
            <h2>Ngôn ngữ &amp; bản dịch</h2>
        </div>
        <div class="wp-postbox-inside space-y-2 text-xs">
            <div>
                <span class="text-gray-500 dark:text-gray-400">Ngôn ngữ:</span>
                <span class="ml-1 inline-flex items-center rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-semibold text-sky-800 dark:bg-sky-950 dark:text-sky-200">
                    {{ $this->getArticleLanguageLabel() }}
                </span>
            </div>

            @php
                $translationConnections = $this->getTranslationConnections();
            @endphp
            @if ($translationConnections !== [])
                <div class="space-y-1.5 border-t border-gray-200 pt-2 dark:border-gray-700">
                    <div class="font-semibold text-gray-700 dark:text-gray-200">Bản dịch liên kết</div>
                    @foreach ($translationConnections as $connection)
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                @if (($connection['status'] ?? '') === 'linked')
                                    <span class="text-gray-800 dark:text-gray-100">
                                        {{ $connection['flag'] ?? '🌐' }} {{ $connection['label'] ?? $connection['lang'] }}:
                                        Linked
                                        @if (! empty($connection['wp_post_id']))
                                            (WP ID: {{ $connection['wp_post_id'] }})
                                        @endif
                                    </span>
                                @else
                                    <span class="text-amber-700 dark:text-amber-300">
                                        {{ $connection['flag'] ?? '🌐' }} {{ $connection['label'] ?? $connection['lang'] }}: Chưa có bản dịch
                                    </span>
                                @endif
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                @if (($connection['status'] ?? '') === 'linked' && ! empty($connection['edit_url']))
                                    @if ($this->isDefaultLanguageArticle() && $this->canQuickTranslateLinkedArticle() && ! empty($connection['article_id']))
                                        <button
                                            type="button"
                                            wire:click.stop="requestQuickTranslate('{{ $connection['lang'] }}', {{ (int) $connection['article_id'] }})"
                                            wire:loading.attr="disabled"
                                            wire:target="requestQuickTranslate,quickTranslateLinkedArticle"
                                            class="rounded border border-violet-300 px-1.5 py-0.5 text-[10px] font-semibold text-violet-800 hover:bg-violet-50 dark:border-violet-700 dark:text-violet-200 dark:hover:bg-violet-950"
                                            title="Dịch nhanh sang {{ $connection['label'] ?? $connection['lang'] }}"
                                        >
                                            <span wire:loading.remove wire:target="requestQuickTranslate,quickTranslateLinkedArticle">Dịch</span>
                                            <span wire:loading wire:target="requestQuickTranslate,quickTranslateLinkedArticle">…</span>
                                        </button>
                                    @endif
                                    <a
                                        href="{{ $connection['edit_url'] }}"
                                        class="inline-flex h-6 w-6 items-center justify-center rounded border border-gray-300 text-gray-600 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800"
                                        title="Mở bản dịch"
                                        aria-label="Mở bản dịch {{ $connection['label'] ?? $connection['lang'] }}"
                                    >
                                        ↗
                                    </a>
                                @elseif (($connection['status'] ?? '') === 'missing' && ! empty($connection['wp_post_id']))
                                    <button
                                        type="button"
                                        wire:click.stop="importMissingTranslation('{{ $connection['lang'] }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="importMissingTranslation"
                                        class="rounded border border-sky-300 px-1.5 py-0.5 text-[10px] font-semibold text-sky-700 hover:bg-sky-50 dark:border-sky-700 dark:text-sky-200 dark:hover:bg-sky-950"
                                    >
                                        Import
                                    </button>
                                @else
                                    <button
                                        type="button"
                                        wire:click.stop="requestTranslationGeneration('{{ $connection['lang'] }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="requestTranslationGeneration"
                                        class="rounded border border-amber-300 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-200 dark:hover:bg-amber-950"
                                    >
                                        Tạo bản dịch
                                    </button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endif
