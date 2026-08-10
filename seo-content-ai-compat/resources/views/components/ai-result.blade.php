@props([
    'label' => 'Kết quả AI',
    'maxHeight' => '28rem',
])

@php
    $content = trim((string) $slot);
    $firstLine = trim((string) (strtok($content, "\n") ?: $content));
    $isMediaUrl = (bool) preg_match('#^(https?://|/storage/)#i', $firstLine);
    $mediaPath = parse_url($firstLine, PHP_URL_PATH) ?? $firstLine;
    $isImage = $isMediaUrl && (bool) preg_match('/\.(png|jpe?g|gif|webp|svg)$/i', $mediaPath);
    $isVideo = $isMediaUrl && (bool) preg_match('/\.(mp4|webm|mov|m4v)$/i', $mediaPath);
    $showMedia = ($isImage || $isVideo) && $content === $firstLine;
@endphp

@once
    @vite('addons/ai-prompt/resources/css/ai-result.css')
@endonce

<div
    {{ $attributes->class(['seo-ai-result']) }}
    x-data="{
        copied: false,
        async copyResult() {
            const text = this.$refs.content?.dataset?.copyText
                || this.$refs.content?.textContent
                || '';

            try {
                await navigator.clipboard.writeText(text);
            } catch (error) {
                const input = document.createElement('textarea');
                input.value = text;
                input.style.position = 'fixed';
                input.style.opacity = '0';
                document.body.appendChild(input);
                input.select();
                document.execCommand('copy');
                input.remove();
            }

            this.copied = true;
            window.setTimeout(() => this.copied = false, 1600);
        }
    }"
>
    <div class="seo-ai-result__toolbar">
        <span class="seo-ai-result__label">{{ $label }}</span>

        <button
            type="button"
            class="seo-ai-result__copy"
            x-on:click="copyResult"
            x-bind:aria-label="copied ? 'Đã sao chép' : 'Sao chép kết quả'"
        >
            <svg x-show="! copied" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M6.5 2.75A1.75 1.75 0 0 0 4.75 4.5v8A1.75 1.75 0 0 0 6.5 14.25h1v-1.5h-1a.25.25 0 0 1-.25-.25v-8a.25.25 0 0 1 .25-.25h6a.25.25 0 0 1 .25.25v1h1.5v-1A1.75 1.75 0 0 0 12.5 2.75h-6Z" />
                <path d="M9.5 5.75A1.75 1.75 0 0 0 7.75 7.5v8A1.75 1.75 0 0 0 9.5 17.25h6a1.75 1.75 0 0 0 1.75-1.75v-8a1.75 1.75 0 0 0-1.75-1.75h-6Zm-.25 1.75a.25.25 0 0 1 .25-.25h6a.25.25 0 0 1 .25.25v8a.25.25 0 0 1-.25.25h-6a.25.25 0 0 1-.25-.25v-8Z" />
            </svg>
            <svg x-cloak x-show="copied" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M16.704 5.292a1 1 0 0 1 .004 1.414l-7.25 7.292a1 1 0 0 1-1.42 0l-3.746-3.77a1 1 0 1 1 1.416-1.41l3.04 3.058 6.542-6.58a1 1 0 0 1 1.414-.004Z" clip-rule="evenodd" />
            </svg>
            <span x-text="copied ? 'Đã copy' : 'Copy'">Copy</span>
        </button>
    </div>

    @if ($showMedia)
        <div
            class="seo-ai-result__media"
            style="--seo-ai-result-max-height: {{ $maxHeight }}"
        >
            @if ($isImage)
                <img
                    src="{{ $firstLine }}"
                    alt="AI generated image"
                    loading="lazy"
                />
            @else
                <video
                    src="{{ $firstLine }}"
                    controls
                    preload="metadata"
                ></video>
            @endif
        </div>
    @else
        <pre
            x-ref="content"
            class="seo-ai-result__content"
            style="--seo-ai-result-max-height: {{ $maxHeight }}"
        >{{ $slot }}</pre>
    @endif
</div>
