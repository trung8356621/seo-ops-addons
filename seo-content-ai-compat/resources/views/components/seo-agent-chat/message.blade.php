@props([
    'role' => 'assistant',
    'content' => '',
    'messageType' => 'text',
    'structured' => [],
])

@php
    $isUser = $role === 'user';
    // Execution cards own the body — avoid duplicating summary in message content.
    $executionTypes = ['execution_result', 'execution_error', 'execution_preview', 'execution_confirmation'];
    $showContent = filled($content) && ! in_array((string) $messageType, $executionTypes, true);
@endphp

<div class="{{ $isUser ? 'seo-global-chat__user-row' : 'seo-global-chat__assistant-row' }}">
    <?php if (! $isUser): ?>
        <span class="seo-global-chat__assistant-icon">
            <x-seo-content-ai::seo-agent-chat.star-icon />
        </span>
    <?php endif; ?>

    <div class="{{ $isUser ? 'seo-global-chat__user-message' : 'seo-global-chat__assistant-message' }} {{ $messageType === 'error' ? 'is-error' : '' }}">
        <?php if ($showContent): ?>
            <div class="whitespace-pre-wrap">{{ $content }}</div>
        <?php endif; ?>

        {{ $slot }}
    </div>
</div>
