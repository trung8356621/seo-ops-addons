export function threadLoadIsCurrent(requestSeq, activeSeq, requestScope, activeScope) {
    return requestSeq === activeSeq && requestScope === activeScope;
}

export function scopeEntryAction(previousScope, nextScope, storedUlid) {
    if (!nextScope || previousScope === nextScope) {
        return 'ignore';
    }
    if (previousScope === null && storedUlid) {
        return 'restore';
    }
    return 'welcome';
}

export function rerunHidesTurn(turnId, rerunTargetId) {
    return rerunTargetId != null && rerunTargetId !== '' && String(turnId) === String(rerunTargetId);
}
