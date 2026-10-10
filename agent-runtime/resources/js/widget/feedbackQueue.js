const STORAGE_KEY = 'seo-ops:agent-routing-feedback:v1';
const MAX_PENDING = 100;

export function readPendingFeedback(storage = window.localStorage) {
    try {
        const value = JSON.parse(storage.getItem(STORAGE_KEY) || '[]');
        return Array.isArray(value) ? value.filter((item) => item?.run_ulid && typeof item.rating === 'boolean') : [];
    } catch {
        return [];
    }
}

export function queueFeedback(runUlid, rating, storage = window.localStorage) {
    const pending = readPendingFeedback(storage).filter((item) => item.run_ulid !== runUlid);
    pending.push({ run_ulid: runUlid, rating, queued_at: new Date().toISOString() });
    storage.setItem(STORAGE_KEY, JSON.stringify(pending.slice(-MAX_PENDING)));
}

export async function flushFeedback(url, csrf, storage = window.localStorage) {
    const pending = readPendingFeedback(storage);
    if (!url || pending.length === 0) return [];
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ items: pending.map(({ run_ulid, rating }) => ({ run_ulid, rating })) }),
    });
    if (!response.ok) throw new Error('Feedback delivery failed.');
    const payload = await response.json();
    const acknowledged = new Set(payload.acknowledged_run_ulids || []);
    storage.setItem(STORAGE_KEY, JSON.stringify(pending.filter((item) => !acknowledged.has(item.run_ulid))));
    return [...acknowledged];
}
