export function eligibleRows(block) {
    if (!block || block.actionable?.action !== 'content_project.draft.intake') {
        return [];
    }
    return (block.rows || []).filter((row) => row?.item?.id && row.item.type);
}

export function selectedCount(selectedIds, rows) {
    const allowed = new Set(eligibleRows({ actionable: { action: 'content_project.draft.intake' }, rows }).map((row) => row.item.id));
    return [...selectedIds].filter((id) => allowed.has(id)).length;
}

export function toggleId(selectedIds, id) {
    const next = new Set(selectedIds);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    return next;
}

export function selectAllIds(rows) {
    return new Set(eligibleRows({ actionable: { action: 'content_project.draft.intake' }, rows }).map((row) => row.item.id));
}

export function intakeItems(rows, selectedIds) {
    const selected = new Set(selectedIds);
    return eligibleRows({ actionable: { action: 'content_project.draft.intake' }, rows })
        .filter((row) => selected.has(row.item.id))
        .map((row) => row.item);
}

export function statusLabel(status, message) {
    if (status === 'added') return 'Đã thêm vào Draft.';
    if (status === 'already_in_draft') return 'Đã có trong Draft.';
    return message ? `Không thêm được: ${message}` : 'Không thêm được.';
}

export function isCompleteSuccess(result) {
    return Boolean(result) && Number(result.failed || 0) === 0 && Number(result.added || 0) + Number(result.already_in_draft || 0) > 0;
}
