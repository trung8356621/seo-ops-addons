@props([
    'label',
    'plain' => '',
    'visible' => false,
    'unlocked' => false,
    'field' => 'read',
])

@php
    $isRevealed = $visible && $unlocked && $plain !== '';
@endphp

<div class="seo-token-field">
    <span class="seo-token-field__label">{{ $label }}</span>
    <div class="seo-token-field__wrap">
        <input
            type="text"
            class="seo-token-field__input fi-input-wrapped block w-full {{ $isRevealed ? 'seo-token-field__input--revealed' : '' }}"
            readonly
            value="{{ $isRevealed ? $plain : '****************************************' }}"
            @if($isRevealed)
                onfocus="window.seoCopyTokenField && window.seoCopyTokenField(this)"
            @endif
        />
        <button
            type="button"
            class="seo-token-field__toggle"
            wire:click="toggleTokenVisibility('{{ $field }}')"
            aria-label="{{ __('Hiển thị / ẩn token') }}"
        >
            @if($isRevealed)
                <x-filament::icon icon="heroicon-o-eye-slash" class="h-5 w-5" />
            @else
                <x-filament::icon icon="heroicon-o-eye" class="h-5 w-5" />
            @endif
        </button>
    </div>
</div>
