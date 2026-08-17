import { isArticleAutosaveLocked } from './articleAutosaveLock';
import { isArticleEditorNetworkError } from './articleEditorNetwork';
import {
    INLINE_WHITESPACE_CORRUPTION_CODE,
    hasInlineWhitespaceCorruption,
    plainTextFromHtmlLoose,
} from './inlineWhitespaceGuard';
import {
    contentsMeaningfullyEqual,
    hashContent,
    normalizeContentForHash,
} from './articleEditorStorage';

export const SAVE_FAILURE = Object.freeze({
    PROTECTED_BLOCK: 'protected_block',
    REVISION_CONFLICT: 'revision_conflict',
    NETWORK: 'network_error',
    VALIDATION: 'validation_error',
});

export const GUARD_REASON = Object.freeze({
    INLINE_WHITESPACE: 'inline_whitespace_corruption',
    EMPTY_BODY: 'empty_body',
    CONTENT_TRUNCATED: 'content_truncated',
    INVALID_PAYLOAD: 'invalid_editor_payload',
    REVISION_MISMATCH: 'revision_mismatch',
    MUTATION_IN_PROGRESS: 'mutation_in_progress',
});

export const LOCAL_RECOVERY_DEBOUNCE_MS = 800;

const CONFLICT_CODES = new Set([
    'article_document_version_conflict',
    'article_content_hash_conflict',
    'conflict_document_version',
    'conflict_content_hash',
    'conflict_updated_at',
    INLINE_WHITESPACE_CORRUPTION_CODE,
]);

/**
 * @param {unknown} error
 * @returns {typeof SAVE_FAILURE[keyof typeof SAVE_FAILURE]}
 */
export function classifyArticleSaveError(error) {
    if (error == null) {
        return SAVE_FAILURE.VALIDATION;
    }

    const code = String(
        (typeof error === 'object' && error !== null
            ? (error.code ?? error.sessionError?.code ?? error.data?.code ?? '')
            : ''),
    ).trim();

    if (code === INLINE_WHITESPACE_CORRUPTION_CODE || code === SAVE_FAILURE.PROTECTED_BLOCK) {
        return SAVE_FAILURE.PROTECTED_BLOCK;
    }

    if (
        (typeof error === 'object' && error !== null && error.conflict === true)
        || CONFLICT_CODES.has(code)
        || code.includes('conflict')
        || code.includes('revision')
    ) {
        return SAVE_FAILURE.REVISION_CONFLICT;
    }

    if (isArticleEditorNetworkError(error)) {
        return SAVE_FAILURE.NETWORK;
    }

    return SAVE_FAILURE.VALIDATION;
}

/**
 * Server-write safety — does not decide local recovery.
 *
 * @param {string} html
 * @param {string} bootstrapPlain
 * @returns {{ ok: boolean, reason: string|null }}
 */
export function inspectWritableDocument(html, bootstrapPlain) {
    const source = String(html ?? '');
    const candidatePlain = plainTextFromHtmlLoose(source);
    const basePlain = String(bootstrapPlain ?? '').trim();

    if (source.trim() === '' || candidatePlain === '') {
        if (basePlain !== '') {
            return { ok: false, reason: GUARD_REASON.EMPTY_BODY };
        }

        return { ok: true, reason: null };
    }

    if (basePlain !== '' && hasInlineWhitespaceCorruption(basePlain, candidatePlain)) {
        return { ok: false, reason: GUARD_REASON.INLINE_WHITESPACE };
    }

    return { ok: true, reason: null };
}

/**
 * Intermediate empty/truncated export during an editor mutation.
 *
 * @param {string} candidateHtml
 * @param {string} baselineHtml
 * @param {{ mutationInProgress?: boolean }} [options]
 * @returns {boolean}
 */
export function isUnstableHollowExport(candidateHtml, baselineHtml, options = {}) {
    const candidateLen = normalizeContentForHash(candidateHtml).length;
    const baseLen = normalizeContentForHash(baselineHtml).length;
    if (candidateLen === 0 && baseLen >= 40) {
        return true;
    }
    if (!options.mutationInProgress) {
        return false;
    }
    if (baseLen < 80) {
        return false;
    }

    return candidateLen * 2 < baseLen || (baseLen - candidateLen) >= 120;
}

/**
 * Clear recovery only when the successful save matches the current editor snapshot.
 *
 * @param {{
 *   currentHtml?: string|null,
 *   savedHtml?: string|null,
 *   savedContentHash?: string|null,
 * }} input
 * @returns {boolean}
 */
export function shouldClearLocalRecoveryAfterSave(input = {}) {
    const currentHtml = String(input.currentHtml ?? '');
    const savedHtml = String(input.savedHtml ?? '');
    const savedHash = String(input.savedContentHash ?? '').trim() || hashContent(savedHtml);
    const currentHash = hashContent(currentHtml);

    if (currentHtml.trim() === '') {
        return false;
    }
    if (savedHash !== '' && currentHash !== '' && savedHash === currentHash) {
        return true;
    }

    return contentsMeaningfullyEqual(currentHtml, savedHtml);
}

/**
 * @returns {Record<string, unknown>}
 */
export function currentEditorSaveMeta() {
    const html = typeof window !== 'undefined' && typeof window.__seoExportEditorHtml === 'function'
        ? String(window.__seoExportEditorHtml() ?? '')
        : '';

    return {
        article_id: Number(window.__SEO_ARTICLE_ID__ ?? 0) || 0,
        project_item_id: Number(
            window.__SEO_ARTICLE_PROJECT_ITEM_ID__
            ?? window.__SEO_ARTICLE_PROJECT_ID__
            ?? 0,
        ) || 0,
        user_id: Number(window.__SEO_EDITOR_CURRENT_USER_ID__ ?? 0) || 0,
        editor_revision: window.__SEO_EDITOR_DOCUMENT_VERSION__ ?? null,
        server_revision: window.__seoEditorSessionClient?.documentVersion ?? null,
        content_length: html.length,
        outline_count: (html.match(/<h[1-6]\b/gi) || []).length,
        image_count: (html.match(/<img\b/gi) || []).length,
        mutation_in_progress: isArticleAutosaveLocked(),
        timestamp: new Date().toISOString(),
    };
}

/**
 * Structured guard log — no article body.
 *
 * @param {Record<string, unknown>} fields
 */
export function logArticleEditorSaveGuard(fields = {}) {
    // eslint-disable-next-line no-console
    console.info('[article-editor-save-guard]', {
        ...currentEditorSaveMeta(),
        ...fields,
    });
}

/**
 * @param {number|string} [delayMs]
 * @returns {number}
 */
export function resolveLocalRecoveryDebounceMs(delayMs) {
    const requested = Number(delayMs);
    if (!Number.isFinite(requested) || requested <= 0) {
        return LOCAL_RECOVERY_DEBOUNCE_MS;
    }

    return Math.min(LOCAL_RECOVERY_DEBOUNCE_MS, Math.max(300, requested));
}
