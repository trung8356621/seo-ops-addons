@php
    $status = (string) ($status ?? '');
    [$label, $class] = match ($status) {
        'reviewed' => [__('seo-content-ai::filament.dashboard.ops_badge_reviewed'), 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'],
        'scheduled' => [__('seo-content-ai::filament.dashboard.ops_badge_scheduled'), 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300'],
        'waiting_publish' => [__('seo-content-ai::filament.dashboard.ops_badge_waiting_publish'), 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'],
        default => [__('seo-content-ai::filament.dashboard.ops_badge_pending_review'), 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'],
    };
@endphp

<span class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium {{ $class }}">{{ $label }}</span>
