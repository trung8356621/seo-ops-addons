<x-filament-panels::page>
    @vite([
        'addons/media/resources/css/media-library.css',
    ])

    <div
        class="seo-media-library seo-ai-processing"
        @if ($this->hasProcessingJobs()) wire:poll.5s="reloadItems" @endif
    >
        <div class="seo-media-library-filters-card">
            <div class="seo-media-library-filters">
                @unless ($this->hasLockedGlobalSite())
                    <div class="seo-media-library-field">
                        <label class="seo-media-library-label" for="image-processing-site">Domain</label>
                        <x-select id="image-processing-site" wire:model.live="siteId" class="text-sm">
                            <option value="">-- Select domain --</option>
                            @foreach ($this->sites as $site)
                                <option value="{{ $site->id }}">{{ $site->domain }}</option>
                            @endforeach
                        </x-select>
                    </div>
                @else
                    <div class="seo-media-library-field">
                        <label class="seo-media-library-label">Domain</label>
                        <div class="seo-media-library-select">
                            {{ $this->currentSiteDomain() ?? ('Site #' . (int) ($siteId ?? 0)) }}
                        </div>
                    </div>
                @endunless

                <div class="seo-media-library-field">
                    <label class="seo-media-library-label" for="image-processing-status">Status</label>
                    <x-select id="image-processing-status" wire:model.live="statusFilter" class="text-sm">
                        <option value="">{{ __('seo-content-ai::filament.image_processing.filter_all') }} ({{ $counts['all'] ?? 0 }})</option>
                        <option value="processing">{{ __('seo-content-ai::filament.image_processing.filter_processing') }} ({{ $counts['processing'] ?? 0 }})</option>
                        <option value="completed">{{ __('seo-content-ai::filament.image_processing.filter_completed') }} ({{ $counts['completed'] ?? 0 }})</option>
                        <option value="failed">{{ __('seo-content-ai::filament.image_processing.filter_failed') }} ({{ $counts['failed'] ?? 0 }})</option>
                    </x-select>
                </div>

                <div class="seo-media-library-field">
                    <label class="seo-media-library-label">&nbsp;</label>
                    <x-filament::button
                        type="button"
                        icon="heroicon-o-arrow-path"
                        wire:click="reloadItems"
                        wire:loading.attr="disabled"
                        wire:target="reloadItems"
                    >
                        <span wire:loading.remove wire:target="reloadItems">{{ __('seo-content-ai::filament.image_processing.reload') }}</span>
                        <span wire:loading wire:target="reloadItems">{{ __('seo-content-ai::filament.image_processing.reloading') }}</span>
                    </x-filament::button>
                </div>
            </div>
        </div>

        <div class="seo-media-library-meta" wire:loading.remove wire:target="reloadItems,siteId,statusFilter,page,previousPage,nextPage">
            @if ($total > 0)
                {{ $total }} {{ __('seo-content-ai::filament.image_processing.jobs') }} · Page {{ $page }}/{{ $totalPages }}
                @if ($this->hasProcessingJobs())
                    · {{ __('seo-content-ai::filament.image_processing.auto_refresh') }}
                @endif
            @elseif ($siteId)
                {{ __('seo-content-ai::filament.image_processing.empty') }}
            @else
                {{ __('seo-content-ai::filament.media_runtime.select_domain_to_view') }}
            @endif
        </div>

        <div wire:loading wire:target="reloadItems,siteId,statusFilter,page,previousPage,nextPage" class="seo-media-library-meta">
            {{ __('seo-content-ai::filament.image_processing.loading') }}
        </div>

        @if (! empty($items))
            <div class="seo-ai-processing-grid">
                @foreach ($items as $item)
                    @php
                        $status = (string) ($item['status'] ?? 'completed');
                    @endphp
                    <article
                        class="seo-ai-processing-card is-{{ $status }}"
                        wire:key="ai-job-{{ $item['id'] }}"
                    >
                        <div class="seo-ai-processing-card__thumb">
                            <img
                                src="{{ $item['url'] }}"
                                alt="{{ $item['slug'] ?: ('AI #' . $item['id']) }}"
                                loading="lazy"
                            />
                            @if ($item['is_placeholder'] ?? false)
                                <span class="seo-ai-processing-card__spinner" aria-hidden="true"></span>
                            @endif
                        </div>

                        <div class="seo-ai-processing-card__body">
                            <div class="seo-ai-processing-card__head">
                                <span class="seo-ai-processing-card__status is-{{ $status }}">
                                    {{ __('seo-content-ai::filament.image_processing.status_' . $status) }}
                                </span>
                                <span class="seo-ai-processing-card__type">
                                    {{ strtoupper((string) ($item['media_type'] ?? 'image')) }}
                                </span>
                            </div>

                            <p class="seo-ai-processing-card__slug">
                                #{{ $item['id'] }}
                                @if (filled($item['slug'] ?? null))
                                    · {{ $item['slug'] }}
                                @endif
                            </p>

                            @if (filled($item['error_message'] ?? null))
                                <p class="seo-ai-processing-card__error">{{ $item['error_message'] }}</p>
                            @endif

                            <p class="seo-ai-processing-card__meta">
                                @if (filled($item['created_at'] ?? null))
                                    {{ $item['created_at'] }}
                                @endif
                                @if (filled($item['updated_at'] ?? null) && ($item['updated_at'] ?? null) !== ($item['created_at'] ?? null))
                                    · {{ __('seo-content-ai::filament.image_processing.updated') }} {{ $item['updated_at'] }}
                                @endif
                            </p>

                            @if (($item['article_id'] ?? null) && filled($item['article_edit_url'] ?? null))
                                <a href="{{ $item['article_edit_url'] }}" class="seo-ai-processing-card__article">
                                    Article #{{ $item['article_id'] }}
                                </a>
                            @endif

                            <div class="seo-ai-processing-card__actions">
                                @if ($status === 'failed')
                                    <x-filament::button
                                        type="button"
                                        size="sm"
                                        color="gray"
                                        icon="heroicon-o-arrow-path"
                                        wire:click="retryJob({{ (int) $item['id'] }})"
                                        wire:loading.attr="disabled"
                                        wire:target="retryJob({{ (int) $item['id'] }})"
                                    >
                                        {{ __('seo-content-ai::filament.image_processing.retry') }}
                                    </x-filament::button>
                                @endif

                                <x-filament::button
                                    type="button"
                                    size="sm"
                                    color="danger"
                                    icon="heroicon-o-trash"
                                    wire:click="deleteJob({{ (int) $item['id'] }})"
                                    wire:confirm="{{ __('seo-content-ai::filament.image_processing.delete_confirm') }}"
                                    wire:loading.attr="disabled"
                                    wire:target="deleteJob({{ (int) $item['id'] }})"
                                >
                                    {{ __('seo-content-ai::filament.image_processing.delete') }}
                                </x-filament::button>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>

            @if ($totalPages > 1)
                <div class="seo-media-library-pagination">
                    <button
                        type="button"
                        class="seo-media-library-page-btn"
                        wire:click="previousPage"
                        @disabled($page <= 1)
                    >
                        Previous
                    </button>
                    <span class="seo-media-library-meta">{{ $page }} / {{ $totalPages }}</span>
                    <button
                        type="button"
                        class="seo-media-library-page-btn"
                        wire:click="nextPage"
                        @disabled($page >= $totalPages)
                    >
                        Next
                    </button>
                </div>
            @endif
        @elseif ($siteId)
            <div class="seo-media-library-empty">
                {{ __('seo-content-ai::filament.image_processing.empty') }}
            </div>
        @endif
    </div>
</x-filament-panels::page>
