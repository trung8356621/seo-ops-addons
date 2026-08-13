/**
 * Presentation status for assistant dock widgets.
 * Images: integrity (error) / metadata (warning) / SEO recommendation (info) — separated.
 */

import {
    resolveImageRefIds,
} from '@media-addon/utils/articleImagesUtils.js';
import { isWordPressProtectedMedia, isBulkSlugRenameSafeMedia } from '@media-addon/utils/mediaSourceClassification.js';
import { loadFeaturedImage } from '@media-addon/utils/articleFeaturedImageStorage.js';
import { presentSeoReason } from '@seo-addon/utils/seoReasonMetrics.js';
import { minimumValidLinksFromPolicy } from '@seo-addon/utils/articleAnalysisOwnership.js';

export const MIN_VALID_HTTP_LINKS = 5;

function resolvedMinValidLinks() {
    return minimumValidLinksFromPolicy();
}

/**
 * @typedef {{
 *   code: string,
 *   message: string,
 *   target_id?: string|null,
 *   target?: string|null,
 *   severity?: 'error'|'warning'|'info',
 *   action?: string|null,
 * }} AssistantHealthReason
 */

/**
 * @typedef {{
 *   key: string,
 *   item_count: number,
 *   issue_count: number,
 *   error_count?: number,
 *   warning_count?: number,
 *   info_count?: number,
 *   status: 'error'|'warning'|'success'|'neutral'|'info',
 *   reasons: AssistantHealthReason[],
 * }} AssistantWidgetHealth
 */

export function isValidHttpLinkHref(href) {
    const value = String(href ?? '').trim();
    if (value === '' || value.startsWith('#') || value.startsWith('javascript:')) {
        return false;
    }
    if (/^(tel|mailto|sms|fax|callto|geo|skype|whatsapp|viber|zalo|maps):/i.test(value)) {
        return false;
    }
    if (/^https?:\/\//i.test(value) || value.startsWith('//') || value.startsWith('/')) {
        return true;
    }

    return false;
}

export function countValidHttpLinks(extractedLinks) {
    const buckets = [
        ...(Array.isArray(extractedLinks?.internal) ? extractedLinks.internal : []),
        ...(Array.isArray(extractedLinks?.external) ? extractedLinks.external : []),
    ];
    const seen = new Set();
    let count = 0;
    buckets.forEach((link) => {
        const href = String(link?.href ?? '').trim();
        if (!isValidHttpLinkHref(href)) {
            return;
        }
        const key = href.toLowerCase().replace(/\/+$/, '');
        if (seen.has(key)) {
            return;
        }
        seen.add(key);
        count += 1;
    });

    return count;
}

function rowHasMediaSignal(row) {
    if (!row) {
        return false;
    }
    const src = String(row.src ?? row.url ?? '').trim();
    const localSrc = String(row.localSrc ?? row.local_src ?? '').trim();
    const { wpAttachmentId, seoMediaId } = resolveImageRefIds(row);

    return src !== '' || localSrc !== '' || wpAttachmentId > 0 || seoMediaId > 0;
}

function isAiMediaLoadingPlaceholder(row) {
    if (!row) {
        return false;
    }

    if (row.isProcessing || row.is_processing) {
        return true;
    }

    const src = String(row.src ?? row.url ?? row.localSrc ?? row.local_src ?? '').trim();

    return /placeholder-loading/i.test(src) || src.includes('seo-ai-media-loading-placeholder');
}

/** Placeholder / empty filename for local-safe media only. */
export function rowHasLocalPlaceholderSlug(row) {
    if (!row || isWordPressProtectedMedia(row) || !isBulkSlugRenameSafeMedia(row)) {
        return false;
    }

    if (isAiMediaLoadingPlaceholder(row)) {
        return false;
    }

    const src = String(row.src ?? row.url ?? row.localSrc ?? row.local_src ?? '').trim();
    const basenameRaw = src.split('/').pop()?.split('?')[0] ?? '';
    const basename = basenameRaw.replace(/\.[^.]+$/, '');
    const slug = String(row.slug ?? '').trim() || basename;
    if (slug === '') {
        return true;
    }

    return /^(image|img|photo|untitled|download|dsc|img_)[-_]?\d*$/i.test(slug)
        || /^(paste|clipboard|import)-[a-f0-9]{8,}$/i.test(slug)
        || /placeholder/i.test(slug);
}

