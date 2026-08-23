@props(['row'])

@php
    $tid = (int) ($row['task_id'] ?? 0);
    $canEdit = ! empty($row['can_edit_keyword_override']);
    $hasOverride = ! empty($row['has_keyword_override']);
    $original = (string) ($row['keyword_original'] ?? '—');
    $effective = (string) ($row['keyword_effective'] ?? $row['keyword'] ?? '—');
    if ($effective === '—' && $original !== '—') {
        $effective = $original;
    }
    $dirty = ! empty($row['generation_keyword_dirty']);
    $initialValue = $effective !== '—' ? $effective : $original;
@endphp

@if (! $canEdit)
    <div class="cp-ops-kw-cell">
        @include('seo-content-ai::components.partials.content-project-keyword-display', [
            'original' => $original,
            'effective' => $effective,
            'hasOverride' => $hasOverride,
            'dirty' => $dirty,
        ])
    </div>
@else
    <div
        class="cp-ops-kw-cell cp-ops-kw-cell--editable"
        wire:key="kw-cell-{{ $tid }}"
        x-data="{
            editing: false,
            value: @js($initialValue),
            saving: false,
            startEdit() {
                if (this.saving) return;
                this.editing = true;
                this.$nextTick(() => { this.$refs.kwInput?.focus(); this.$refs.kwInput?.select(); });
            },
            cancelEdit() {
                this.editing = false;
                this.value = @js($initialValue);
            },
            async saveEdit() {
                const trimmed = String(this.value || '').trim();
                if (trimmed === '') {
                    this.cancelEdit();
                    return;
                }
                if (this.saving) return;
                this.saving = true;
                try {
                    const res = await $wire.saveGenerationKeywordOverride({{ $tid }}, trimmed);
                    if (res?.ok) {
                        this.editing = false;
                    }
                } finally {
                    this.saving = false;
                }
            },
            async revertOverride() {
                if (this.saving) return;
                this.saving = true;
                try {
                    await $wire.revertGenerationKeywordOverride({{ $tid }});
                } finally {
                    this.saving = false;
                }
            },
        }"
        @dblclick.stop="startEdit()"
        @click.outside="if (editing && !saving) { saveEdit() }"
    >
        <div x-show="!editing" class="cp-ops-kw-display">
            @include('seo-content-ai::components.partials.content-project-keyword-display', [
                'original' => $original,
                'effective' => $effective,
                'hasOverride' => $hasOverride,
                'dirty' => $dirty,
            ])
            @if ($hasOverride)
                <button
                    type="button"
                    class="cp-ops-kw-revert"
                    @click.stop="revertOverride()"
                    title="{{ __('seo-content-ai::filament.projects.keyword_override_revert') }}"
                >{{ __('seo-content-ai::filament.projects.keyword_override_revert') }}</button>
            @endif
        </div>
        <div x-show="editing" x-cloak class="cp-ops-kw-edit">
            <input
                x-ref="kwInput"
                type="text"
                class="cp-ops-kw-input"
                x-model="value"
                :disabled="saving"
                @keydown.enter.prevent="saveEdit()"
                @keydown.escape.prevent="cancelEdit()"
                aria-label="{{ __('seo-content-ai::filament.projects.keyword_override_edit_label') }}"
            />
        </div>
    </div>
@endif
