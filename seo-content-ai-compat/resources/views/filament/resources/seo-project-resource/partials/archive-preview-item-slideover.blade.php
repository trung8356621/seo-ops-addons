@php
    /** @var array<string, mixed> $row */
    $row = is_array($row ?? null) ? $row : [];

    $display = static function (mixed $value): string {
        if ($value === null) {
            return '—';
        }
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed !== '' ? $trimmed : '—';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '—';
    };

    $title = $display($row['title'] ?? null);
    $keyword = $display($row['keyword'] ?? null);
    $slug = $display($row['slug'] ?? null);
    $metaTitle = $display($row['meta_title'] ?? null);
    $metaDescription = $display($row['meta_description'] ?? null);
    $outline = $display($row['outline_meta'] ?? null);
    $taskStatus = trim((string) ($row['task_status'] ?? ''));
    $reviewStatus = trim((string) ($row['review_status'] ?? ''));
    $syncStatus = trim((string) ($row['sync_status'] ?? ''));
    $wpUrl = trim((string) ($row['wordpress_url'] ?? ''));
    $excerpt = trim((string) ($row['body_excerpt'] ?? ''));
    $editUrl = is_string($row['edit_url'] ?? null) ? $row['edit_url'] : null;
    $canEdit = (bool) ($row['can_edit'] ?? false);
    $articleExists = (bool) ($row['article_exists'] ?? false);
    $seoScore = $row['seo_score'] ?? null;
@endphp

