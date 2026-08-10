@props([
    'text' => 'Agent chỉ thực thi capability qua xác nhận. Không tự publish.',
])

<p {{ $attributes->merge(['class' => 'seo-global-chat__hint seo-agent-chat__disclaimer']) }}>
    {{ $text }}
</p>
