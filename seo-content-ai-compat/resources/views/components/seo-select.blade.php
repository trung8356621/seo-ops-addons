@props([
    'id' => null,
    'size' => 'default',
    'wrapClass' => '',
])

@php
    $wrapClasses = trim(
        'seo-select-wrap'
        . ($size !== 'default' ? ' seo-select-wrap--' . $size : '')
        . ($wrapClass !== '' ? ' ' . $wrapClass : ''),
    );
    $selectClasses = trim('seo-select ' . ($attributes->get('class') ?? ''));
@endphp

<div class="{{ $wrapClasses }}">
    <select
        @if (filled($id)) id="{{ $id }}" @endif
        {{ $attributes->except('class')->merge(['class' => $selectClasses]) }}
    >{{ $slot }}</select>
    <span class="seo-select-chevron" aria-hidden="true"></span>
</div>