function rowUploadIncomplete(row) {
    if (isAiMediaLoadingPlaceholder(row)) {
        return false;
    }

    const src = String(row?.src ?? row?.url ?? row?.localSrc ?? row?.local_src ?? '').trim();
    if (src === '') {
        return true;
    }
    if (src.startsWith('blob:') || /placeholder/i.test(src)) {
        return true;
    }

    return false;
}

/**
 * Analyze body images into integrity / metadata / recommendation buckets.
 *
 * @param {Array<Record<string, unknown>>} rows
 * @param {string} keyword
 */
export function analyzeImageRowsHealth(rows, keyword = '') {
    const list = Array.isArray(rows) ? rows : [];
    const reasons = [];
    let errorCount = 0;
    let warningCount = 0;
    let firstErrorTarget = null;
    let firstWarningTarget = null;
    void keyword;

    list.forEach((row) => {
        if (!row) {
            return;
        }
        const blockId = String(row.blockId ?? row.block_id ?? '').trim();
        const targetId = blockId || (row.wpAttachmentId ? `image-${row.wpAttachmentId}` : null);
        const protectedWp = isWordPressProtectedMedia(row);

        if (!rowHasMediaSignal(row) || rowUploadIncomplete(row)) {
            errorCount += 1;
            if (!firstErrorTarget) {
                firstErrorTarget = targetId;
            }
            reasons.push({
                code: rowHasMediaSignal(row) ? 'image_upload_incomplete' : 'image_reference_invalid',
                message: rowHasMediaSignal(row)
                    ? 'Ảnh chưa upload xong hoặc đang dùng placeholder'
                    : 'Block ảnh trống / thiếu media',
                target_id: targetId,
                target: 'images',
                severity: 'error',
                action: 'focus_image',
                protected_from_bulk_rename: protectedWp,
            });
            return;
        }

        const alt = String(row.alt ?? '').trim();
        if (alt === '' || /^(image|img|photo|untitled)$/i.test(alt)) {
            warningCount += 1;
            if (!firstWarningTarget) {
                firstWarningTarget = targetId;
            }
            reasons.push({
                code: 'image_alt_missing',
                message: 'Ảnh chưa có ALT.',
                target_id: targetId,
                target: 'images',
                severity: 'warning',
                action: 'focus_alt',
                protected_from_bulk_rename: protectedWp,
            });
        }

        // Local/new only — WP filename ≠ keyword is NOT a hard error for bulk.
        if (rowHasLocalPlaceholderSlug(row) || row?.requires_slug_fix === true) {
            errorCount += 1;
            if (!firstErrorTarget) {
                firstErrorTarget = targetId;
            }
            reasons.push({
                code: 'image_slug_unresolved',
                message: 'Ảnh nội bộ chưa được chuẩn hóa slug.',
                target_id: targetId,
                target: 'images',
                severity: 'error',
                action: 'fix_local_slug',
                requires_slug_fix: true,
                slug_fix_eligible: row?.slug_fix_eligible !== false,
                protected_from_bulk_rename: false,
            });
        }
    });

    // Deduplicate reason codes for chip summary (keep first of each code type for count messages).
    const errorReasons = reasons.filter((r) => r.severity === 'error');
    const warningReasons = reasons.filter((r) => r.severity === 'warning');
    const summaryReasons = [];
    const slugCount = errorReasons.filter((r) => r.code === 'image_slug_unresolved').length;
    const integrityCount = errorReasons.filter((r) => (
        r.code === 'image_upload_incomplete' || r.code === 'image_reference_invalid'
    )).length;

    if (integrityCount > 0) {
        summaryReasons.push({
            code: 'image_integrity_issues',
            message: integrityCount === 1
                ? '1 ảnh có lỗi media (trống / placeholder / hỏng)'
                : `${integrityCount} ảnh có lỗi media`,
            target_id: firstErrorTarget,
            target: 'images',
            severity: 'error',
            action: 'focus_image',
        });
    }
    if (slugCount > 0) {
        summaryReasons.push({
            code: 'image_slug_unresolved',
            message: slugCount === 1
                ? '1 ảnh local cần chuẩn hóa slug'
                : `${slugCount} ảnh local cần chuẩn hóa slug`,
            target_id: firstErrorTarget,
            target: 'images',
            severity: 'error',
            action: 'fix_local_slug',
            requires_slug_fix: true,
            slug_fix_eligible: true,
        });
    }
    if (warningCount > 0) {
        const altCount = warningReasons.filter((r) => r.code === 'image_alt_missing').length;
        if (altCount > 0) {
            summaryReasons.push({
                code: 'image_alt_missing',
                message: altCount === 1 ? '1 ảnh thiếu ALT' : `${altCount} ảnh thiếu ALT`,
                target_id: firstWarningTarget,
                target: 'images',
                severity: 'warning',
                action: 'focus_alt',
            });
        }
    }

    return {
        itemCount: list.length,
        errorCount,
        warningCount,
        reasons: summaryReasons.length > 0 ? summaryReasons : errorReasons.concat(warningReasons),
        detailReasons: reasons,
        slugIssues: slugCount,
        invalidIssues: integrityCount,
        issueCount: errorCount + warningCount,
    };
}

