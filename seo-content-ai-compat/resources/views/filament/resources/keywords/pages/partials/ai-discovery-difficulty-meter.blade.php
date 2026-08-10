@php
    $difficulty = strtolower(trim($difficulty ?? 'medium'));
    $bars = match ($difficulty) {
        'easy' => 1,
        'hard' => 3,
        default => 2,
    };
    $label = match ($difficulty) {
        'easy' => __('seo-content-ai::filament.keyword.discovery_difficulty_easy'),
        'hard' => __('seo-content-ai::filament.keyword.discovery_difficulty_hard'),
        default => __('seo-content-ai::filament.keyword.discovery_difficulty_medium'),
    };
@endphp

<div class="inline-flex items-center gap-2">
    <div class="flex items-end gap-0.5" aria-hidden="true">
        @for ($i = 1; $i <= 3; $i++)
            <span @class([
                'ai-discovery-meter-bar',
                'ai-discovery-meter-bar--active-easy' => $bars >= $i && $difficulty === 'easy',
                'ai-discovery-meter-bar--active-medium' => $bars >= $i && $difficulty === 'medium',
                'ai-discovery-meter-bar--active-hard' => $bars >= $i && $difficulty === 'hard',
            ])></span>
        @endfor
    </div>
    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ $label }}</span>
</div>
