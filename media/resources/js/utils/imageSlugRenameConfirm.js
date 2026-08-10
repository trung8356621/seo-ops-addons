import { resolveWpRenameOldUrl } from './articleImagesUtils';

export const SLUG_RENAME_WARNING = `THAO TÁC NGUY HIỂM

Đổi slug ảnh sẽ:
• Đổi tên file trên WordPress (Thư viện Media)
• Quét và thay URL ảnh cũ trong TẤT CẢ bài viết/trang/sản phẩm trên site

Không thể hoàn tác tự động.`;

export function confirmSlugRename({ count = 1, isQuickFix = false } = {}) {
    const intro = isQuickFix
        ? `Sẽ đổi slug (kebab-case từ khóa) cho ${count} ảnh và gọi WordPress đổi tên file.`
        : `Sẽ đổi slug ảnh và gọi WordPress đổi tên file.`;

    return window.confirm(`${SLUG_RENAME_WARNING}\n\n${intro}\n\nBạn có chắc muốn tiếp tục?`);
}

export function buildRenameQueueFromImages(images, getNewSlug) {
    const queue = [];

    images.forEach((row) => {
        if (row?.excludeQuickFix) {
            return;
        }

        const newSlug = getNewSlug(row);
        if (!newSlug || !row.wpAttachmentId) {
            return;
        }

        const oldSlug = (row.slug || '').trim();
        if (newSlug === oldSlug) {
            return;
        }

        queue.push({
            attachment_id: row.wpAttachmentId,
            new_slug: newSlug,
            old_url: resolveWpRenameOldUrl(row),
            old_slug: oldSlug,
            block_id: String(row?.blockId ?? row?.block_id ?? '').trim(),
        });
    });

    return queue;
}

export function dispatchWordPressSlugRename(items) {
    if (!items?.length) {
        return;
    }

    window.dispatchEvent(
        new CustomEvent('seo-rename-attachment-slugs-loading', {
            detail: { count: items.length },
        }),
    );

    window.dispatchEvent(
        new CustomEvent('seo-rename-attachment-slugs', {
            detail: { items },
        }),
    );
}