export function buildImagesWidgetHealth({
    rows = [],
    keyword = '',
    imageRatioMetrics = null,
    locale = 'vi',
    messages = {},
} = {}) {
    const analyzed = analyzeImageRowsHealth(rows, keyword);
    const reasons = [...analyzed.reasons];
    const recommended = Math.max(0, Number(imageRatioMetrics?.recommended_image_count) || 0);
    const missingRecommended = Math.max(0, Number(imageRatioMetrics?.missing_image_count) || 0);
    const metricsValid = imageRatioMetrics?.valid_image_count ?? imageRatioMetrics?.current_image_count;
    const validCount = metricsValid != null && Number.isFinite(Number(metricsValid))
        ? Math.max(0, Number(metricsValid))
        : Math.max(0, analyzed.itemCount - analyzed.errorCount);

    let infoCount = 0;
    if (recommended > 0) {
        infoCount += 1;
        reasons.push({
            code: 'image_recommendation',
            message: locale === 'en'
                ? `About ${recommended} images recommended (${validCount} valid).`
                : `Đề xuất khoảng ${recommended} ảnh (${validCount} ảnh hợp lệ).`,
            target: 'images',
            severity: 'info',
            action: 'open_images_panel',
        });
    }

    if (
        imageRatioMetrics
        && missingRecommended > 0
        && Number(imageRatioMetrics.recommended_image_count) > Number(imageRatioMetrics.current_image_count)
    ) {
        const presented = presentSeoReason('image_ratio_low', {
            messages,
            metrics: imageRatioMetrics,
            locale,
        });
        if (!reasons.some((r) => r.code === 'image_ratio_low')) {
            infoCount += 1;
            reasons.push({
                code: 'image_ratio_low',
                message: presented.summary,
                target: 'images',
                severity: 'info',
                action: 'open_images_panel',
            });
        }
    }

    const errorCount = analyzed.errorCount;
    const warningCount = analyzed.warningCount;
    let status = 'neutral';
    if (errorCount > 0) {
        status = 'error';
    } else if (warningCount > 0) {
        status = 'warning';
    } else if (infoCount > 0 && validCount > 0) {
        status = 'info';
    } else if (validCount > 0) {
        status = 'success';
    }

    return {
        key: 'images',
        item_count: analyzed.itemCount > 0 ? analyzed.itemCount : validCount,
        issue_count: errorCount + warningCount,
        error_count: errorCount,
        warning_count: warningCount,
        info_count: infoCount,
        recommended_count: recommended,
        missing_recommended_count: missingRecommended,
        status,
        reasons: reasons.filter((reason, index, list) => (
            list.findIndex((entry) => entry.code === reason.code && entry.target_id === reason.target_id) === index
        )),
    };
}

