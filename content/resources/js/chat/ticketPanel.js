/**
 * Support ticket panel — local-first, attach/paste via TeamChatAttachmentService API.
 */
(function () {
    const ROOT_ID = 'seo-ticket-panel-root';

    function csrf() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
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

    function attachmentHtml(list) {
        if (!Array.isArray(list) || list.length === 0) {
            return '';
        }
        return `<ul class="mt-2 flex flex-wrap gap-2">${list.map((a) => {
            const name = escapeHtml(a.name || 'Tệp');
            const url = escapeAttr(a.url || '');
            if (a.is_image && url) {
                return `<li class="seo-ticket-attach">
                  <img class="seo-ticket-attach__img max-h-24 rounded border border-gray-200 dark:border-gray-700" src="${url}" alt="${name}" data-role="safe-img" />
                  <div class="seo-chat-img-placeholder" hidden data-role="img-fallback">Không tải được ảnh</div>
                </li>`;
            }
            if (url) {
                return `<li><a class="text-xs text-primary-600 underline" href="${url}" target="_blank" rel="noopener">${name}</a></li>`;
            }
            return `<li class="seo-chat-img-placeholder text-xs">${name} (không có URL)</li>`;
        }).join('')}</ul>`;
    }

    function bindSafeImages(root) {
        root.querySelectorAll('[data-role="safe-img"]').forEach((img) => {
            const wrap = img.closest('.seo-ticket-attach') || img.parentElement;
            const fallback = wrap?.querySelector('[data-role="img-fallback"]');
            img.addEventListener('error', () => {
                img.hidden = true;
                if (fallback) fallback.hidden = false;
            });
        });
    }

    async function boot() {
        const root = document.getElementById(ROOT_ID);
        if (!root || root.dataset.mounted === '1') return;
        root.dataset.mounted = '1';

        let props = {};
        try {
            props = JSON.parse(root.getAttribute('data-props') || '{}');
        } catch (_) {
            props = {};
        }

        const state = {
            title: '',
            body: '',
            files: [],
            submitting: false,
            notice: '',
            tickets: [],
            remoteEnabled: false,
        };

        async function loadList() {
            try {
                const res = await fetch(props.indexUrl, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const data = await res.json();
                state.tickets = Array.isArray(data.tickets) ? data.tickets : [];
                state.remoteEnabled = Boolean(data.remote_enabled);
            } catch (_) {
                // silent — form still usable
            }
            render();
        }

        function render() {
            const pending = state.files.map((f, i) => `
              <li class="inline-flex items-center gap-1 rounded bg-gray-100 px-2 py-1 text-xs dark:bg-gray-800">
                ${escapeHtml(f.name)}
                <button type="button" class="text-rose-600" data-remove-file="${i}" aria-label="Xóa">×</button>
              </li>`).join('');

            const list = state.tickets.map((t) => `
              <li class="rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                <div class="font-medium">${escapeHtml(t.title)}</div>
                <div class="mt-1 whitespace-pre-wrap text-xs text-gray-600 dark:text-gray-300">${escapeHtml(t.body || '')}</div>
                ${attachmentHtml(t.attachments)}
                <div class="text-xs text-gray-500 mt-1">#${t.id} · ${escapeHtml(t.status)}${t.sent_at ? ' · ' + escapeHtml(t.sent_at) : ''}</div>
                ${t.status !== 'sent' ? `<button type="button" class="mt-2 text-xs text-primary-600" data-retry="${t.id}">Retry gửi remote</button>` : ''}
                ${t.last_error ? `<div class="mt-1 text-xs text-rose-600">${escapeHtml(t.last_error)}</div>` : ''}
              </li>`).join('');

            root.innerHTML = `
              <div class="seo-ticket-panel max-w-2xl space-y-4 p-1">
                <p class="text-sm text-gray-600 dark:text-gray-300">Gửi lỗi/support về server. Ticket luôn được lưu cục bộ trước — máy chủ remote có thể offline. Dán ảnh (Ctrl+V) hoặc đính kèm tệp.</p>
                ${state.notice ? `<div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">${escapeHtml(state.notice)}</div>` : ''}
                <form class="space-y-3" data-role="form">
                  <div>
                    <label class="block text-sm font-medium mb-1">Tiêu đề</label>
                    <input required maxlength="200" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900" data-role="title" value="${escapeHtml(state.title)}" />
                  </div>
                  <div>
                    <label class="block text-sm font-medium mb-1">Nội dung lỗi</label>
                    <textarea required maxlength="10000" rows="8" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900" data-role="body" placeholder="Mô tả lỗi… có thể dán ảnh vào đây">${escapeHtml(state.body)}</textarea>
                  </div>
                  <div class="flex flex-wrap items-center gap-2">
                    <input type="file" multiple accept="${escapeAttr(props.accept || 'image/*,.pdf')}" class="hidden" data-role="file" />
                    <button type="button" class="rounded-lg bg-gray-100 px-3 py-2 text-sm dark:bg-gray-800" data-role="attach">Đính kèm</button>
                    <ul class="flex flex-wrap gap-2">${pending || ''}</ul>
                  </div>
                  <button type="submit" class="rounded-lg bg-primary-600 px-4 py-2 text-sm text-white" ${state.submitting ? 'disabled' : ''}>
                    ${state.submitting ? 'Đang lưu…' : 'Gửi ticket'}
                  </button>
                </form>
                <div>
                  <h3 class="text-sm font-semibold mb-2">Ticket gần đây</h3>
                  <ul class="space-y-2">${list || '<li class="text-sm text-gray-500">Chưa có ticket.</li>'}</ul>
                </div>
              </div>`;

            const form = root.querySelector('[data-role="form"]');
            form?.addEventListener('submit', onSubmit);
            root.querySelector('[data-role="title"]')?.addEventListener('input', (e) => { state.title = e.target.value; });
            const bodyEl = root.querySelector('[data-role="body"]');
            bodyEl?.addEventListener('input', (e) => { state.body = e.target.value; });
            bodyEl?.addEventListener('paste', onPaste);

            root.querySelector('[data-role="attach"]')?.addEventListener('click', () => {
                root.querySelector('[data-role="file"]')?.click();
            });
            root.querySelector('[data-role="file"]')?.addEventListener('change', (e) => {
                const picked = Array.from(e.target.files || []);
                addFiles(picked);
                e.target.value = '';
            });
            root.querySelectorAll('[data-remove-file]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const idx = Number(btn.getAttribute('data-remove-file'));
                    if (!Number.isNaN(idx)) {
                        state.files.splice(idx, 1);
                        render();
                    }
                });
            });
            root.querySelectorAll('[data-retry]').forEach((btn) => {
                btn.addEventListener('click', async () => {
                    const id = btn.getAttribute('data-retry');
                    const url = String(props.retryUrlTemplate || '').replace('__ID__', id);
                    try {
                        const res = await fetch(url, {
                            method: 'POST',
                            headers: {
                                Accept: 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': props.csrfToken || csrf(),
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            credentials: 'same-origin',
                            body: JSON.stringify({}),
                        });
                        const data = await res.json();
                        state.notice = data.message || 'Đã thử gửi lại.';
                        await loadList();
                    } catch (_) {
                        state.notice = 'Retry thất bại — ticket vẫn còn trên máy local.';
                        render();
                    }
                });
            });
            bindSafeImages(root);
        }

        function addFiles(files) {
            const maxBytes = Number(props.maxFileSizeBytes) || (5 * 1024 * 1024);
            const next = [...state.files];
            for (const file of files) {
                if (next.length >= 5) break;
                if (file.size > maxBytes) {
                    state.notice = 'Tệp vượt quá giới hạn kích thước.';
                    continue;
                }
                next.push(file);
            }
            state.files = next;
            render();
        }

        function onPaste(e) {
            const items = Array.from(e.clipboardData?.items || []);
            const imageFiles = [];
            items.forEach((item) => {
                if (item.kind === 'file' && String(item.type || '').startsWith('image/')) {
                    const file = item.getAsFile();
                    if (file) {
                        const ext = (file.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
                        imageFiles.push(new File([file], `paste-${Date.now()}.${ext}`, { type: file.type }));
                    }
                }
            });
            if (imageFiles.length > 0) {
                e.preventDefault();
                addFiles(imageFiles);
            }
        }

        async function onSubmit(e) {
            e.preventDefault();
            if (state.submitting) return;
            state.submitting = true;
            state.notice = '';
            render();
            try {
                const form = new FormData();
                form.append('title', state.title);
                form.append('body', state.body);
                form.append('page_url', props.pageUrl || window.location.href);
                state.files.forEach((file) => {
                    form.append('files[]', file);
                });
                const res = await fetch(props.storeUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': props.csrfToken || csrf(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: form,
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    throw new Error(data.message || ('HTTP ' + res.status));
                }
                state.notice = data.message || 'Đã lưu ticket cục bộ.';
                state.title = '';
                state.body = '';
                state.files = [];
                await loadList();
            } catch (err) {
                state.notice = err.message || 'Không gửi được — thử lại.';
                render();
            } finally {
                state.submitting = false;
                render();
            }
        }

        render();
        loadList();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
    document.addEventListener('livewire:navigated', () => {
        const root = document.getElementById(ROOT_ID);
        if (root) {
            delete root.dataset.mounted;
        }
        boot();
    });
})();