<div class="fi-archive-preview-slideover-body space-y-5 text-sm">
    @if (! $articleExists)
        <div class="rounded-lg border border-warning-300 bg-warning-50 px-3 py-2 text-warning-900 dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-100">
            {{ __('seo-content-ai::filament.projects.archive_preview_article_missing') }}
        </div>
    @endif

    {{-- A. Main content --}}
    <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <h4 class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ __('seo-content-ai::filament.projects.archive_preview_section_main') }}
        </h4>
        <dl class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_col_title') }}</dt>
                <dd class="mt-1 select-text font-medium text-gray-950 dark:text-white">{{ e($title) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_keyword') }}</dt>
                <dd class="mt-1 select-text text-gray-900 dark:text-white">{{ e($keyword) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_slug') }}</dt>
                <dd class="mt-1 break-all select-text text-gray-900 dark:text-white">{{ e($slug) }}</dd>
            </div>
        </dl>
    </section>

    {{-- B. SEO metadata --}}
    <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <h4 class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ __('seo-content-ai::filament.projects.archive_preview_section_seo') }}
        </h4>
        <dl class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_meta_title') }}</dt>
                <dd class="mt-1 select-text text-gray-900 dark:text-white">{{ e($metaTitle) }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_meta_description') }}</dt>
                <dd class="mt-1 select-text leading-relaxed text-gray-900 dark:text-white">{{ e($metaDescription) }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_outline') }}</dt>
                <dd class="mt-1 max-h-40 overflow-y-auto whitespace-pre-wrap select-text leading-relaxed text-gray-900 dark:text-white">{{ e($outline) }}</dd>
            </div>
        </dl>
    </section>

    {{-- C. Status --}}
    <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <h4 class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ __('seo-content-ai::filament.projects.archive_preview_section_status') }}
        </h4>
        <dl class="grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_col_status') }}</dt>
                <dd class="mt-1">
                    @if ($taskStatus !== '')
                        <span class="inline-flex rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ e($taskStatus) }}</span>
                    @else
                        —
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_review_status') }}</dt>
                <dd class="mt-1">
                    @if ($reviewStatus !== '')
                        <span class="inline-flex rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ e($reviewStatus) }}</span>
                    @else
                        —
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_col_avg_seo') }}</dt>
                <dd class="mt-1 select-text text-gray-900 dark:text-white">
                    {{ $seoScore !== null ? number_format((float) $seoScore, 2) : '—' }}
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_images') }}</dt>
                <dd class="mt-1 select-text text-gray-900 dark:text-white">{{ (int) ($row['image_count'] ?? 0) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_internal_links') }}</dt>
                <dd class="mt-1 select-text text-gray-900 dark:text-white">{{ (int) ($row['internal_link_count'] ?? 0) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_external_links') }}</dt>
                <dd class="mt-1 select-text text-gray-900 dark:text-white">{{ (int) ($row['external_link_count'] ?? 0) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_sync') }}</dt>
                <dd class="mt-1">
                    @if ($syncStatus !== '')
                        <span class="inline-flex rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ e($syncStatus) }}</span>
                    @else
                        —
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_wp_post') }}</dt>
                <dd class="mt-1 select-text text-gray-900 dark:text-white">
                    {{ ($row['wordpress_post_id'] ?? null) ? (int) $row['wordpress_post_id'] : '—' }}
                </dd>
            </div>
            @if (trim((string) ($row['wp_sync_error'] ?? '')) !== '')
                <div class="sm:col-span-2">
                    <dt class="text-xs font-medium text-danger-600 dark:text-danger-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_wp_error') }}</dt>
                    <dd class="mt-1 text-danger-700 dark:text-danger-300">{{ e((string) $row['wp_sync_error']) }}</dd>
                </div>
            @endif
        </dl>
    </section>

    {{-- D. Links --}}
    <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <h4 class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ __('seo-content-ai::filament.projects.archive_preview_section_links') }}
        </h4>
        <dl class="grid gap-4">
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_wp_url') }}</dt>
                <dd class="mt-1">
                    @if ($wpUrl !== '')
                        <a
                            href="{{ e($wpUrl) }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex max-w-full items-start gap-1 break-all text-primary-600 hover:underline dark:text-primary-400"
                        >
                            <span class="min-w-0">{{ e($wpUrl) }}</span>
                            <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                        </a>
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.projects.archive_preview_open_wp') }}
                        </div>
                    @else
                        —
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_edit_article') }}</dt>
                <dd class="mt-1">
                    @if ($canEdit && filled($editUrl))
                        <a
                            href="{{ $editUrl }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 font-medium text-primary-600 hover:underline dark:text-primary-400"
                        >
                            {{ __('seo-content-ai::filament.projects.archive_preview_edit_article') }}
                            <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="h-3.5 w-3.5" />
                        </a>
                    @elseif ($articleExists)
                        —
                    @else
                        <span class="text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_article_missing') }}</span>
                    @endif
                </dd>
            </div>
        </dl>
    </section>

    {{-- E. Timestamps --}}
    <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <h4 class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ __('seo-content-ai::filament.projects.archive_preview_section_timestamps') }}
        </h4>
        <dl class="grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_col_created_at') }}</dt>
                <dd class="mt-1 select-text text-gray-900 dark:text-white">{{ e($display($row['created_at'] ?? null)) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_updated_at') }}</dt>
                <dd class="mt-1 select-text text-gray-900 dark:text-white">{{ e($display($row['updated_at'] ?? null)) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.completed_at') }}</dt>
                <dd class="mt-1 select-text text-gray-900 dark:text-white">{{ e($display($row['completed_at'] ?? null)) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_last_saved') }}</dt>
                <dd class="mt-1 select-text text-gray-900 dark:text-white">{{ e($display($row['last_saved_at'] ?? null)) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_last_synced') }}</dt>
                <dd class="mt-1 select-text text-gray-900 dark:text-white">{{ e($display($row['last_synced_at'] ?? null)) }}</dd>
            </div>
        </dl>
    </section>

    {{-- F. Excerpt --}}
    <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <h4 class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ __('seo-content-ai::filament.projects.archive_preview_section_excerpt') }}
        </h4>
        @if ($excerpt !== '')
            <div
                x-data="{ expanded: false }"
                class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/50"
            >
                <div
                    class="select-text leading-relaxed text-gray-800 dark:text-gray-100"
                    :class="expanded ? '' : 'max-h-48 overflow-y-auto'"
                >
                    {{ e($excerpt) }}
                </div>
                <button
                    type="button"
                    class="mt-2 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                    x-on:click="expanded = !expanded"
                    x-text="expanded
                        ? @js(__('seo-content-ai::filament.projects.archive_preview_excerpt_collapse'))
                        : @js(__('seo-content-ai::filament.projects.archive_preview_excerpt_expand'))"
                ></button>
            </div>
        @else
            <p class="text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_no_data') }}</p>
        @endif
    </section>
</div>
