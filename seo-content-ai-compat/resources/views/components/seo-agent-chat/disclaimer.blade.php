@props([
    'text' => @js(__('Agents only enforce capabilities through authentication. Do not self-publish.')),
])

<p {{ $attributes->merge(['class' => 'seo-global-chat__hint seo-agent-chat__disclaimer']) }}>
    {{ $text }}
</p>
