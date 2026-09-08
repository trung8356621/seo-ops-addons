/**
 * Topic/comment authorization helpers for local-first workspace.
 * Mirrors SeedingTopicAuthorization (PHP) — manage ≈ canMutate until fine RBAC.
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
 * @param {(topic: Record<string, unknown>, reports: Array<Record<string, unknown>>) => boolean} [hasWorkHistory]
 */
export function canDeleteTopic(topic, userId, canMutate, reports = [], hasWorkHistory = null) {
    if (!canMutate || !topic) return false;
    if (typeof hasWorkHistory === 'function' && hasWorkHistory(topic, reports)) return false;
    const owner = topic.created_by_user_id;
    if (owner != null && owner !== '' && String(owner) === String(userId)) return true;
    return true; // manage
}

/**
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
 * Author or manage.
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
 * Share eligibility — single source used by feed / sidebar / detail.
 * @param {Record<string, unknown>} topic
 */
export function canShareTopic(topic) {
    const state = topic?.state || 'draft';
    if (state !== 'draft') return false;
    const comments = Array.isArray(topic?.comments) ? topic.comments : [];
    return comments.length >= 1;
}

/**
 * @param {Record<string, unknown>} topic
 * @returns {'no_comments'|'ready'|'shared'|'completed'|'archived'}
 */
export function shareStatusOf(topic) {
    const state = topic?.state || 'draft';
    if (state === 'archived') return 'archived';
    if (state === 'completed') return 'completed';
    if (state === 'shared') return 'shared';
    if (canShareTopic(topic)) return 'ready';
    return 'no_comments';
}

export function shareStatusLabel(status) {
    if (status === 'ready') return 'Sẵn sàng chia sẻ';
    if (status === 'shared') return 'Đã chia sẻ';
    if (status === 'completed') return 'Hoàn tất';
    if (status === 'archived') return 'Lưu trữ';
    return 'Chưa có bình luận';
}
