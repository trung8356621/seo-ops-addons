@props([
    'target' => null,
    'label' => null,
    'savingLabel' => null,
    'disabled' => false,
    'icon' => 'heroicon-o-check',
])

@php
    $label ??= __('filament-panels::resources/pages/edit-record.form.actions.save.label');
    $savingLabel ??= __('seo-content-ai::filament.common.saving');
@endphp

<div>
    @if (filled($target))
        <x-filament::button
            type="submit"
            :icon="$icon"
            :disabled="$disabled"
            wire:loading.attr="disabled"
            wire:target="{{ $target }}"
            {{ $attributes->class(['pointer-events-none opacity-50' => $disabled]) }}
        >
            <span wire:loading.remove wire:target="{{ $target }}">{{ $label }}</span>
            <span wire:loading wire:target="{{ $target }}">{{ $savingLabel }}</span>
        </x-filament::button>
    @else
        <x-filament::button
            type="submit"
            :icon="$icon"
            :disabled="$disabled"
            wire:loading.attr="disabled"
            {{ $attributes->class(['pointer-events-none opacity-50' => $disabled]) }}
        >
            {{ $label }}
        </x-filament::button>
    @endif
</div>
