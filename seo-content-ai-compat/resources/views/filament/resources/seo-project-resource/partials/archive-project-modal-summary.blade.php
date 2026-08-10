@php
    /** @var array<string, mixed> $summary */
    $summary = is_array($summary ?? null) ? $summary : [];
    /** @var array<string, mixed> $gate */
    $gate = is_array($gate ?? null) ? $gate : [];

    $domainName = trim((string) ($summary['domain_name'] ?? ''));
    $month = $summary['month'] ?? null;
    $year = $summary['year'] ?? null;
    $period = ($month !== null && $year !== null)
        ? sprintf('%02d/%s', (int) $month, (int) $year)
        : '—';

    $total = (int) ($summary['total_articles'] ?? 0);
    $completed = (int) ($summary['completed_articles'] ?? 0);
    $approved = (int) ($summary['approved_articles'] ?? 0);
    $synced = (int) ($summary['synced_articles'] ?? 0);
    $incomplete = (int) ($summary['incomplete_articles'] ?? max(0, $total - $completed));
    $unapproved = (int) ($summary['unapproved_articles'] ?? max(0, $total - $approved));
    $unsynced = (int) ($summary['unsynced_articles'] ?? max(0, $total - $synced));
    $failed = (int) ($summary['failed_articles'] ?? 0);

    $showWarning = $unapproved > 0 || $unsynced > 0 || $failed > 0;
@endphp

<div class="prose prose-sm max-w-none dark:prose-invert">
    <ul class="my-0 list-disc space-y-1 pl-5">
        <li><strong>{{ __('seo-content-ai::filament.projects.archive_modal_domain') }}:</strong> {{ $domainName !== '' ? e($domainName) : '—' }}</li>
        <li><strong>{{ __('seo-content-ai::filament.projects.archive_modal_period') }}:</strong> {{ e($period) }}</li>
        <li><strong>{{ __('seo-content-ai::filament.projects.archive_col_total') }}:</strong> {{ $total }}</li>
        <li><strong>{{ __('seo-content-ai::filament.projects.archive_col_completed') }}:</strong> {{ $completed }}</li>
        <li><strong>{{ __('seo-content-ai::filament.projects.archive_col_synced') }}:</strong> {{ $synced }}</li>
        <li><strong>{{ __('seo-content-ai::filament.projects.archive_modal_incomplete') }}:</strong> {{ $incomplete }}</li>
        <li><strong>{{ __('seo-content-ai::filament.projects.archive_modal_unapproved') }}:</strong> {{ $unapproved }}</li>
        <li><strong>{{ __('seo-content-ai::filament.projects.archive_modal_unsynced') }}:</strong> {{ $unsynced }}</li>
        <li><strong>{{ __('seo-content-ai::filament.projects.archive_modal_failed') }}:</strong> {{ $failed }}</li>
    </ul>

    @if (! empty($gate['blocked_reason']))
        <div class="mt-4 rounded-lg border border-danger-300 bg-danger-50 px-3 py-2 text-sm text-danger-900 not-prose dark:border-danger-500/40 dark:bg-danger-500/10 dark:text-danger-100">
            {{ e((string) $gate['blocked_reason']) }}
        </div>
    @elseif (! empty($gate['requires_waiting_publish_confirm']))
        <div class="mt-4 rounded-lg border border-warning-300 bg-warning-50 px-3 py-2 text-sm text-warning-900 not-prose dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-100">
            {{ __('seo-content-ai::filament.projects.archive_waiting_publish_notice', ['count' => (int) ($gate['waiting_publish'] ?? 0)]) }}
        </div>
    @endif

    @if ($showWarning)
        <div class="mt-4 rounded-lg border border-warning-300 bg-warning-50 px-3 py-2 text-sm text-warning-900 not-prose dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-100">
            {{ __('seo-content-ai::filament.projects.archive_modal_warning') }}
        </div>
    @endif
</div>
