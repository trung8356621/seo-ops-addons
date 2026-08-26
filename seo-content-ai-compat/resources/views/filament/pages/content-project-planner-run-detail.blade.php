@php
    /** @var \Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectPlannerRunDetail $this */
    $detail = $this->detailPayload();
    $contentType = (string) ($detail['content_type'] ?? 'post');
    $contentTypeLabel = $contentType === 'product'
        ? (string) __('seo-content-ai::filament.projects.planner_content_type_product')
        : (string) __('seo-content-ai::filament.projects.planner_content_type_post');
    $notes = trim((string) ($detail['notes'] ?? ''));
    $language = trim((string) ($detail['language'] ?? ''));
@endphp

<x-filament-panels::page>
    <x-seo-content-ai::content-project-ops-styles />

    <div class="space-y-6" data-planner-run-detail="1">
        <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-white/10 dark:bg-gray-900">
            <dl class="grid gap-2 sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-gray-500">{{ __('seo-content-ai::filament.projects.planner_run_source') }}</dt>
                    <dd>{{ __('seo-content-ai::filament.projects.planner_run_source_ai_new_content') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">{{ __('seo-content-ai::filament.projects.planner_content_type') }}</dt>
                    <dd>{{ $contentTypeLabel }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">{{ __('seo-content-ai::filament.projects.planner_run_summary_counts') }}</dt>
                    <dd>
                        {{ __('seo-content-ai::filament.projects.planner_run_summary_line', [
                            'requested' => (int) ($detail['requested'] ?? 0),
                            'added' => (int) ($detail['added'] ?? 0),
                            'duplicates' => (int) ($detail['duplicates'] ?? 0),
                            'rejected' => (int) ($detail['rejected'] ?? 0),
                            'invalid' => (int) ($detail['invalid'] ?? 0),
                        ]) }}
                    </dd>
                </div>
                @if ($language !== '')
                    <div>
                        <dt class="text-xs text-gray-500">{{ __('seo-content-ai::filament.projects.planner_run_language') }}</dt>
                        <dd>{{ strtoupper($language) }}</dd>
                    </div>
                @endif
                <div class="sm:col-span-2">
                    <dt class="text-xs text-gray-500">{{ __('seo-content-ai::filament.projects.planner_notes') }}</dt>
                    <dd>{{ $notes !== '' ? $notes : __('seo-content-ai::filament.projects.planner_notes_empty') }}</dd>
                </div>
            </dl>
        </div>

        <div class="space-y-3" data-planner-run-candidates="1">
            @forelse (($detail['candidates'] ?? []) as $candidate)
                <div class="rounded-lg border border-gray-200 px-4 py-3 text-sm dark:border-white/10">
                    <div class="font-medium">{{ $candidate['title'] ?? '' }}</div>
                    <div class="mt-1 text-xs text-gray-500">
                        {{ __('seo-content-ai::filament.projects.planner_keyword_label') }}:
                        {{ $candidate['keyword'] ?? '' }}
                    </div>
                    @if (! empty($candidate['suggestion_reason']))
                        <div class="mt-1 text-xs text-gray-500">
                            {{ __('seo-content-ai::filament.projects.planner_why') }}:
                            {{ $candidate['suggestion_reason'] }}
                        </div>
                    @endif
                    @if (! empty($candidate['source_signal']))
                        <div class="mt-1 text-xs text-gray-500">
                            {{ __('seo-content-ai::filament.projects.planner_source_signal') }}:
                            {{ $candidate['source_signal'] }}
                        </div>
                    @endif
                    <div class="mt-1 text-xs font-medium text-gray-700 dark:text-gray-200">
                        {{ __('seo-content-ai::filament.projects.planner_decision') }}:
                        {{ $candidate['decision_label'] ?? ($candidate['status'] ?? '') }}
                    </div>
                    @if (! empty($candidate['can_restore']) && ! empty($candidate['fingerprint']))
                        <button
                            type="button"
                            class="mt-2 text-xs text-primary-600 hover:underline"
                            wire:click="restoreFingerprint('{{ e((string) $candidate['fingerprint']) }}')"
                            wire:loading.attr="disabled"
                        >
                            {{ __('seo-content-ai::filament.projects.suggestions_restore') }}
                        </button>
                    @endif
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ __('seo-content-ai::filament.projects.planner_run_no_candidates') }}</p>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
