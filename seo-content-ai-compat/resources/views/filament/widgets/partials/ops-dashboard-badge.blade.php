@php
    $status = (string) ($status ?? '');
    [$label, $class] = match ($status) {
        'reviewed' => [__('seo-content-ai::filament.dashboard.ops_badge_reviewed'), 'ops-landing-badge ops-landing-badge--reviewed'],
        'scheduled' => [__('seo-content-ai::filament.dashboard.ops_badge_scheduled'), 'ops-landing-badge ops-landing-badge--scheduled'],
        'waiting_publish' => [__('seo-content-ai::filament.dashboard.ops_badge_waiting_publish'), 'ops-landing-badge ops-landing-badge--waiting'],
        default => [__('seo-content-ai::filament.dashboard.ops_badge_pending_review'), 'ops-landing-badge ops-landing-badge--pending'],
    };
@endphp

<span class="{{ $class }}">{{ $label }}</span>
