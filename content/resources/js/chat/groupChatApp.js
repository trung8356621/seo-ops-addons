/**
 * Group chat client — JS-owned state, JSON API only.
 * Single-inflight poller, optimistic send, delta after_id.
 */
(function () {
    const ROOT_ID = 'seo-group-chat-root';

    function csrf() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    function createStore(props) {
        return {
            messages: [],
            lastMessageId: 0,
            initialLoading: true,
            refreshing: false,
            sending: false,
            failedMessages: {},
            unreadWhileScrolled: 0,
            draft: '',
            file: null,
            stickToBottom: true,
            statusText: '',
            pollTimer: null,
            inflight: false,
            destroyed: false,
            props,
        };
    }

    function maxId(messages) {
        return messages.reduce((m, row) => Math.max(m, Number(row.id) || 0), 0);
    }

    async function apiGet(url) {
        const res = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!res.ok) {
            throw new Error('HTTP ' + res.status);
        }
        return res.json();
    }

    async function apiPost(url, body, isForm) {
        const headers = {
            Accept: 'application/json',
            'X-CSRF-TOKEN': body?.csrfToken || csrf(),
            'X-Requested-With': 'XMLHttpRequest',
        };
        let payload = body;
        if (isForm) {
            payload = body;
        } else {
            headers['Content-Type'] = 'application/json';
            payload = JSON.stringify(body);
        }
        const res = await fetch(url, {
            method: 'POST',
            headers,
            credentials: 'same-origin',
            body: payload,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            const err = new Error(data.message || ('HTTP ' + res.status));
            err.payload = data;
            throw err;
        }
        return data;
    }

    function mergeMessages(store, incoming) {
        if (!Array.isArray(incoming) || incoming.length === 0) {
            return 0;
        }
        const byKey = new Map();
        store.messages.forEach((m) => {
            const key = m.client_key || ('id:' + m.id);
            byKey.set(key, m);
        });
        let added = 0;
        incoming.forEach((row) => {
            const id = Number(row.id) || 0;
            const key = id > 0 ? 'id:' + id : (row.client_key || ('tmp:' + Math.random()));
            if (!byKey.has(key) && id > 0) {
                // Drop matching optimistic temp
                for (const [k, v] of byKey.entries()) {
                    if (String(k).startsWith('tmp:') && v.message === row.message && v._status === 'sending') {
                        byKey.delete(k);
                    }
                }
                added += 1;
            }
            byKey.set(key, { ...row, _status: row._status || 'sent' });
        });
        store.messages = Array.from(byKey.values()).sort((a, b) => (Number(a.id) || 0) - (Number(b.id) || 0));
        store.lastMessageId = Math.max(store.lastMessageId, maxId(store.messages));
        return added;
    }

    function nearBottom(el) {
        if (!el) return true;
        return el.scrollHeight - el.scrollTop - el.clientHeight < 80;
    }

    function render(store, root) {
        const list = store.messages.map((m) => {
            const mine = m.is_mine || m.user_id === store.props.currentUserId;
            const status = m._status || 'sent';
            const failed = status === 'failed';
            const sending = status === 'sending';
            return `
              <div class="seo-group-chat__row ${mine ? 'is-mine' : ''}" data-id="${m.id || ''}" data-client-key="${m.client_key || ''}">
                <div class="seo-group-chat__bubble">
                  ${!mine ? `<div class="seo-group-chat__name">${escapeHtml(m.user_name || 'Thành viên')}</div>` : ''}
                  <div class="seo-group-chat__text">${escapeHtml(m.message || '')}</div>
                  ${m.attachment_url ? (
                    m.attachment_is_image
                      ? `<div class="seo-group-chat__img-wrap">
                           <a href="${escapeAttr(m.attachment_url)}" target="_blank" rel="noopener">
                             <img class="seo-group-chat__img" src="${escapeAttr(m.attachment_url)}" alt="" data-role="safe-img" />
                           </a>
                           <div class="seo-chat-img-placeholder" hidden data-role="img-fallback">Không tải được ảnh đính kèm</div>
                         </div>`
                      : `<a class="seo-group-chat__file" href="${escapeAttr(m.attachment_url)}" target="_blank" rel="noopener">${escapeHtml(m.attachment_name || 'Tệp')}</a>`
                  ) : (m.attachment_name ? `<div class="seo-chat-img-placeholder">Tệp đính kèm không khả dụng</div>` : '')}
                  <div class="seo-group-chat__meta">
                    ${sending ? 'Đang gửi…' : ''}
                    ${failed ? `<button type="button" data-retry="${escapeAttr(m.client_key || '')}" class="seo-group-chat__retry">Retry</button>` : ''}
                  </div>
                </div>
              </div>`;
        }).join('');

        root.innerHTML = `
          <div class="seo-group-chat seo-global-chat flex h-full min-h-0 flex-col">
            <div class="seo-group-chat__status text-xs text-gray-500 px-2 py-1">${escapeHtml(store.statusText || (store.initialLoading ? 'Đang tải…' : (store.refreshing ? 'Đang cập nhật…' : '')))}</div>
            <div class="seo-group-chat__messages flex-1 min-h-0 overflow-y-auto px-3 py-2 space-y-2" data-role="messages">
              ${store.initialLoading ? '<div class="animate-pulse h-16 bg-gray-100 rounded dark:bg-gray-800"></div>' : list}
            </div>
            ${store.unreadWhileScrolled > 0 ? `<button type="button" class="seo-group-chat__jump" data-role="jump">${store.unreadWhileScrolled} tin nhắn mới ↓</button>` : ''}
            <form class="seo-group-chat__composer flex gap-2 border-t border-gray-200 p-2 dark:border-gray-700" data-role="composer">
              <input type="file" accept="${escapeAttr(store.props.accept || '')}" class="hidden" data-role="file" />
              <button type="button" class="rounded-lg px-2 text-sm bg-gray-100 dark:bg-gray-800" data-role="attach">📎</button>
              <input type="text" class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900" placeholder="Nhắn tin nhóm…" value="${escapeAttr(store.draft)}" data-role="draft" ${store.sending && false ? 'disabled' : ''} />
              <button type="submit" class="rounded-lg bg-primary-600 px-3 py-2 text-sm text-white" data-role="send">Gửi</button>
            </form>
          </div>`;

        bind(store, root);
        root.querySelectorAll('[data-role="safe-img"]').forEach((img) => {
            const wrap = img.closest('.seo-group-chat__img-wrap') || img.parentElement;
            const fallback = wrap?.querySelector('[data-role="img-fallback"]');
            img.addEventListener('error', () => {
                img.hidden = true;
                const link = img.closest('a');
                if (link) link.hidden = true;
                if (fallback) fallback.hidden = false;
            });
        });
        const scroller = root.querySelector('[data-role="messages"]');
        if (store.stickToBottom && scroller) {
            scroller.scrollTop = scroller.scrollHeight;
        }
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function escapeAttr(s) {
        return escapeHtml(s).replace(/'/g, '&#39;');
    }

    function bind(store, root) {
        const scroller = root.querySelector('[data-role="messages"]');
        if (scroller) {
            scroller.addEventListener('scroll', () => {
                store.stickToBottom = nearBottom(scroller);
                if (store.stickToBottom && store.unreadWhileScrolled > 0) {
                    store.unreadWhileScrolled = 0;
                    markRead(store);
                    render(store, root);
                }
            });
        }

        root.querySelector('[data-role="jump"]')?.addEventListener('click', () => {
            store.stickToBottom = true;
            store.unreadWhileScrolled = 0;
            markRead(store);
            render(store, root);
        });

        const draft = root.querySelector('[data-role="draft"]');
        draft?.addEventListener('input', (e) => {
            store.draft = e.target.value;
        });

        root.querySelector('[data-role="attach"]')?.addEventListener('click', () => {
            root.querySelector('[data-role="file"]')?.click();
        });
        root.querySelector('[data-role="file"]')?.addEventListener('change', (e) => {
            store.file = e.target.files?.[0] || null;
        });

        root.querySelector('[data-role="composer"]')?.addEventListener('submit', (e) => {
            e.preventDefault();
            send(store, root);
        });

        root.querySelectorAll('[data-retry]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const key = btn.getAttribute('data-retry');
                const msg = store.messages.find((m) => m.client_key === key);
                if (msg) {
                    retry(store, root, msg);
                }
            });
        });
    }

    async function markRead(store) {
        if (!store.lastMessageId || !store.props.markReadUrl) return;
        try {
            await apiPost(store.props.markReadUrl, {
                last_read_message_id: store.lastMessageId,
                csrfToken: store.props.csrfToken,
            });
        } catch (_) {
            // silent
        }
    }

    async function loadInitial(store, root) {
        store.initialLoading = true;
        render(store, root);
        try {
            const url = store.props.messagesUrl + '?poll=1&after_id=0';
            const data = await apiGet(url);
            mergeMessages(store, data.messages || []);
            store.statusText = '';
            await markRead(store);
        } catch (e) {
            store.statusText = 'Không tải được tin nhắn. Giữ nguyên danh sách hiện có.';
        } finally {
            store.initialLoading = false;
            render(store, root);
        }
    }

    async function pollOnce(store, root) {
        if (store.destroyed || store.inflight || document.hidden) {
            schedule(store, root);
            return;
        }
        store.inflight = true;
        store.refreshing = true;
        try {
            const after = Math.max(0, store.lastMessageId);
            const url = store.props.messagesUrl + '?poll=1&after_id=' + encodeURIComponent(String(after));
            const data = await apiGet(url);
            const added = mergeMessages(store, data.messages || []);
            if (added > 0) {
                if (store.stickToBottom) {
                    await markRead(store);
                } else {
                    store.unreadWhileScrolled += added;
                }
                render(store, root);
            }
            store.statusText = '';
        } catch (_) {
            store.statusText = 'Tạm mất kết nối — sẽ thử lại.';
            render(store, root);
        } finally {
            store.inflight = false;
            store.refreshing = false;
            schedule(store, root);
        }
    }

    function schedule(store, root) {
        if (store.destroyed) return;
        if (store.pollTimer) {
            clearTimeout(store.pollTimer);
            store.pollTimer = null;
        }
        const hidden = document.hidden;
        const ms = hidden ? 60000 : (Number(store.props.pollIntervalMs) || 15000);
        if (hidden) {
            // stop aggressive polling while hidden — one check at 60s max
        }
        store.pollTimer = setTimeout(() => pollOnce(store, root), ms);
    }

    async function send(store, root) {
        const text = String(store.draft || '').trim();
        if (!text && !store.file) return;

        const clientKey = 'tmp:' + Date.now() + ':' + Math.random().toString(36).slice(2, 8);
        const optimistic = {
            id: 0,
            client_key: clientKey,
            user_id: store.props.currentUserId,
            user_name: 'Bạn',
            message: text,
            is_mine: true,
            _status: 'sending',
            _draft: text,
            _file: store.file,
        };
        store.messages.push(optimistic);
        store.draft = '';
        store.file = null;
        store.stickToBottom = true;
        render(store, root);

        await postMessage(store, root, optimistic);
    }

    async function retry(store, root, msg) {
        msg._status = 'sending';
        render(store, root);
        await postMessage(store, root, msg);
    }

    async function postMessage(store, root, msg) {
        const form = new FormData();
        form.append('message', msg._draft || msg.message || '');
        if (msg._file) {
            form.append('file', msg._file);
        }
        try {
            const res = await fetch(store.props.storeUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': store.props.csrfToken || csrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: form,
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                throw new Error(data.message || ('HTTP ' + res.status));
            }
            store.messages = store.messages.filter((m) => m.client_key !== msg.client_key);
            mergeMessages(store, [data.message]);
            await markRead(store);
            render(store, root);
        } catch (e) {
            msg._status = 'failed';
            store.statusText = e.message || 'Gửi thất bại';
            render(store, root);
        }
    }

    function mount(root) {
        let props = {};
        try {
            props = JSON.parse(root.getAttribute('data-props') || '{}');
        } catch (_) {
            props = {};
        }
        const store = createStore(props);
        const onVisible = () => {
            if (!document.hidden) {
                pollOnce(store, root);
            }
        };
        document.addEventListener('visibilitychange', onVisible);
        store._onVisible = onVisible;

        loadInitial(store, root).then(() => schedule(store, root));

        root._seoGroupChatDestroy = () => {
            store.destroyed = true;
            if (store.pollTimer) clearTimeout(store.pollTimer);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }

    function boot() {
        const root = document.getElementById(ROOT_ID);
        if (!root || root.dataset.mounted === '1') return;
        root.dataset.mounted = '1';
        mount(root);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    document.addEventListener('livewire:navigated', boot);
})();
