/**
 * Topic/comment authorization helpers for local-first workspace.
 * Mirrors SeedingTopicAuthorization (PHP) — manage ≈ canMutate until fine RBAC.
 *
 * Flexible Seeding: canSeedTopic is independent of topic author / canMutate.
 */

/**
 * @param {Record<string, unknown>} topic
 * @param {number|string} userId
 * @param {boolean} canMutate
 */
export function canEditTopic(topic, userId, canMutate) {
    if (!canMutate || !topic) return false;
    const owner = topic.created_by_user_id;
    if (owner == null || owner === '') return false;
    return String(owner) === String(userId);
}

/**
 * Author or manage (canMutate).
 * @param {Record<string, unknown>} topic
 * @param {number|string} userId
 * @param {boolean} canMutate
 * @param {Array<Record<string, unknown>>} [reports]
 * @param {(topic: Record<string, unknown>, reports: Array<Record<string, unknown>>, extra?: object) => boolean} [hasWorkHistory]
 * @param {object} [extra]
 */
export function canDeleteTopic(topic, userId, canMutate, reports = [], hasWorkHistory = null, extra = {}) {
    if (!canMutate || !topic) return false;
    if (typeof hasWorkHistory === 'function' && hasWorkHistory(topic, reports, extra)) return false;
    const owner = topic.created_by_user_id;
    if (owner != null && owner !== '' && String(owner) === String(userId)) return true;
    return true; // manage
}

/**
 * Share / Gen eligibility — NOT tied to topic author or canMutate.
 * @param {Record<string, unknown>|null|undefined} topic
 * @param {{ hasWorkspaceAccess?: boolean }} [opts]
 */
export function canSeedTopic(topic, opts = {}) {
    const hasAccess = opts.hasWorkspaceAccess !== false;
    if (!hasAccess || !topic) return false;
    const state = topic.state || 'draft';
    if (state === 'archived' || topic.is_archived) return false;
    return true;
}

/**
 * @deprecated Use canSeedTopic — kept for migrate/tests naming only.
 * @param {Record<string, unknown>} topic
 * @param {{ hasWorkspaceAccess?: boolean }} [opts]
 */
export function canShareTopic(topic, opts = {}) {
    return canSeedTopic(topic, opts);
}

/**
 * Legacy comment helpers — unused in primary Flexible Seeding UX.
 * @param {Record<string, unknown>} comment
 * @param {number|string} userId
 * @param {boolean} canMutate
 */
export function canEditComment(comment, userId, canMutate) {
    if (!canMutate || !comment) return false;
    const owner = comment.author_user_id ?? comment.created_by_user_id;
    if (owner == null || owner === '') return false;
    return String(owner) === String(userId);
}

/**
 * @param {Record<string, unknown>} comment
 * @param {number|string} userId
 * @param {boolean} canMutate
 */
export function canDeleteComment(comment, userId, canMutate) {
    if (!canMutate || !comment) return false;
    if (comment.state === 'in_progress' || comment.state === 'completed' || comment.claimed_by_user_id || comment.completed_at) {
        return false;
    }
    const owner = comment.author_user_id ?? comment.created_by_user_id;
    if (owner != null && owner !== '' && String(owner) === String(userId)) return true;
    return true;
}

/**
 * Link Pool is scoped to the current user's localStorage document.
 * @param {boolean} hasWorkspaceAccess
 */
export function canManageOwnSeedLinks(hasWorkspaceAccess = true) {
    return hasWorkspaceAccess !== false;
}

/**
 * Soft status for feed chips (no comment gate).
 * @param {Record<string, unknown>} topic
 * @returns {'ready'|'seeded'|'archived'|'draft'}
 */
export function seedStatusOf(topic) {
    const state = topic?.state || 'draft';
    if (state === 'archived') return 'archived';
    if (topic?.last_seeded_at) return 'seeded';
    if (state === 'draft') return 'draft';
    return 'ready';
}

export function seedStatusLabel(status) {
    if (status === 'seeded') return 'Đã dùng';
    if (status === 'archived') return 'Lưu trữ';
    if (status === 'draft') return 'Chủ đề';
    return 'Sẵn sàng';
}

/** @deprecated */
export function shareStatusOf(topic) {
    return seedStatusOf(topic);
}

/** @deprecated */
export function shareStatusLabel(status) {
    return seedStatusLabel(status);
}
