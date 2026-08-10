<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Xem trước — {{ $article->title }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            font-size: 16px;
            line-height: 1.65;
            color: #1f2937;
            background: #f3f4f6;
        }
        .seo-preview-bar {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.65rem 1.25rem;
            background: #111827;
            color: #f9fafb;
            font-size: 0.8125rem;
        }
        .seo-preview-bar a {
            color: #93c5fd;
            text-decoration: none;
        }
        .seo-preview-bar a:hover { text-decoration: underline; }
        .seo-preview-wrap {
            max-width: 48rem;
            margin: 1.5rem auto 3rem;
            padding: 2rem 2.25rem;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,.08);
        }
        .seo-preview-wrap h1 {
            font-size: 1.875rem;
            line-height: 1.25;
            margin: 0 0 1rem;
        }
        .seo-preview-meta {
            font-size: 0.875rem;
            color: #6b7280;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .seo-preview-content img { max-width: 100%; height: auto; }
        .seo-preview-content table { width: 100%; border-collapse: collapse; }
        .seo-preview-content th,
        .seo-preview-content td { border: 1px solid #e5e7eb; padding: 0.5rem; }
    </style>
    @php
        $faqCssPath = base_path('addons/content/resources/css/omi-faq-accordion.css');
    @endphp
    @if (is_file($faqCssPath))
        <style>{!! file_get_contents($faqCssPath) !!}</style>
    @endif
</head>
<body>
    <div class="seo-preview-bar">
        <span>Xem trước trên Laravel (chưa đồng bộ WordPress)</span>
        <span>
            <a href="{{ $editUrl }}">← Sửa bài</a>
            @if (filled($permalink))
                · <a href="{{ $permalink }}" target="_blank" rel="noopener">WordPress</a>
            @endif
        </span>
    </div>

    <article class="seo-preview-wrap">
        <h1>{{ $article->title }}</h1>
        <p class="seo-preview-meta">
            Trạng thái: <strong>{{ $article->status }}</strong>
            @if (filled($focusKeyword))
                · Từ khóa: <strong>{{ $focusKeyword }}</strong>
            @endif
        </p>
        <div class="seo-preview-content">
            {!! $contentHtml !!}
        </div>
    </article>
</body>
</html>
