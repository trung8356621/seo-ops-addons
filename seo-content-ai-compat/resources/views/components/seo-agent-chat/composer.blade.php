@props([
    'placeholder' => 'Nhập / để xem lệnh hoặc mô tả việc cần làm…',
    'disabled' => false,
])

@php
    // Alpine needs a JS boolean literal — Blade $disabled is not an Alpine variable.
    $lockedLiteral = filter_var($disabled, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
@endphp

<footer {{ $attributes->merge(['class' => 'seo-global-chat__composer seo-agent-chat__composer']) }}>
    {{ $above ?? '' }}

    {{-- Single submit owner: Alpine submitAgentComposer() calls Livewire sendMessage. No Livewire model binding on textarea. --}}
    <form
        x-ref="agentComposerForm"
        method="post"
        x-on:submit.prevent="submitAgentComposer()"
        class="seo-global-chat__composer-row seo-agent-chat__composer-row"
        wire:loading.class="pointer-events-none opacity-60"
        wire:target="selectTemplate"
    >
        <textarea
            id="seo-agent-composer-input"
            x-model="composer"
            rows="1"
            class="seo-global-chat__input seo-agent-workspace__composer-input"
            placeholder="{{ $placeholder }}"
            autocomplete="off"
            @disabled($disabled)
            x-bind:disabled="composerSubmitting || !!$wire.composerSubmitting || {{ $lockedLiteral }}"
            x-on:input="onComposerInput($event)"
            x-on:keydown.arrow-down.prevent="onComposerArrow(1)"
            x-on:keydown.arrow-up.prevent="onComposerArrow(-1)"
            x-on:keydown.escape.prevent="closePalette()"
            x-on:keydown.tab="onComposerTab($event)"
            x-on:keydown.enter="onComposerEnter($event)"
        ></textarea>
        <button
            type="submit"
            class="seo-global-chat__send"
            x-bind:disabled="composerSubmitting || !!$wire.composerSubmitting || {{ $lockedLiteral }}"
            wire:loading.attr="disabled"
            wire:target="selectTemplate"
            @disabled($disabled)
            aria-label="Gửi"
        >
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" x-show="!(composerSubmitting || $wire.composerSubmitting)">
                <path d="M3.4 20.4 20.85 12.8a.75.75 0 0 0 0-1.35L3.4 3.85a.75.75 0 0 0-1.07.86l2.5 7.04a.75.75 0 0 0 .54.5l8.06 1.9-8.06 1.9a.75.75 0 0 0-.54.5l-2.5 7.04a.75.75 0 0 0 1.07.86Z" />
            </svg>
            <span class="inline-flex" x-show="composerSubmitting || $wire.composerSubmitting" x-cloak>
                <x-filament::loading-indicator class="h-4 w-4" />
            </span>
        </button>
    </form>

    {{ $below ?? '' }}
</footer>
