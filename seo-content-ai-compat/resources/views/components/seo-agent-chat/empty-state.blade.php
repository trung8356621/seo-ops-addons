@props([
    'title' => @js(__('How can I help?')),
    'description' => @js(__('Type / to see all skills')),
])

<div {{ $attributes->merge(['class' => 'seo-global-chat__empty seo-agent-chat__empty']) }}>
    <span class="seo-global-chat__empty-icon">
        <x-seo-content-ai::seo-agent-chat.star-icon />
    </span>
    <h3>{{ $title }}</h3>
    <p>{{ $description }}</p>
</div>
