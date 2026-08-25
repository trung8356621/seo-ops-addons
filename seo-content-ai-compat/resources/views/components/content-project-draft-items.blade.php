@props([
    'items' => [],
    'hasDraft' => false,
])

@php
    /** @var list<array<string, mixed>> $items */
    $count = count($items);
@endphp

<section class="cp-plan-draft" data-content-planning-draft-items="1" wire:key="cp-draft-items">
    <div class="cp-plan-draft__head">
        <h3 class="cp-plan-draft__title">
            {{ __('seo-content-ai::filament.projects.planner_draft_items') }}
        </h3>
        @if ($hasDraft)
            <span class="cp-plan-draft__badge">{{ $count }}</span>
        @endif
    </div>

    <div class="cp-plan-draft__body">
        @if (! $hasDraft)
            <div class="cp-plan-draft__empty text-sm text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.projects.content_planning_create_draft_first') }}
            </div>
        @elseif ($count === 0)
            <div class="cp-plan-draft__empty" data-draft-empty="1">
                <p class="text-sm font-medium text-gray-800 dark:text-gray-100">
                    {{ __('seo-content-ai::filament.projects.content_planning_draft_empty_title') }}
                </p>
                <p class="mt-1 text-xs text-gray-500">
                    {{ __('seo-content-ai::filament.projects.content_planning_draft_empty_body') }}
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" class="cp-plan-btn cp-plan-btn--improve" style="width:auto;flex:0 0 auto;" wire:click="fillSuggestions">
                        {{ __('seo-content-ai::filament.projects.planner_fill_from_seo_audit') }}
                    </button>
                    <button type="button" class="cp-plan-btn cp-plan-btn--create" style="width:auto;flex:0 0 auto;" wire:click="generateNewContentSuggestions">
                        {{ __('seo-content-ai::filament.projects.planner_generate_with_ai') }}
                    </button>
                </div>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                    <thead class="bg-gray-50 text-left text-xs font-medium text-gray-500 dark:bg-gray-950/50 dark:text-gray-400">
                        <tr>
                            <th class="w-10 px-4 py-2.5"></th>
                            <th class="px-3 py-2.5">{{ __('seo-content-ai::filament.projects.suggestions_col_article') }}</th>
                            <th class="px-3 py-2.5">{{ __('seo-content-ai::filament.projects.suggestions_col_action') }}</th>
                            <th class="px-3 py-2.5">{{ __('seo-content-ai::filament.projects.content_planning_col_source') }}</th>
                            <th class="px-3 py-2.5">{{ __('seo-content-ai::filament.projects.suggestions_col_state') }}</th>
                            <th class="px-3 py-2.5">{{ __('seo-content-ai::filament.projects.content_planning_col_updated') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($items as $row)
                            @php
                                $type = (string) ($row['type'] ?? '');
                                $typeClass = match ($type) {
                                    'rewrite' => 'text-emerald-700 dark:text-emerald-300',
                                    'improve' => 'text-amber-700 dark:text-amber-300',
                                    'create' => 'text-sky-700 dark:text-sky-300',
                                    default => 'text-gray-700 dark:text-gray-300',
                                };
                            @endphp
                            <tr wire:key="cp-draft-item-{{ $row['id'] }}">
                                <td class="px-4 py-3 align-top">
                                    <input
                                        type="checkbox"
                                        class="rounded border-gray-300 text-primary-600"
                                        value="{{ (int) $row['id'] }}"
                                        wire:model.live="selectedTaskIds"
                                    >
                                </td>
                                <td class="px-3 py-3 align-top">
                                    <div class="font-medium text-gray-900 dark:text-gray-100">{{ $row['title'] }}</div>
                                    @if (! empty($row['why']))
                                        <div class="mt-0.5 text-xs text-gray-500">{{ $row['why'] }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-3 align-top">
                                    <span @class(['text-xs font-semibold', $typeClass])>{{ $row['type_label'] }}</span>
                                </td>
                                <td class="px-3 py-3 align-top text-xs text-gray-600 dark:text-gray-300">{{ $row['source_label'] }}</td>
                                <td class="px-3 py-3 align-top">
                                    <span class="inline-flex items-center gap-1.5 text-xs text-emerald-700 dark:text-emerald-300">
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                        {{ __('seo-content-ai::filament.projects.content_planning_state_ready') }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 align-top text-xs text-gray-500">{{ $row['updated_label'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</section>
