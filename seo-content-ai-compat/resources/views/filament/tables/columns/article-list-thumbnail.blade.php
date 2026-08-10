@php
    /** @var array{status: string, url: ?string, media_id: ?int, source: ?string} $state */
    $state = $getState() ?? ['status' => 'unknown', 'url' => null, 'media_id' => null, 'source' => null];
    $status = (string) ($state['status'] ?? 'unknown');
    $url = is_string($state['url'] ?? null) ? trim((string) $state['url']) : '';
@endphp

<div class="article-list-thumb" data-featured-status="{{ $status }}">
    @if ($status === 'available' && $url !== '')
        <img
            src="{{ $url }}"
            alt=""
            width="46"
            height="46"
            loading="lazy"
            class="article-list-thumb__img"
        />
    @elseif ($status === 'absent')
        <span class="article-list-thumb__placeholder" title="{{ __('seo-content-ai::filament.article_list.thumb_absent') }}">
            {{ __('seo-content-ai::filament.article_list.thumb_absent_short') }}
        </span>
    @else
        <span class="article-list-thumb__placeholder article-list-thumb__placeholder--unknown" title="{{ __('seo-content-ai::filament.article_list.thumb_unknown') }}">
            {{ __('seo-content-ai::filament.article_list.thumb_unknown_short') }}
        </span>
    @endif
</div>

@once
    <style>
        .article-list-thumb {
            width: 46px;
            height: 46px;
            border-radius: 0.375rem;
            overflow: hidden;
            background: rgb(243 244 246);
        }
        .article-list-thumb__img {
            width: 46px;
            height: 46px;
            object-fit: cover;
            display: block;
        }
        .article-list-thumb__placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            padding: 0.15rem;
            text-align: center;
            font-size: 0.55rem;
            line-height: 1.15;
            color: rgb(107 114 128);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .article-list-thumb__placeholder--unknown {
            color: rgb(146 64 14);
            background: rgb(254 243 199);
        }
        .dark .article-list-thumb {
            background: rgb(31 41 55);
        }
        .dark .article-list-thumb__placeholder {
            color: rgb(156 163 175);
        }
        .dark .article-list-thumb__placeholder--unknown {
            color: rgb(253 230 138);
            background: rgb(69 26 3);
        }
    </style>
@endonce