export function buildSeoWidgetHealth({
    focusKeyword = '',
    violations = [],
    failedItems = [],
    locale = 'vi',
} = {}) {
    const keyword = String(focusKeyword ?? '').trim();
    const reasons = [];
    const failed = Array.isArray(failedItems) ? failedItems : [];

    if (keyword === '' || /^(từ khóa|keyword|focus keyword|nhập|enter)/i.test(keyword)) {
        reasons.push({
            code: 'focus_keyword_missing',
            message: locale === 'en' ? 'Focus keyword is missing' : 'Thiếu từ khóa chính',
            target_id: 'focus-keyword',
            target: 'seo',
        });
    }

    failed.forEach((item) => {
        const code = String(item?.key ?? item?.code ?? '').trim();
        if (!code || code === 'missing_focus_keyword') {
            return;
        }
        if (reasons.some((r) => r.code === code)) {
            return;
        }
        reasons.push({
            code,
            message: String(item?.summary ?? item?.label ?? code),
            target: 'seo',
            target_id: code,
        });
    });

    if (Array.isArray(violations) && violations.includes('missing_focus_keyword')) {
        if (!reasons.some((r) => r.code === 'focus_keyword_missing')) {
            reasons.unshift({
                code: 'focus_keyword_missing',
                message: locale === 'en' ? 'Focus keyword is missing' : 'Thiếu từ khóa chính',
                target_id: 'focus-keyword',
                target: 'seo',
            });
        }
    }

    const issueCount = reasons.length;
    let status = 'success';
    if (issueCount > 0) {
        const onlySoft = reasons.every((r) =>
            String(r.code).includes('suboptimal')
            || String(r.code).includes('below_excellent'),
        );
        status = onlySoft ? 'warning' : 'error';
    }

    return {
        key: 'seo',
        item_count: failed.length,
        issue_count: issueCount,
        status: issueCount > 0 ? status : (keyword ? 'success' : 'neutral'),
        reasons,
    };
}

export function buildLinksWidgetHealth({ extractedLinks = null, locale = 'vi', minimumValidLinks = null } = {}) {
    const validCount = countValidHttpLinks(extractedLinks);
    const minimum = Math.max(1, Number(minimumValidLinks) || resolvedMinValidLinks());
    const reasons = [];
    const missing = Math.max(0, minimum - validCount);

    if (missing > 0) {
        reasons.push({
            code: 'links_below_minimum',
            message: locale === 'en'
                ? `Need ${missing} more valid link(s) (${validCount}/${minimum}).`
                : `Cần thêm ${missing} liên kết hợp lệ (${validCount}/${minimum}).`,
            target: 'links',
            severity: 'error',
            params: {
                current: validCount,
                minimum,
                missing,
            },
        });
    }

    return {
        key: 'links',
        item_count: validCount,
        issue_count: reasons.length,
        status: reasons.length > 0 ? 'error' : (validCount > 0 ? 'success' : 'neutral'),
        reasons,
    };
}

export function buildFeaturedWidgetHealth({
    articleId = 0,
    featuredImage = null,
    keyword = '',
    altMandatory = false,
    locale = 'vi',
} = {}) {
    const stored = loadFeaturedImage(articleId);
    const item = featuredImage && String(featuredImage.url ?? featuredImage.src ?? '').trim()
        ? featuredImage
        : stored;
    const reasons = [];
    const url = String(item?.url ?? item?.src ?? '').trim();

    if (!item || url === '') {
        reasons.push({
            code: 'featured_missing',
            message: locale === 'en' ? 'Featured image is missing' : 'Chưa có ảnh đại diện',
            target: 'featured',
            severity: 'error',
        });
    } else {
        const row = {
            src: url,
            slug: item.slug,
            wpAttachmentId: item.wp_attachment_id ?? item.wpAttachmentId,
            seoMediaId: item.seo_media_id ?? item.seoMediaId,
            quickFixIndex: 1,
        };
        const wpId = Number(row.wpAttachmentId ?? 0);
        const seoId = Number(row.seoMediaId ?? 0);

        if (
            !isAiMediaLoadingPlaceholder({ src: url, isProcessing: item?.isProcessing ?? item?.is_processing })
            && (/placeholder/i.test(url) || url.startsWith('blob:'))
        ) {
            reasons.push({
                code: 'featured_upload_incomplete',
                message: locale === 'en' ? 'Featured image upload incomplete' : 'Ảnh đại diện chưa upload xong',
                target: 'featured',
                severity: 'error',
            });
        } else if (wpId <= 0 && seoId <= 0 && !/^https?:\/\//i.test(url)) {
            reasons.push({
                code: 'featured_upload_incomplete',
                message: locale === 'en' ? 'Featured image upload incomplete' : 'Ảnh đại diện chưa upload xong',
                target: 'featured',
                severity: 'error',
            });
        }

        // ALT / local slug integrity belong to Images unified inventory — not Featured chip.
        // Featured stays green when thumbnail URL/reference is valid (even if ratio/ALT/slug soft).
        void altMandatory;
        void keyword;
        void row;
    }

    const hardErrors = reasons.filter((r) => (
        r.code === 'featured_missing'
        || r.code === 'featured_upload_incomplete'
        || r.code === 'featured_url_invalid'
        || r.code === 'featured_reference_broken'
    ));

    let status = 'success';
    if (hardErrors.length > 0) {
        status = 'error';
    } else if (url) {
        status = 'success';
    }

    return {
        key: 'featured',
        item_count: url ? 1 : 0,
        issue_count: hardErrors.length,
        error_count: hardErrors.length,
        warning_count: 0,
        status,
        reasons: hardErrors,
    };
}

