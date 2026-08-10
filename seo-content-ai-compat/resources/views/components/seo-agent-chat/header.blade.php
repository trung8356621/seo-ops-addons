@props([
    'title' => 'Agent Workspace',
    'subtitle' => null,
])

<header {{ $attributes->merge(['class' => 'seo-global-chat__header seo-agent-chat__header']) }}>
    <div class="seo-global-chat__header-top">
        <div class="seo-global-chat__brand">
            <span class="seo-global-chat__brand-icon">
                <x-seo-content-ai::seo-agent-chat.star-icon />
            </span>
            <div>
                <h2>{{ $title }}</h2>
                @if (filled($subtitle))
                    <p>{{ $subtitle }}</p>
                @endif
            </div>
        </div>
        @if (isset($actions))
            <div class="seo-global-chat__header-actions">
                {{ $actions }}
            </div>
        @endif
    </div>
</header>
