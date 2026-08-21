/**
 * Same-browser, same-user tab presence. Server edit leases intentionally do not
 * arbitrate tabs owned by the same user.
 */
export function createArticleEditorTabChannel({ articleId, userId, tabId, onPeer }) {
    if (typeof window === 'undefined' || typeof window.BroadcastChannel !== 'function') {
        return { supported: false, destroy() {} };
    }

    const normalizedArticleId = Number(articleId) || 0;
    const normalizedUserId = Number(userId) || 0;
    const normalizedTabId = String(tabId || '');
    if (normalizedArticleId <= 0 || normalizedUserId <= 0 || normalizedTabId === '') {
        return { supported: false, destroy() {} };
    }

    const channel = new window.BroadcastChannel(`article-editor-${normalizedArticleId}`);
    const publish = (type) => channel.postMessage({
        type,
        article_id: normalizedArticleId,
        user_id: normalizedUserId,
        tab_id: normalizedTabId,
        sent_at: Date.now(),
    });

    channel.onmessage = (event) => {
        const message = event?.data;
        if (
            Number(message?.article_id) !== normalizedArticleId
            || Number(message?.user_id) !== normalizedUserId
            || String(message?.tab_id || '') === normalizedTabId
        ) {
            return;
        }
        if (message?.type === 'hello') {
            publish('present');
        }
        if (message?.type === 'hello' || message?.type === 'present') {
            onPeer?.(message);
        }
    };

    publish('hello');

    return {
        supported: true,
        destroy() {
            publish('close');
            channel.close();
        },
    };
}

export default createArticleEditorTabChannel;
