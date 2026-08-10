<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>So sánh phiên bản — {{ $article->title }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: #111827;
            background: #f3f4f6;
        }
        .seo-rev-bar {
            position: sticky;
            top: 0;
            z-index: 20;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.75rem 1.25rem;
            background: #111827;
            color: #f9fafb;
        }
        .seo-rev-bar a { color: #93c5fd; text-decoration: none; }
        .seo-rev-bar a:hover { text-decoration: underline; }
        .seo-rev-restore-btn {
            border: 0;
            border-radius: 6px;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            background: #059669;
            color: #fff;
        }
        .seo-rev-restore-btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }
        .seo-rev-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            padding: 1rem;
            min-height: calc(100vh - 56px);
        }
        @media (max-width: 960px) {
            .seo-rev-grid { grid-template-columns: 1fr; }
        }
        .seo-rev-panel {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            min-height: 420px;
            overflow: hidden;
        }
        .seo-rev-panel__head {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e5e7eb;
            background: #f9fafb;
            font-weight: 600;
        }
        .seo-rev-panel__head.is-past { background: #eff6ff; color: #1d4ed8; }
        .seo-rev-panel__head.is-current { background: #ecfdf5; color: #047857; }
        .seo-rev-panel__tools {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .seo-rev-select {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 0.5rem 0.65rem;
            font-size: 0.875rem;
            background: #fff;
        }
        .seo-rev-panel__body {
            padding: 1rem 1.25rem 1.5rem;
            overflow: auto;
            flex: 1;
        }
        .seo-rev-empty {
            color: #6b7280;
            font-size: 0.875rem;
            padding: 1rem 0;
        }
        .seo-rev-title {
            font-size: 1.375rem;
            line-height: 1.3;
            font-weight: 700;
            margin: 0 0 1rem;
        }
        .seo-rev-seo {
            margin-bottom: 1rem;
            padding: 0.75rem;
            border-radius: 6px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            font-size: 0.8125rem;
        }
        .seo-rev-seo dt {
            font-weight: 600;
            color: #374151;
            margin-top: 0.35rem;
        }
        .seo-rev-seo dt:first-child { margin-top: 0; }
        .seo-rev-seo dd {
            margin: 0.15rem 0 0;
            color: #4b5563;
            word-break: break-word;
        }
        .seo-rev-content {
            font-size: 0.9375rem;
            line-height: 1.65;
            color: #1f2937;
        }
        .seo-rev-content img { max-width: 100%; height: auto; }
        .seo-rev-content table { width: 100%; border-collapse: collapse; }
        .seo-rev-content th,
        .seo-rev-content td { border: 1px solid #e5e7eb; padding: 0.4rem; }
        .seo-rev-loading { color: #6b7280; font-size: 0.875rem; }
        .seo-rev-modal {
            position: fixed;
            inset: 0;
            z-index: 50;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(17, 24, 39, 0.55);
            padding: 1rem;
        }
        .seo-rev-modal__box {
            width: min(100%, 420px);
            background: #fff;
            border-radius: 8px;
            padding: 1.25rem;
            box-shadow: 0 10px 25px rgba(0,0,0,.15);
        }
        .seo-rev-modal__actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        .seo-rev-modal__btn {
            border-radius: 6px;
            padding: 0.45rem 0.85rem;
            font-size: 0.875rem;
            cursor: pointer;
            border: 1px solid #d1d5db;
            background: #fff;
        }
        .seo-rev-modal__btn.is-primary {
            background: #059669;
            border-color: #059669;
            color: #fff;
        }
    </style>
</head>
<body
    x-data="seoRevisionCompare({
        articleId: @js((int) $article->id),
        revisions: @js($revisions),
        current: @js($current),
        restoreUrl: @js($restoreUrl),
        detailUrlTemplate: @js(str_replace('__REVISION__', '{id}', $revisionDetailUrlTemplate)),
    })"
>
    <header class="seo-rev-bar">
        <a href="{{ $editUrl }}">← Quay lại trang sửa bài</a>
        <button
            type="button"
            class="seo-rev-restore-btn"
            x-bind:disabled="!selectedRevisionId || restoring"
            x-on:click="confirmOpen = true"
        >
            ✅ Xác nhận khôi phục phiên bản
        </button>
    </header>

    <div class="seo-rev-grid">
        <section class="seo-rev-panel">
            <div class="seo-rev-panel__head is-past">Phiên bản cũ (Lịch sử)</div>
            <div class="seo-rev-panel__tools">
                <x-select
                    class="seo-rev-select"
                    x-model="selectedRevisionId"
                    x-on:change="loadRevision()"
                >
                    <option value="">— Chọn phiên bản —</option>
                    <template x-for="item in revisions" x-bind:key="item.id">
                        <option x-bind:value="item.id" x-text="item.label"></option>
                    </template>
                </x-select>
            </div>
            <div class="seo-rev-panel__body">
                <template x-if="loadingRevision">
                    <p class="seo-rev-loading">Đang tải phiên bản…</p>
                </template>
                <template x-if="!loadingRevision && !pastPreview">
                    <p class="seo-rev-empty">Chọn một phiên bản từ danh sách để xem nội dung cũ.</p>
                </template>
                <template x-if="!loadingRevision && pastPreview">
                    <div>
                        <h1 class="seo-rev-title" x-text="pastPreview.title || '(Không có tiêu đề)'"></h1>
                        <dl class="seo-rev-seo">
                            <dt>SEO Title</dt>
                            <dd x-text="pastPreview.seo_meta?.seo_title || '—'"></dd>
                            <dt>Meta Description</dt>
                            <dd x-text="pastPreview.seo_meta?.meta_description || '—'"></dd>
                            <dt>Focus Keyword</dt>
                            <dd x-text="pastPreview.seo_meta?.focus_keyword || '—'"></dd>
                            <dt>SEO Score</dt>
                            <dd x-text="pastPreview.seo_meta?.seo_score ?? '—'"></dd>
                        </dl>
                        <div class="seo-rev-content" x-html="pastPreview.content || ''"></div>
                    </div>
                </template>
            </div>
        </section>

        <section class="seo-rev-panel">
            <div class="seo-rev-panel__head is-current">Phiên bản hiện tại</div>
            <div class="seo-rev-panel__body">
                <h1 class="seo-rev-title">{{ $current['title'] !== '' ? $current['title'] : '(Không có tiêu đề)' }}</h1>
                <dl class="seo-rev-seo">
                    <dt>SEO Title</dt>
                    <dd>{{ $current['seo_meta']['seo_title'] ?? '—' }}</dd>
                    <dt>Meta Description</dt>
                    <dd>{{ $current['seo_meta']['meta_description'] ?? '—' }}</dd>
                    <dt>Focus Keyword</dt>
                    <dd>{{ $current['seo_meta']['focus_keyword'] ?? '—' }}</dd>
                    <dt>SEO Score</dt>
                    <dd>{{ $current['seo_meta']['seo_score'] ?? '—' }}</dd>
                </dl>
                <div class="seo-rev-content">{!! $current['content'] !!}</div>
            </div>
        </section>
    </div>

    <div class="seo-rev-modal" x-show="confirmOpen" x-cloak>
        <div class="seo-rev-modal__box" x-on:click.outside="confirmOpen = false">
            <h2 style="margin:0 0 .5rem;font-size:1rem;">Xác nhận khôi phục</h2>
            <p style="margin:0;color:#4b5563;">
                Bạn có chắc chắn muốn ghi đè nội dung hiện tại bằng phiên bản này không?
            </p>
            <div class="seo-rev-modal__actions">
                <button type="button" class="seo-rev-modal__btn" x-on:click="confirmOpen = false">Hủy</button>
                <button type="button" class="seo-rev-modal__btn is-primary" x-on:click="submitRestore()">Khôi phục</button>
            </div>
        </div>
    </div>

    <form x-ref="restoreForm" method="post" action="{{ $restoreUrl }}" style="display:none;">
        @csrf
        <input type="hidden" name="revision_id" x-bind:value="selectedRevisionId">
    </form>

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script>
        function seoRevisionCompare(config) {
            return {
                articleId: config.articleId,
                revisions: Array.isArray(config.revisions) ? config.revisions : [],
                current: config.current ?? {},
                restoreUrl: config.restoreUrl,
                detailUrlTemplate: config.detailUrlTemplate,
                selectedRevisionId: '',
                pastPreview: null,
                loadingRevision: false,
                restoring: false,
                confirmOpen: false,

                async loadRevision() {
                    const id = Number(this.selectedRevisionId || 0);
                    if (id <= 0) {
                        this.pastPreview = null;
                        return;
                    }

                    this.loadingRevision = true;

                    try {
                        const url = this.detailUrlTemplate.replace('{id}', String(id));
                        const response = await fetch(url, {
                            headers: {
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            credentials: 'same-origin',
                        });

                        if (!response.ok) {
                            this.pastPreview = null;
                            return;
                        }

                        const data = await response.json();
                        this.pastPreview = data?.revision ?? null;
                    } catch (error) {
                        console.warn('Không tải được revision', error);
                        this.pastPreview = null;
                    } finally {
                        this.loadingRevision = false;
                    }
                },

                submitRestore() {
                    if (!this.selectedRevisionId || this.restoring) {
                        return;
                    }

                    this.restoring = true;
                    this.confirmOpen = false;
                    this.$refs.restoreForm.submit();
                },
            };
        }
    </script>
</body>
</html>
