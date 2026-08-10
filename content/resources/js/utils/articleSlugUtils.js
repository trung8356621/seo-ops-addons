/**
 * Chuẩn hóa slug giống Laravel Str::slug() — kebab-case, bỏ dấu tiếng Việt.
 */
export function normalizeArticleSlug(value) {
    if (value == null) {
        return '';
    }

    return String(value)
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/đ/g, 'd')
        .replace(/Đ/g, 'd')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}