export function buildGalleryWidgetHealth({
    required = false,
    items = [],
    keyword = '',
    locale = 'vi',
} = {}) {
    const list = Array.isArray(items) ? items : [];
    const reasons = [];
    void keyword;

    if (!required && list.length === 0) {
        return {
            key: 'gallery',
            item_count: 0,
            issue_count: 0,
            status: 'neutral',
            reasons: [],
        };
    }

    if (required && list.length === 0) {
        reasons.push({
            code: 'gallery_missing',
            message: locale === 'en' ? 'Product gallery is empty' : 'Gallery sản phẩm đang trống',
            target: 'gallery',
            severity: 'error',
        });
    }

    list.forEach((row, index) => {
        const url = String(row?.url ?? row?.src ?? '').trim();
        if (!url || /placeholder/i.test(url) || url.startsWith('blob:')) {
            reasons.push({
                code: 'gallery_item_broken',
                message: locale === 'en'
                    ? `Gallery item #${index + 1} is broken or incomplete`
                    : `Ảnh album #${index + 1} lỗi hoặc chưa sẵn sàng`,
                target: 'gallery',
                severity: 'error',
            });
        }
    });

    const hard = reasons.filter((r) => r.severity === 'error');

    return {
        key: 'gallery',
        item_count: list.length,
        issue_count: hard.length,
        status: hard.length > 0 ? 'error' : (list.length > 0 ? 'success' : (required ? 'error' : 'neutral')),
        reasons,
    };
}

/**
 * Publishing readiness — missing required category (post-type aware).
 *
 * @param {{
 *   postType?: string,
 *   recordType?: string,
 *   selectedIds?: number[],
 *   required?: boolean|null,
 *   taxonomy?: string|null,
 *   locale?: string,
 * }} [input]
 */
export function buildPublishingWidgetHealth({
    postType = 'article',
    recordType = '',
    selectedIds = [],
    required = null,
    taxonomy = null,
    locale = 'vi',
} = {}) {
    const ids = (Array.isArray(selectedIds) ? selectedIds : [])
        .map((id) => Number(id))
        .filter((id) => Number.isFinite(id) && id > 0);
    const needsCategory = required === null || required === undefined
        ? false
        : Boolean(required);
    const reasons = [];

    if (needsCategory && ids.length === 0) {
        const taxonomyKey = String(taxonomy || '').trim();
        const isProduct = taxonomyKey === 'product_category' || taxonomyKey === 'product_cat';
        reasons.push({
            code: 'publishing_category_missing',
            message: locale === 'en'
                ? (isProduct ? 'Product category is required.' : 'Category is required.')
                : 'Chưa chọn danh mục.',
            target: 'publishing',
            severity: 'error',
            params: {
                post_type: String(postType || ''),
                record_type: String(recordType || ''),
                taxonomy: taxonomyKey || null,
            },
        });
    }

    const issueCount = reasons.length;

    return {
        key: 'publishing',
        item_count: ids.length,
        issue_count: issueCount,
        error_count: issueCount,
        warning_count: 0,
        status: issueCount > 0 ? 'error' : (needsCategory ? 'success' : 'neutral'),
        reasons,
    };
}

export function buildNavigatorBadgesFromWidgetHealth(health) {
    const badges = {};
    if (health?.seo) {
        badges.seo = health.seo.issue_count > 0 ? health.seo.issue_count : null;
    }

    return badges;
}
