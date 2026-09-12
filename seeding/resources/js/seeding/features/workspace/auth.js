/**
 * Topic authorization for React-first hybrid workspace.
 *
 * Draft (local): author may edit / share / delete.
 * Shared (DB): author cannot edit or Gen; others may Gen/report if eligible.
 */

/**
 * @param {Record<string, unknown>} topic
 * @param {number|string} userId
 * @param {boolean} canMutate
 */
export function canEditTopic(topic, userId, canMutate) {
    if (!canMutate || !topic) return false;
    const state = topic.state || (topic.id ? 'shared' : 'draft');
    if (state !== 'draft' && !String(topic.localId || '').startsWith('draft:')) return false;
    const owner = topic.created_by_user_id ?? topic.created_by;
    if (owner == null || owner === '') return false;
    return String(owner) === String(userId);
}

/**
 * @param {Record<string, unknown>} topic
 * @param {number|string} userId
 * @param {boolean} canMutate
 * @param {Array<Record<string, unknown>>} [reports]
 * @param {(topic: Record<string, unknown>, reports: Array<Record<string, unknown>>, extra?: object) => boolean} [hasWorkHistory]
 * @param {object} [extra]
 */
export function canDeleteTopic(topic, userId, canMutate, reports = [], hasWorkHistory = null, extra = {}) {
    if (!canMutate || !topic) return false;
    if (!canEditTopic(topic, userId, canMutate)) return false;
    if (typeof hasWorkHistory === 'function' && hasWorkHistory(topic, reports, extra)) return false;
    return true;
}

/**
 * Author may share local draft → DB commit (Manager).
 */
export function canShareDraftTopic(topic, userId, canMutate, isManager = false) {
    if (!isManager) return false;
    return canEditTopic(topic, userId, canMutate);
}

/**
 * Manager may create topics.
 */
export function canCreateTopic(isManager, canMutate) {
    return Boolean(isManager && canMutate);
}

/**
 * Gen comment eligibility — shared feed topics only; never own topic.
 * @param {Record<string, unknown>|null|undefined} topic
 * @param {{ hasWorkspaceAccess?: boolean, userId?: number|string }} [opts]
 */
export function canSeedTopic(topic, opts = {}) {
    const hasAccess = opts.hasWorkspaceAccess !== false;
    if (!hasAccess || !topic) return false;
    const state = topic.state || (topic.id ? 'shared' : 'draft');
    if (state === 'archived' || topic.is_archived) return false;
    if (state === 'draft' || String(topic.localId || '').startsWith('draft:')) return false;
    if (topic.id == null) return false;

    const owner = topic.created_by_user_id ?? topic.created_by;
    if (opts.userId != null && owner != null && String(owner) === String(opts.userId)) {
        return false;
    }

    const required = Number(topic.required_report_count ?? topic.required_comments_per_user ?? 0);
    const done = Number(topic.current_user_report_count ?? 0);
    if (required > 0 && done >= required) return false;
    if (topic.eligibility && topic.eligibility.eligible === false) return false;

    return true;
}

/** @deprecated */
export function canShareTopic(topic, opts = {}) {
    return canSeedTopic(topic, opts);
}

export function canManageOwnSeedLinks(hasWorkspaceAccess = true) {
    return hasWorkspaceAccess !== false;
}

/**
 * @param {Record<string, unknown>} topic
 * @returns {'ready'|'seeded'|'archived'|'draft'|'shared'|'done'}
 */
export function seedStatusOf(topic) {
    const state = topic?.state || (topic?.id ? 'shared' : 'draft');
    if (state === 'archived') return 'archived';
    if (state === 'draft' || String(topic?.localId || '').startsWith('draft:')) return 'draft';
    const required = Number(topic?.required_report_count ?? topic?.required_comments_per_user ?? 0);
    const done = Number(topic?.current_user_report_count ?? 0);
    if (required > 0 && done >= required) return 'done';
    if (done > 0) return 'seeded';
    return 'shared';
}

export function seedStatusLabel(status) {
    if (status === 'seeded') return 'Đang làm';
    if (status === 'archived') return 'Lưu trữ';
    if (status === 'draft') return 'Nháp';
    if (status === 'done') return 'Hoàn tất';
    if (status === 'shared') return 'Chia sẻ';
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
