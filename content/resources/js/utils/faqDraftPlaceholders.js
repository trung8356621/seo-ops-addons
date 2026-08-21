import { answerHtmlForEditor } from './faqAnswerHtml.js';

/**
 * Plain text from FAQ answer HTML — empty `<p></p>` counts as blank.
 *
 * @param {unknown} answer
 * @returns {string}
 */
export function faqAnswerPlainText(answer) {
    return String(answer ?? '')
        .replace(/<[^>]*>/g, ' ')
        .replace(/&nbsp;/gi, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

/**
 * @param {unknown} question
 * @returns {string}
 */
export function normalizeFaqQuestionKey(question) {
    return String(question ?? '')
        .trim()
        .toLowerCase()
        .replace(/\s+/g, ' ');
}

/**
 * Brand-new "+ Thêm câu" rows before the user types.
 * Server persistence drops empty question/answer — UI must keep them locally.
 *
 * @param {unknown} row
 * @returns {boolean}
 */
export function isFaqDraftPlaceholder(row) {
    if (!row || typeof row !== 'object') {
        return false;
    }

    return String(row.question ?? '').trim() === ''
        && faqAnswerPlainText(row.answer) === '';
}

/**
 * Rows the server omits (missing question and/or answer plain text).
 * Includes empty placeholders and mid-edit drafts (question filled, answer still empty).
 *
 * @param {unknown} row
 * @returns {boolean}
 */
export function isFaqUnpersistedLocal(row) {
    if (!row || typeof row !== 'object') {
        return false;
    }

    const question = String(row.question ?? '').trim();
    const answer = faqAnswerPlainText(row.answer);

    return question === '' || answer === '';
}

/**
 * Stable client identity for React keys / TipTap remount avoidance.
 *
 * @param {unknown} row
 * @param {number} [index]
 * @returns {string}
 */
export function faqRowClientKey(row, index = 0) {
    const existing = String(row?.client_key ?? '').trim();
    if (existing !== '') {
        return existing;
    }
    if (row?.id != null && row.id !== '') {
        return `faq-id-${row.id}`;
    }

    return `faq-row-${index}`;
}

/**
 * @param {unknown} row
 * @param {number} sortOrder
 * @returns {object}
 */
function asLocalFaqRow(row, sortOrder) {
    return {
        id: row?.id ?? null,
        client_key: faqRowClientKey(row, sortOrder),
        question: String(row?.question ?? ''),
        answer: answerHtmlForEditor(
            row && typeof row === 'object' ? (row.answer || '<p></p>') : '<p></p>',
        ),
        more: String(row?.more ?? ''),
        sort_order: sortOrder,
        duplicate: Boolean(row?.duplicate),
        duplicate_scope: row?.duplicate_scope === 'site' ? 'site' : null,
    };
}

/**
 * After FAQ snapshot ACK, keep server SoT for persisted rows and re-attach
 * local rows the server omitted (empty drafts, question-only, answer-only,
 * or complete rows typed during the in-flight request).
 *
 * Matching prefers id → normalized question → unused local order.
 * Always uses latest `localRows` (post-await UI state), not a stale snapshot alone.
 *
 * @param {unknown[]} serverRows
 * @param {unknown[]} localRows
 * @returns {{ rows: object[], needsFlush: boolean }}
 */
export function mergeFaqRowsPreservingDrafts(serverRows, localRows) {
    const server = Array.isArray(serverRows) ? serverRows : [];
    const local = Array.isArray(localRows) ? localRows : [];

    if (local.length === 0) {
        return {
            rows: server.map((row, index) => asLocalFaqRow(row, index + 1)),
            needsFlush: false,
        };
    }

    const matchedLocal = new Set();
    const serverQuestionKeys = new Set();
    const serverIds = new Set();
    let needsFlush = false;

    const merged = server.map((sRow, index) => {
        const sId = sRow?.id ?? null;
        const sQuestionKey = normalizeFaqQuestionKey(sRow?.question);
        if (sId != null) {
            serverIds.add(sId);
        }
        if (sQuestionKey !== '') {
            serverQuestionKeys.add(sQuestionKey);
        }

        let localIndex = -1;
        if (sId != null) {
            localIndex = local.findIndex(
                (row, i) => !matchedLocal.has(i) && row?.id != null && row.id === sId,
            );
        }
        if (localIndex < 0 && sQuestionKey !== '') {
            localIndex = local.findIndex(
                (row, i) => !matchedLocal.has(i)
                    && normalizeFaqQuestionKey(row?.question) === sQuestionKey,
            );
        }
        if (localIndex < 0 && index < local.length && !matchedLocal.has(index)) {
            const candidate = local[index];
            const candidateKey = normalizeFaqQuestionKey(candidate?.question);
            if (
                isFaqDraftPlaceholder(candidate)
                || candidateKey === ''
                || candidateKey === sQuestionKey
            ) {
                localIndex = index;
            }
        }

        const localMatch = localIndex >= 0 ? local[localIndex] : null;
        if (localIndex >= 0) {
            matchedLocal.add(localIndex);
        }

        const next = asLocalFaqRow(
            {
                ...sRow,
                client_key: faqRowClientKey(localMatch ?? sRow, index),
            },
            index + 1,
        );

        // Concurrent edit during in-flight save: keep newer local Q/A on same identity.
        if (localMatch && !isFaqUnpersistedLocal(localMatch)) {
            const localQ = String(localMatch.question ?? '').trim();
            const localA = String(localMatch.answer ?? '');
            const serverQ = String(sRow?.question ?? '').trim();
            const serverA = String(sRow?.answer ?? '');
            if (localQ !== serverQ || faqAnswerPlainText(localA) !== faqAnswerPlainText(serverA)) {
                next.question = localQ || next.question;
                next.answer = answerHtmlForEditor(localA || next.answer);
                needsFlush = true;
            }
        }

        return next;
    });

    local.forEach((row, index) => {
        if (matchedLocal.has(index)) {
            return;
        }

        const questionKey = normalizeFaqQuestionKey(row?.question);
        if (row?.id != null && serverIds.has(row.id)) {
            return;
        }
        if (questionKey !== '' && serverQuestionKeys.has(questionKey)) {
            return;
        }

        merged.push(asLocalFaqRow(row, merged.length + 1));
        if (questionKey !== '') {
            serverQuestionKeys.add(questionKey);
        }
        if (!isFaqUnpersistedLocal(row)) {
            needsFlush = true;
        }
    });

    return { rows: merged, needsFlush };
}

/**
 * AI generate is additive preview: keep existing (incl. manual) rows, append
 * generated ones not already present (dedupe by normalized question).
 *
 * @param {unknown[]} existingRows
 * @param {unknown[]} generatedRows
 * @returns {object[]}
 */
export function mergeGeneratedFaqsWithExisting(existingRows, generatedRows) {
    const existing = Array.isArray(existingRows) ? existingRows : [];
    const generated = Array.isArray(generatedRows) ? generatedRows : [];
    const seen = new Set(
        existing
            .map((row) => normalizeFaqQuestionKey(row?.question))
            .filter((key) => key !== ''),
    );

    const merged = existing.map((row, index) => asLocalFaqRow(row, index + 1));

    generated.forEach((row) => {
        const key = normalizeFaqQuestionKey(row?.question);
        if (key !== '' && seen.has(key)) {
            return;
        }
        if (key !== '') {
            seen.add(key);
        }
        merged.push(
            asLocalFaqRow(
                {
                    ...row,
                    id: null,
                    client_key: `faq-gen-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`,
                },
                merged.length + 1,
            ),
        );
    });

    return merged;
}

/**
 * True when a complete local row still lacks a server id (needs another flush).
 *
 * @param {unknown[]} rows
 * @returns {boolean}
 */
export function faqRowsNeedPersistFlush(rows) {
    return (Array.isArray(rows) ? rows : []).some((row) => {
        if (!row || typeof row !== 'object') {
            return false;
        }
        if (isFaqUnpersistedLocal(row)) {
            return false;
        }

        return row.id == null || row.id === '';
    });
}
