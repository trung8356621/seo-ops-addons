<div
    class="wp-seo-fields mt-4 border-t border-gray-200 pt-3 dark:border-gray-700"
    x-data="{ seoFieldsOpen: false }"
    x-on:article-editor-toggle-seo-fields.window="seoFieldsOpen = !seoFieldsOpen"
>
    <div class="wp-seo-fields-toolbar">
        <button
            type="button"
            x-on:click="seoFieldsOpen = !seoFieldsOpen"
            class="inline-flex items-center gap-1.5 text-xs font-medium text-sky-600 hover:text-sky-700 hover:underline dark:text-sky-400 dark:hover:text-sky-300"
            x-bind:aria-expanded="seoFieldsOpen"
        >
            <svg
                class="h-3.5 w-3.5 transition-transform"
                x-bind:class="seoFieldsOpen ? 'rotate-90' : ''"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                aria-hidden="true"
            >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
            <span x-text="seoFieldsOpen ? 'Ẩn trường SEO' : 'Chỉnh sửa trường SEO'"></span>
        </button>

        <div class="wp-seo-fields-toolbar-end">
            {{-- Keyboard shortcuts panel đã gỡ — không tạo slot trùng --}}
            <div class="seo-editor-page-actions seo-editor-page-actions--seo-fields-legacy" aria-hidden="true"></div>
        </div>
    </div>

    <div x-show="seoFieldsOpen" x-cloak class="mt-3 space-y-3">
        <div>
            <div class="mb-1 flex items-center justify-between text-xs text-gray-600 dark:text-gray-300">
                <label class="font-medium text-gray-700 dark:text-gray-200">Từ khóa chính</label>
                <span>{{ mb_strlen(trim((string) $focusKeyword)) }} ký tự</span>
            </div>
            <input
                type="text"
                wire:model.live.debounce.300ms="focusKeyword"
                placeholder="Nhập từ khóa chính cho bài viết..."
                class="w-full rounded border border-gray-300 bg-white px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-900"
            />
        </div>

        <div>
            <div class="mb-1 flex items-center justify-between text-xs text-gray-600 dark:text-gray-300">
                <label class="font-medium text-gray-700 dark:text-gray-200">Liên kết cố định</label>
                <span>{{ mb_strlen(trim((string) $articleSlug)) }} / 85</span>
            </div>
            <input
                type="text"
                value="{{ trim((string) $articleSlug) }}"
                readonly
                class="w-full rounded border border-gray-300 bg-gray-100 px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-800"
            />
            <div class="mt-1 h-1.5 w-full overflow-hidden rounded bg-gray-200 dark:bg-gray-700">
                @php($slugLength = mb_strlen(trim((string) $articleSlug)))
                @php($slugRatio = min(100, (int) round(($slugLength / 85) * 100)))
                <div
                    class="h-full {{ $slugLength > 85 ? 'bg-red-500' : ($slugLength >= 80 ? 'bg-amber-500' : 'bg-green-500') }}"
                    style="width: {{ $slugRatio }}%;"
                ></div>
            </div>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            Tiêu đề SEO và mô tả meta được quản lý trong khối «Xem trước Google» bên trái editor.
        </p>
    </div>
</div>
