const QUEUE_KEY = 'seo-ops:agent-routing-feedback:v1';
const SELECTION_KEY = 'seo-ops:agent-routing-review-selections:v1';
const MAX_PENDING = 100;
const MAX_SELECTIONS = 200;

export function readPendingFeedback(storage = window.localStorage) {
    try {
        const value = JSON.parse(storage.getItem(QUEUE_KEY) || '[]');
        return Array.isArray(value) ? value.filter((item) => item?.run_ulid) : [];
    } catch {
        return [];
    }
}

export function readReviewSelections(storage = window.localStorage) {
    try {
        const value = JSON.parse(storage.getItem(SELECTION_KEY) || '{}');
        return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
    } catch {
        return {};
    }
}

export function queueRoutingReview(runUlid, preferredCandidateId, storage = window.localStorage) {
    const pending = readPendingFeedback(storage).filter((item) => item.run_ulid !== runUlid);
    pending.push({
        run_ulid: runUlid,
        preferred_candidate_id: preferredCandidateId || null,
        none_of_above: preferredCandidateId === null,
        queued_at: new Date().toISOString(),
    });
    storage.setItem(QUEUE_KEY, JSON.stringify(pending.slice(-MAX_PENDING)));

    const selections = readReviewSelections(storage);
    delete selections[runUlid];
    selections[runUlid] = preferredCandidateId || '__none__';
    storage.setItem(SELECTION_KEY, JSON.stringify(Object.fromEntries(Object.entries(selections).slice(-MAX_SELECTIONS))));
}

export async function flushFeedback(url, csrf, storage = window.localStorage) {
    const pending = readPendingFeedback(storage);
    if (!url || pending.length === 0) return [];
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ items: pending.map(({ queued_at: _queuedAt, ...item }) => item) }),
    });
    if (!response.ok) throw new Error('Feedback delivery failed.');
    const payload = await response.json();
    const acknowledged = new Set(payload.acknowledged_run_ulids || []);
    storage.setItem(QUEUE_KEY, JSON.stringify(pending.filter((item) => !acknowledged.has(item.run_ulid))));
    return [...acknowledged];
}
