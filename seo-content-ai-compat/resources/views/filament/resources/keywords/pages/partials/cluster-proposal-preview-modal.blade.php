@if ($this->showClusterProposalPreview)
    <div
        wire:key="cluster-proposal-preview-modal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/40 p-4"
        x-data
        x-on:keydown.escape.window="$wire.closeClusterProposalPreview()"
    >
        <div class="flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl bg-white shadow-xl dark:bg-gray-900" @click.stop>
            <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                <div>
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                        {{ __('seo-content-ai::filament.keyword.topic_proposal_title') }}
                    </h3>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('seo-content-ai::filament.keyword.topic_proposal_preview_only') }}
                    </p>
                </div>
                <button
                    type="button"
                    class="text-sm text-gray-500 hover:text-gray-800 dark:hover:text-gray-200"
                    wire:click="closeClusterProposalPreview"
                >
                    {{ __('seo-content-ai::filament.keyword.topic_proposal_close') }}
                </button>
            </div>

            <div class="flex-1 overflow-y-auto px-5 py-4">
                <div class="mb-4 flex flex-wrap items-end gap-3">
                    <label class="text-sm">
                        <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.keyword.topic_proposal_strategy_label') }}
                        </span>
                        <x-select size="sm" wire:model.live="clusterProposalStrategy" wire:loading.attr="disabled" wire:target="refreshClusterProposalPreview,updatedClusterProposalStrategy">
                            <option value="strict">{{ __('seo-content-ai::filament.keyword.topic_proposal_strategy_strict') }}</option>
                            <option value="balanced">{{ __('seo-content-ai::filament.keyword.topic_proposal_strategy_balanced') }}</option>
                            <option value="broad">{{ __('seo-content-ai::filament.keyword.topic_proposal_strategy_broad') }}</option>
                        </x-select>
                    </label>
                    <x-filament::button
                        type="button"
                        size="sm"
                        color="gray"
                        wire:click="refreshClusterProposalPreview"
                        wire:loading.attr="disabled"
                        wire:target="refreshClusterProposalPreview,updatedClusterProposalStrategy"
                    >
                        <span wire:loading.remove wire:target="refreshClusterProposalPreview,updatedClusterProposalStrategy">
                            {{ __('seo-content-ai::filament.keyword.topic_proposal_refresh') }}
                        </span>
                        <span wire:loading wire:target="refreshClusterProposalPreview,updatedClusterProposalStrategy">
                            {{ __('seo-content-ai::filament.keyword.topic_proposal_working') }}
                        </span>
                    </x-filament::button>
                </div>

                @php
                    $preview = $this->clusterProposalPreview;
                @endphp

                @if (! is_array($preview))
                    <p class="text-sm text-gray-500">{{ __('seo-content-ai::filament.keyword.topic_proposal_empty') }}</p>
                @else
                    @php
                        $diag = is_array($preview['diagnostics'] ?? null) ? $preview['diagnostics'] : [];
                    @endphp
                    <div class="mb-4 grid gap-2 sm:grid-cols-2">
                        <div class="rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700">
                            <div class="text-xs text-gray-500">{{ __('seo-content-ai::filament.keyword.topic_proposal_stat_protected_clusters') }}</div>
                            <div class="font-semibold">{{ number_format((int) ($preview['protected_cluster_count'] ?? 0)) }}</div>
                        </div>
                        <div class="rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700">
                            <div class="text-xs text-gray-500">{{ __('seo-content-ai::filament.keyword.topic_proposal_stat_candidates') }}</div>
                            <div class="font-semibold">{{ number_format((int) ($preview['candidate_count'] ?? 0)) }}</div>
                        </div>
                        <div class="rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700">
                            <div class="text-xs text-gray-500">{{ __('seo-content-ai::filament.keyword.topic_proposal_stat_proposed_clusters') }}</div>
                            <div class="font-semibold">{{ number_format((int) ($preview['proposed_cluster_count'] ?? 0)) }}</div>
                        </div>
                        <div class="rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700">
                            <div class="text-xs text-gray-500">{{ __('seo-content-ai::filament.keyword.topic_proposal_stat_proposed_keywords') }}</div>
                            <div class="font-semibold">{{ number_format((int) ($preview['proposed_keyword_count'] ?? 0)) }}</div>
                        </div>
                        <div class="rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700 sm:col-span-2">
                            <div class="text-xs text-gray-500">{{ __('seo-content-ai::filament.keyword.topic_proposal_stat_unclustered') }}</div>
                            <div class="font-semibold">{{ number_format((int) ($preview['remain_unclustered'] ?? 0)) }}</div>
                        </div>
                        @if ((int) ($diag['initial_cluster_count'] ?? 0) > 0)
                            <div class="rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700">
                                <div class="text-xs text-gray-500">{{ __('seo-content-ai::filament.keyword.topic_proposal_stat_initial_clusters') }}</div>
                                <div class="font-semibold">{{ number_format((int) ($diag['initial_cluster_count'] ?? 0)) }}</div>
                            </div>
                            <div class="rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700">
                                <div class="text-xs text-gray-500">{{ __('seo-content-ai::filament.keyword.topic_proposal_stat_loose_clusters') }}</div>
                                <div class="font-semibold">{{ number_format((int) ($diag['loose_clusters_detected'] ?? 0)) }}</div>
                            </div>
                            <div class="rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700">
                                <div class="text-xs text-gray-500">{{ __('seo-content-ai::filament.keyword.topic_proposal_stat_splits') }}</div>
                                <div class="font-semibold">{{ number_format((int) ($diag['clusters_split_count'] ?? 0)) }}</div>
                            </div>
                            <div class="rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700">
                                <div class="text-xs text-gray-500">{{ __('seo-content-ai::filament.keyword.topic_proposal_stat_rehomed') }}</div>
                                <div class="font-semibold">{{ number_format((int) ($diag['subgroups_rehomed'] ?? 0)) }}</div>
                            </div>
                            <div class="rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700">
                                <div class="text-xs text-gray-500">Competitive moves</div>
                                <div class="font-semibold">{{ number_format((int) ($diag['competitive_moves'] ?? 0)) }}</div>
                            </div>
                            <div class="rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700">
                                <div class="text-xs text-gray-500">Strong duplicates merged</div>
                                <div class="font-semibold">{{ number_format((int) ($diag['strong_duplicate_merges'] ?? 0)) }}</div>
                            </div>
                            <div class="rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700">
                                <div class="text-xs text-gray-500">{{ __('seo-content-ai::filament.keyword.topic_proposal_stat_released') }}</div>
                                <div class="font-semibold">{{ number_format((int) ($diag['members_released'] ?? 0)) }}</div>
                            </div>
                            <div class="rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700">
                                <div class="text-xs text-gray-500">{{ __('seo-content-ai::filament.keyword.topic_proposal_stat_ready') }}</div>
                                <div class="font-semibold">{{ number_format((int) ($diag['ready_proposal_count'] ?? 0)) }}</div>
                            </div>
                            <div class="rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700">
                                <div class="text-xs text-gray-500">{{ __('seo-content-ai::filament.keyword.topic_proposal_stat_needs_review') }}</div>
                                <div class="font-semibold">{{ number_format((int) ($diag['needs_review_proposal_count'] ?? 0)) }}</div>
                            </div>
                        @endif
                    </div>

                    @if ($diag !== [])
                        <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.keyword.topic_summary_quality_line', [
                                'unclassified' => number_format((int) ($diag['unclassified_keywords'] ?? 0)),
                                'non_seo' => number_format((int) ($diag['non_seo_keywords'] ?? 0)),
                            ]) }}
                        </p>
                    @endif

                    <p class="mb-3 text-xs text-gray-500">
                        Strategy: {{ $preview['strategy_label'] ?? $preview['strategy'] ?? 'balanced' }}
                    </p>

                    @php
                        $clusters = is_array($preview['proposed_clusters'] ?? null) ? $preview['proposed_clusters'] : [];
                        $batchCounts = $this->getBatchPreviewCounts();
                    @endphp

                    @if ($this->canApplyProposal() && $batchCounts['ready'] > 0)
                        <div class="mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 px-3 py-3 dark:border-gray-700">
                            @if ($this->getSelectedReadyProposalCount() > 0)
                                <span class="text-sm text-gray-600 dark:text-gray-300">
                                    {{ trans_choice('seo-content-ai::filament.keyword.topic_batch_selected_count', $this->getSelectedReadyProposalCount(), ['count' => number_format($this->getSelectedReadyProposalCount())]) }}
                                </span>
                                <x-filament::button
                                    type="button"
                                    size="sm"
                                    wire:click="openBatchApplySelectedConfirm"
                                    wire:loading.attr="disabled"
                                    wire:target="confirmBatchApply,confirmApplyProposal,openBatchApplySelectedConfirm,openBatchApplyAllReadyConfirm,toggleReadyProposalSelection"
                                >
                                    {{ __('seo-content-ai::filament.keyword.topic_batch_apply_selected_action') }}
                                </x-filament::button>
                            @endif
                            <x-filament::button
                                type="button"
                                size="sm"
                                color="gray"
                                wire:click="openBatchApplyAllReadyConfirm"
                                wire:loading.attr="disabled"
                                wire:target="confirmBatchApply,confirmApplyProposal,openBatchApplySelectedConfirm,openBatchApplyAllReadyConfirm,toggleReadyProposalSelection"
                            >
                                {{ __('seo-content-ai::filament.keyword.topic_batch_apply_all_ready_action', ['count' => number_format($batchCounts['ready'])]) }}
                            </x-filament::button>
                        </div>
                    @endif

                    @if ($clusters === [])
                        <p class="mb-4 text-sm text-gray-500">{{ __('seo-content-ai::filament.keyword.topic_proposal_empty') }}</p>
                    @else
                        <div class="space-y-3">
                            @foreach ($clusters as $cluster)
                                @php
                                    $isReady = ($cluster['final_status'] ?? '') === 'READY';
                                    $proposalFingerprint = (string) ($cluster['proposal_fingerprint'] ?? '');
                                    $isSelected = $isReady && $proposalFingerprint !== '' && in_array($proposalFingerprint, $this->selectedReadyProposalFingerprints, true);
                                @endphp
                                <details class="rounded-lg border border-gray-200 p-3 dark:border-gray-700" @if ($loop->index < 5) open @endif>
                                    <summary class="cursor-pointer list-none">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div class="flex min-w-0 items-start gap-2">
                                                @if ($this->canApplyProposal() && $isReady && $proposalFingerprint !== '')
                                                    <input
                                                        type="checkbox"
                                                        class="mt-1 rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600"
                                                        wire:click.stop="toggleReadyProposalSelection(@js($proposalFingerprint))"
                                                        @checked($isSelected)
                                                        wire:loading.attr="disabled"
                                                        wire:target="confirmBatchApply,confirmApplyProposal,openBatchApplySelectedConfirm,openBatchApplyAllReadyConfirm,toggleReadyProposalSelection"
                                                    >
                                                @endif
                                                <span class="font-medium text-gray-900 dark:text-gray-100">
                                                    {{ $cluster['representative_label'] ?? '—' }}
                                                </span>
                                            </div>
                                            <span class="text-xs text-gray-500">
                                                {{ __('seo-content-ai::filament.keyword.topic_proposal_members', ['count' => number_format((int) ($cluster['member_count'] ?? 0))]) }}
                                                · {{ $cluster['cohesion_label'] ?? '' }}
                                                @if (! empty($cluster['final_status_label']))
                                                    · {{ $cluster['final_status_label'] }}
                                                @endif
                                                @if (app()->environment('local'))
                                                    ({{ number_format((float) ($cluster['cohesion'] ?? 0), 2) }})
                                                @endif
                                            </span>
                                        </div>
                                        @if (! empty($cluster['core_member_count']) || ! empty($cluster['borderline_member_count']))
                                            <p class="mt-1 text-xs text-gray-400">
                                                {{ __('seo-content-ai::filament.keyword.topic_proposal_core_borderline', [
                                                    'core' => number_format((int) ($cluster['core_member_count'] ?? 0)),
                                                    'borderline' => number_format((int) ($cluster['borderline_member_count'] ?? 0)),
                                                ]) }}
                                            </p>
                                        @endif
                                        @if (! empty($cluster['split_from_label']) && app()->environment('local'))
                                            <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                                                {{ __('seo-content-ai::filament.keyword.topic_proposal_split_from', ['label' => $cluster['split_from_label']]) }}
                                            </p>
                                        @endif
                                        @if (! empty($cluster['rehome_note']) && app()->environment('local'))
                                            <p class="mt-1 text-xs text-emerald-600 dark:text-emerald-400">{{ $cluster['rehome_note'] }}</p>
                                        @endif
                                    </summary>
                                    <ul class="mt-2 space-y-1 border-t border-gray-100 pt-2 text-sm dark:border-gray-800">
                                        @foreach (($cluster['members'] ?? []) as $member)
                                            <li class="text-gray-700 dark:text-gray-200">
                                                {{ $member['phrase'] ?? '' }}
                                                @if (! empty($member['seo_intent']))
                                                    <span class="text-xs capitalize text-gray-400">({{ $member['seo_intent'] }})</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                    @if ($this->canApplyProposal() && ! empty($cluster['proposal_fingerprint']))
                                        <div class="mt-3 border-t border-gray-100 pt-3 dark:border-gray-800">
                                            <x-filament::button
                                                type="button"
                                                size="sm"
                                                wire:click="openApplyProposalConfirm(@js($cluster))"
                                                wire:loading.attr="disabled"
                                                wire:target="confirmApplyProposal,confirmBatchApply,openApplyProposalConfirm,openBatchApplySelectedConfirm,openBatchApplyAllReadyConfirm,toggleReadyProposalSelection"
                                            >
                                                {{ __('seo-content-ai::filament.keyword.topic_apply_action') }}
                                            </x-filament::button>
                                        </div>
                                    @endif
                                </details>
                            @endforeach
                        </div>
                    @endif

                    @php
                        $outliers = $this->getFilteredClusterProposalOutliers();
                        $outlierTotal = is_array($preview['unclustered'] ?? null) ? count($preview['unclustered']) : 0;
                    @endphp

                    @if ($outlierTotal > 0)
                        <div class="mt-6 border-t border-gray-200 pt-4 dark:border-gray-700">
                            <h4 class="mb-2 text-sm font-semibold">
                                {{ __('seo-content-ai::filament.keyword.topic_proposal_outliers_title') }} — {{ number_format($outlierTotal) }}
                            </h4>
                            <input
                                type="search"
                                wire:model.live.debounce.300ms="clusterProposalOutlierSearch"
                                class="topic-index-input mb-3 w-full"
                                placeholder="{{ __('seo-content-ai::filament.keyword.topic_proposal_outliers_search') }}"
                            >
                            <ul class="max-h-48 space-y-1 overflow-y-auto text-sm text-gray-600 dark:text-gray-300">
                                @forelse ($outliers as $outlier)
                                    <li>{{ $outlier['phrase'] ?? '' }}</li>
                                @empty
                                    <li class="text-gray-400">—</li>
                                @endforelse
                            </ul>
                        </div>
                    @endif
                @endif
            </div>
        </div>

        @if ($this->batchApplyMode)
            <div class="fixed inset-0 z-[60] flex items-center justify-center bg-gray-950/50 p-4">
                <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl dark:bg-gray-900">
                    @if ($this->batchApplyMode === 'all_ready')
                        <h4 class="text-base font-semibold text-gray-950 dark:text-white">
                            {{ __('seo-content-ai::filament.keyword.topic_batch_apply_all_confirm_title') }}
                        </h4>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                            {{ trans_choice('seo-content-ai::filament.keyword.topic_batch_apply_all_confirm_body', $this->batchApplyKeywordCount, [
                                'ready' => number_format($this->batchApplyReadyCount),
                                'count' => number_format($this->batchApplyKeywordCount),
                                'skipped' => number_format($this->batchApplyNeedsReviewCount),
                            ]) }}
                        </p>
                    @else
                        <h4 class="text-base font-semibold text-gray-950 dark:text-white">
                            {{ __('seo-content-ai::filament.keyword.topic_batch_apply_selected_confirm_title', [
                                'count' => number_format($this->batchApplyProposalCount),
                            ]) }}
                        </h4>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                            {{ trans_choice('seo-content-ai::filament.keyword.topic_batch_apply_selected_confirm_body', $this->batchApplyKeywordCount, [
                                'clusters' => number_format($this->batchApplyProposalCount),
                                'count' => number_format($this->batchApplyKeywordCount),
                            ]) }}
                        </p>
                    @endif

                    <div class="mt-4 flex justify-end gap-2">
                        <x-filament::button type="button" color="gray" wire:click="cancelBatchApplyConfirm">
                            {{ __('seo-content-ai::filament.keyword.topic_proposal_close') }}
                        </x-filament::button>
                        <x-filament::button
                            type="button"
                            wire:click="confirmBatchApply"
                            wire:loading.attr="disabled"
                            wire:target="confirmBatchApply"
                        >
                            <span wire:loading.remove wire:target="confirmBatchApply">
                                @if ($this->batchApplyMode === 'all_ready')
                                    {{ __('seo-content-ai::filament.keyword.topic_batch_apply_all_confirm_action', [
                                        'count' => number_format($this->batchApplyProposalCount),
                                    ]) }}
                                @else
                                    {{ __('seo-content-ai::filament.keyword.topic_batch_apply_selected_confirm_action', [
                                        'count' => number_format($this->batchApplyProposalCount),
                                    ]) }}
                                @endif
                            </span>
                            <span wire:loading wire:target="confirmBatchApply">
                                {{ __('seo-content-ai::filament.keyword.topic_proposal_working') }}
                            </span>
                        </x-filament::button>
                    </div>
                </div>
            </div>
        @elseif ($this->applyProposalFingerprint)
            <div class="fixed inset-0 z-[60] flex items-center justify-center bg-gray-950/50 p-4">
                <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl dark:bg-gray-900">
                    @if ($this->applyFinalStatus === 'NEEDS_REVIEW')
                        <h4 class="text-base font-semibold text-amber-700 dark:text-amber-400">
                            {{ __('seo-content-ai::filament.keyword.topic_apply_needs_review_title') }}
                        </h4>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                            {{ __('seo-content-ai::filament.keyword.topic_apply_needs_review_body') }}
                        </p>
                    @else
                        <h4 class="text-base font-semibold text-gray-950 dark:text-white">
                            {{ __('seo-content-ai::filament.keyword.topic_apply_confirm_title', ['label' => $this->applyRepresentativeLabel]) }}
                        </h4>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                            {{ trans_choice('seo-content-ai::filament.keyword.topic_apply_confirm_body', $this->applyMemberCount, ['count' => number_format($this->applyMemberCount)]) }}
                        </p>
                    @endif

                    <div class="mt-4 flex justify-end gap-2">
                        <x-filament::button type="button" color="gray" wire:click="cancelApplyProposalConfirm">
                            {{ __('seo-content-ai::filament.keyword.topic_proposal_close') }}
                        </x-filament::button>
                        <x-filament::button
                            type="button"
                            wire:click="confirmApplyProposal"
                            wire:loading.attr="disabled"
                            wire:target="confirmApplyProposal"
                        >
                            <span wire:loading.remove wire:target="confirmApplyProposal">
                                @if ($this->applyFinalStatus === 'NEEDS_REVIEW')
                                    {{ __('seo-content-ai::filament.keyword.topic_apply_needs_review_action') }}
                                @else
                                    {{ __('seo-content-ai::filament.keyword.topic_apply_action') }}
                                @endif
                            </span>
                            <span wire:loading wire:target="confirmApplyProposal">
                                {{ __('seo-content-ai::filament.keyword.topic_proposal_working') }}
                            </span>
                        </x-filament::button>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endif
