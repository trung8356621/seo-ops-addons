export function threadTitleFromMessage(message) {
    const title = String(message || '').trim().replace(/\s+/g, ' ').slice(0, 80);
    return title || 'Untitled conversation';
}

export function prependThread(threads, thread) {
    const list = Array.isArray(threads) ? threads : [];
    const ulid = thread?.ulid;
    if (!ulid || list.some((item) => item?.ulid === ulid)) {
        return list;
    }
    return [thread, ...list];
}

export function archiveThreadLocally(activeThreads, archivedThreads, ulid) {
    const active = Array.isArray(activeThreads) ? activeThreads : [];
    const archived = Array.isArray(archivedThreads) ? archivedThreads : [];
    const thread = active.find((item) => item?.ulid === ulid);
    return {
        active: active.filter((item) => item?.ulid !== ulid),
        archived: thread
            ? [{ ...thread, status: 'archived' }, ...archived.filter((item) => item?.ulid !== ulid)]
            : archived,
    };
}
