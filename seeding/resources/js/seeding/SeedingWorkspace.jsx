import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Archive, ArchiveRestore, Copy, ExternalLink, Plus, X } from 'lucide-react';
import { buildTopicUrl, buildTopicsUrl, seedingApiFetch } from './api';
import {
    makeLocalDraftId,
    migrateTopicCache,
    previewText,
    readTopicCache,
    readWorkspaceState,
    removeTopicCache,
    topicStorageKey,
    writeTopicCache,
    writeWorkspaceState,
} from './storage';

const AUTOSAVE_MS = 700;
const EVENT_NAME = 'domain-context-changed';
const LEGACY_EVENT = 'seoGlobalSiteChanged';

function emptyTopic(localId) {
    return {
        localId,
        id: null,
        full_text: '',
        source_html: null,
        social_url: '',
        status: 'draft',
        status_label: 'DRAFT',
        social_platform: null,
        social_platform_label: null,
        links: [],
        links_count: 0,
        archived_at: null,
        is_archived: false,
        preview: 'Chủ đề mới',
        updated_at: null,
        server_synced_at: null,
    };
}

function statusBadgeClass(status) {
    if (status === 'active') return 'seeding-ws__badge seeding-ws__badge--active';
    if (status === 'done') return 'seeding-ws__badge seeding-ws__badge--done';
    return 'seeding-ws__badge seeding-ws__badge--draft';
}

function mergeServerTopic(prev, server) {
    return {
        ...prev,
        ...server,
        localId: prev?.localId && !server.id ? prev.localId : undefined,
        social_url: server.social_url ?? '',
        source_html: server.source_html ?? null,
        links: Array.isArray(server.links) ? server.links : [],
        server_synced_at: server.updated_at ?? new Date().toISOString(),
    };
}

export default function SeedingWorkspace({ siteId: initialSiteId, apiBase, canMutate = true }) {
    const [siteId, setSiteId] = useState(initialSiteId);
    const [topics, setTopics] = useState([]);
    const [localDrafts, setLocalDrafts] = useState([]);
    const [archivedCount, setArchivedCount] = useState(0);
    const [showArchived, setShowArchived] = useState(false);
    const [search, setSearch] = useState('');
    const [selectedKey, setSelectedKey] = useState(null);
    const [selected, setSelected] = useState(null);
    const [saveState, setSaveState] = useState('idle'); // idle | dirty | saving | saved | error
    const [toast, setToast] = useState(null);
    const [loadingList, setLoadingList] = useState(false);

    const saveTimer = useRef(null);
    const selectedRef = useRef(null);
    const toastTimer = useRef(null);
    const creatingRef = useRef(false);

    useEffect(() => {
        selectedRef.current = selected;
    }, [selected]);

    useEffect(() => {
        setSiteId(initialSiteId);
    }, [initialSiteId]);

    useEffect(() => {
        const onDomain = (event) => {
            const next = Number(event?.detail?.siteId ?? event?.detail?.site_id ?? 0);
            if (Number.isFinite(next) && next > 0) {
                setSiteId(next);
            }
        };
        window.addEventListener(EVENT_NAME, onDomain);
        window.addEventListener(LEGACY_EVENT, onDomain);
        window.addEventListener('seeding-site-changed', onDomain);
        return () => {
            window.removeEventListener(EVENT_NAME, onDomain);
            window.removeEventListener(LEGACY_EVENT, onDomain);
            window.removeEventListener('seeding-site-changed', onDomain);
        };
    }, []);

    const showToast = useCallback((message, action = null) => {
        if (toastTimer.current) {
            clearTimeout(toastTimer.current);
        }
        setToast({ message, action });
        toastTimer.current = setTimeout(() => setToast(null), 4500);
    }, []);

    const persistWorkspace = useCallback(
        (partial) => {
            if (!siteId) return;
            const current = readWorkspaceState(siteId);
            writeWorkspaceState(siteId, { ...current, ...partial });
        },
        [siteId],
    );

    const loadList = useCallback(
        async (archived = showArchived) => {
            if (!siteId || !apiBase) {
                setTopics([]);
                return;
            }
            setLoadingList(true);
            try {
                const data = await seedingApiFetch(buildTopicsUrl(apiBase, siteId, archived));
                setTopics(Array.isArray(data?.topics) ? data.topics : []);
                setArchivedCount(Number(data?.archived_count ?? 0));
            } catch (error) {
                console.warn('seeding list failed', error);
                showToast(error.message || 'Không tải được danh sách');
            } finally {
                setLoadingList(false);
            }
        },
        [apiBase, showArchived, showToast, siteId],
    );

    useEffect(() => {
        if (!siteId) {
            setTopics([]);
            setLocalDrafts([]);
            setSelected(null);
            setSelectedKey(null);
            return;
        }
        const ws = readWorkspaceState(siteId);
        setSearch(ws.search || '');
        setShowArchived(Boolean(ws.showArchived));
        setSelectedKey(ws.selectedTopicId ? String(ws.selectedTopicId) : null);
        setLocalDrafts([]);
        loadList(Boolean(ws.showArchived));
    }, [siteId]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        if (!siteId) return;
        loadList(showArchived);
        persistWorkspace({ showArchived, search, selectedTopicId: selectedKey });
    }, [showArchived]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        if (!siteId || !selectedKey) {
            return;
        }
        const cached = readTopicCache(siteId, selectedKey);
        const fromList = topics.find((t) => String(t.id) === String(selectedKey));
        if (String(selectedKey).startsWith('draft:')) {
            const fromLocal = localDrafts.find((t) => t.localId === selectedKey);
            setSelected(
                mergeServerTopic(
                    emptyTopic(selectedKey),
                    { ...(fromLocal || {}), ...(cached || {}), localId: selectedKey, id: null },
                ),
            );
            return;
        }
        if (cached && (!fromList || (cached.local_updated_at && cached.full_text !== fromList.full_text))) {
            setSelected(mergeServerTopic(emptyTopic(selectedKey), { ...fromList, ...cached, id: fromList?.id ?? cached.id ?? null }));
            return;
        }
        if (fromList) {
            setSelected(mergeServerTopic(emptyTopic(String(fromList.id)), fromList));
        }
    }, [selectedKey, siteId, topics, localDrafts]);

    const filteredTopics = useMemo(() => {
        const q = search.trim().toLowerCase();
        const server = topics;
        const locals = showArchived ? [] : localDrafts;
        const merged = [...locals, ...server];
        if (q === '') return merged;
        return merged.filter((t) => {
            const hay = `${t.preview || ''} ${t.full_text || ''}`.toLowerCase();
            return hay.includes(q);
        });
    }, [localDrafts, search, showArchived, topics]);

    const upsertSidebarTopic = useCallback((topic) => {
        if (!topic?.id) return;
        setTopics((prev) => {
            const without = prev.filter((t) => t.id !== topic.id);
            if (topic.is_archived && !showArchived) {
                return without;
            }
            if (!topic.is_archived && showArchived) {
                return without;
            }
            return [topic, ...without].sort((a, b) => (b.id || 0) - (a.id || 0));
        });
    }, [showArchived]);

    const flushSave = useCallback(async () => {
        const topic = selectedRef.current;
        if (!topic || !siteId || !canMutate || !apiBase) {
            return;
        }

        const payload = {
            site_id: siteId,
            full_text: topic.full_text ?? '',
            source_html: topic.source_html ?? null,
            social_url: topic.social_url ? topic.social_url : null,
        };

        setSaveState('saving');
        try {
            let data;
            if (!topic.id) {
                if (creatingRef.current) {
                    return;
                }
                creatingRef.current = true;
                data = await seedingApiFetch(apiBase, {
                    method: 'POST',
                    body: JSON.stringify(payload),
                });
                creatingRef.current = false;
                const serverTopic = data.topic;
                const localKey = topicStorageKey(topic);
                const merged = mergeServerTopic(topic, serverTopic);
                setSelected(merged);
                selectedRef.current = merged;
                setSelectedKey(String(serverTopic.id));
                setLocalDrafts((list) => list.filter((d) => d.localId !== localKey));
                migrateTopicCache(siteId, localKey, String(serverTopic.id), merged);
                persistWorkspace({ selectedTopicId: String(serverTopic.id) });
                upsertSidebarTopic(serverTopic);
                if (typeof data.archived_count === 'number') {
                    setArchivedCount(data.archived_count);
                }
            } else {
                data = await seedingApiFetch(buildTopicUrl(apiBase, topic.id, siteId), {
                    method: 'PATCH',
                    body: JSON.stringify(payload),
                });
                const serverTopic = data.topic;
                const merged = mergeServerTopic(topic, serverTopic);
                // last-write-wins: keep newer local text if user typed during request
                const latest = selectedRef.current;
                if (latest && latest.id === serverTopic.id && latest.full_text !== topic.full_text) {
                    merged.full_text = latest.full_text;
                    merged.source_html = latest.source_html;
                    setSaveState('dirty');
                    writeTopicCache(siteId, String(serverTopic.id), merged);
                    setSelected(merged);
                    upsertSidebarTopic({ ...serverTopic, preview: previewText(merged.full_text), full_text: merged.full_text });
                    return;
                }
                setSelected(merged);
                selectedRef.current = merged;
                writeTopicCache(siteId, String(serverTopic.id), merged);
                upsertSidebarTopic(serverTopic);
                if (typeof data.archived_count === 'number') {
                    setArchivedCount(data.archived_count);
                }
            }
            setSaveState('saved');
        } catch (error) {
            creatingRef.current = false;
            console.warn('seeding autosave failed', error);
            setSaveState('error');
            showToast(error.message || 'Lưu thất bại — dữ liệu vẫn giữ local');
        }
    }, [apiBase, canMutate, persistWorkspace, showToast, siteId, upsertSidebarTopic]);

    const scheduleSave = useCallback(() => {
        if (!canMutate) return;
        setSaveState('dirty');
        if (saveTimer.current) {
            clearTimeout(saveTimer.current);
        }
        saveTimer.current = setTimeout(() => {
            flushSave();
        }, AUTOSAVE_MS);
    }, [canMutate, flushSave]);

    useEffect(() => () => {
        if (saveTimer.current) clearTimeout(saveTimer.current);
        if (toastTimer.current) clearTimeout(toastTimer.current);
    }, []);

    const selectTopic = (topic) => {
        const key = topic.id ? String(topic.id) : (topic.localId || makeLocalDraftId());
        setSelectedKey(key);
        persistWorkspace({ selectedTopicId: key, search, showArchived });
    };

    const createTopic = () => {
        if (!siteId || !canMutate) return;
        const localId = makeLocalDraftId();
        const draft = emptyTopic(localId);
        writeTopicCache(siteId, localId, draft);
        setLocalDrafts((list) => [draft, ...list.filter((d) => d.localId !== localId)]);
        setSelected(draft);
        selectedRef.current = draft;
        setSelectedKey(localId);
        persistWorkspace({ selectedTopicId: localId, showArchived: false });
        if (showArchived) {
            setShowArchived(false);
        }
        setSaveState('idle');
    };

    const patchSelected = (partial, { autosave = true } = {}) => {
        setSelected((prev) => {
            if (!prev) return prev;
            const next = {
                ...prev,
                ...partial,
                preview: previewText(partial.full_text !== undefined ? partial.full_text : prev.full_text),
            };
            selectedRef.current = next;
            if (siteId) {
                writeTopicCache(siteId, topicStorageKey(next), next);
            }
            if (next.id) {
                setTopics((list) =>
                    list.map((t) =>
                        t.id === next.id
                            ? {
                                ...t,
                                preview: next.preview,
                                status: next.status,
                                full_text: next.full_text,
                                social_url: next.social_url,
                            }
                            : t,
                    ),
                );
            } else if (next.localId) {
                setLocalDrafts((list) => {
                    const others = list.filter((d) => d.localId !== next.localId);
                    return [next, ...others];
                });
            }
            return next;
        });
        if (autosave) {
            scheduleSave();
        }
    };

    const onPasteContent = (event) => {
        const html = event.clipboardData?.getData('text/html');
        if (html && html.trim() !== '') {
            // Keep default paste for plain text into textarea; stash HTML separately.
            patchSelected({ source_html: html }, { autosave: false });
            // schedule after React applies plain text from paste
            setTimeout(() => scheduleSave(), 0);
        }
    };

    const onCopy = async () => {
        const text = selectedRef.current?.full_text ?? '';
        try {
            await navigator.clipboard.writeText(text);
            showToast('Đã copy');
        } catch {
            showToast('Không copy được');
        }
    };

    const archiveTopic = async (topic, event) => {
        event?.stopPropagation?.();
        if (!topic?.id || !canMutate) return;
        const prevList = topics;
        const prevCount = archivedCount;
        setTopics((list) => list.filter((t) => t.id !== topic.id));
        setArchivedCount((c) => c + 1);
        if (selected?.id === topic.id) {
            setSelected((s) => (s ? { ...s, is_archived: true, archived_at: new Date().toISOString() } : s));
        }
        try {
            const data = await seedingApiFetch(buildTopicUrl(apiBase, topic.id, siteId), {
                method: 'PATCH',
                body: JSON.stringify({ site_id: siteId, archived: true }),
            });
            if (typeof data.archived_count === 'number') {
                setArchivedCount(data.archived_count);
            }
            showToast('Đã lưu trữ chủ đề', {
                label: 'Hoàn tác',
                onClick: async () => {
                    await restoreTopic(topic);
                },
            });
        } catch (error) {
            setTopics(prevList);
            setArchivedCount(prevCount);
            showToast(error.message || 'Archive thất bại');
        }
    };

    const restoreTopic = async (topic) => {
        if (!topic?.id || !canMutate) return;
        try {
            const data = await seedingApiFetch(buildTopicUrl(apiBase, topic.id, siteId), {
                method: 'PATCH',
                body: JSON.stringify({ site_id: siteId, archived: false }),
            });
            if (typeof data.archived_count === 'number') {
                setArchivedCount(data.archived_count);
            }
            if (showArchived) {
                setTopics((list) => list.filter((t) => t.id !== topic.id));
            } else {
                upsertSidebarTopic(data.topic);
            }
            if (selected?.id === topic.id) {
                setSelected(mergeServerTopic(selected, data.topic));
            }
            showToast('Đã khôi phục chủ đề');
        } catch (error) {
            showToast(error.message || 'Khôi phục thất bại');
        }
    };

    if (!siteId) {
        return <div className="seeding-ws__empty">Chọn domain để mở Seeding Topic V2.</div>;
    }

    return (
        <div className="seeding-ws">
            <aside className="seeding-ws__sidebar">
                <div className="seeding-ws__sidebar-head">
                    <h2>Chủ đề</h2>
                    <button
                        type="button"
                        className="seeding-ws__btn seeding-ws__btn--primary"
                        onClick={createTopic}
                        disabled={!canMutate}
                        title="Chủ đề mới"
                    >
                        <Plus size={14} />
                        Chủ đề
                    </button>
                </div>
                <div className="seeding-ws__search">
                    <input
                        value={search}
                        onChange={(e) => {
                            const value = e.target.value;
                            setSearch(value);
                            persistWorkspace({ search: value, selectedTopicId: selectedKey, showArchived });
                        }}
                        placeholder="Tìm…"
                    />
                </div>
                <div className="seeding-ws__list">
                    <div className="seeding-ws__section-label">
                        {showArchived ? 'Archived' : 'Đang làm'}
                        {loadingList ? ' · …' : ''}
                    </div>
                    {filteredTopics.length === 0 ? (
                        <div className="seeding-ws__empty" style={{ padding: '0.75rem' }}>
                            {showArchived ? 'Không có topic đã lưu trữ.' : 'Chưa có chủ đề.'}
                        </div>
                    ) : (
                        filteredTopics.map((topic) => {
                            const key = topic.id ? String(topic.id) : topic.localId;
                            const active = selectedKey === key;
                            return (
                                <div key={key} style={{ display: 'grid', gridTemplateColumns: '1fr auto', gap: 2 }}>
                                    <button
                                        type="button"
                                        className={`seeding-ws__row${active ? ' is-active' : ''}`}
                                        onClick={() => selectTopic(topic)}
                                    >
                                        <div>
                                            <div className="seeding-ws__row-title">{previewText(topic.full_text || topic.preview)}</div>
                                            <div className="seeding-ws__row-meta">
                                                <span className={statusBadgeClass(topic.status)}>{topic.status_label || topic.status}</span>
                                            </div>
                                        </div>
                                    </button>
                                    {canMutate && topic.id ? (
                                        showArchived ? (
                                            <button
                                                type="button"
                                                className="seeding-ws__icon-btn"
                                                title="Khôi phục"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    restoreTopic(topic);
                                                }}
                                            >
                                                <ArchiveRestore size={14} />
                                            </button>
                                        ) : (
                                            <button
                                                type="button"
                                                className="seeding-ws__icon-btn"
                                                title="Archive"
                                                onClick={(e) => archiveTopic(topic, e)}
                                            >
                                                <Archive size={14} />
                                            </button>
                                        )
                                    ) : (
                                        <span />
                                    )}
                                </div>
                            );
                        })
                    )}
                </div>
                <button
                    type="button"
                    className="seeding-ws__archived-toggle"
                    onClick={() => setShowArchived((v) => !v)}
                >
                    {showArchived ? '← Quay lại work queue' : `Archived (${archivedCount})`}
                </button>
            </aside>

            <main className="seeding-ws__main">
                {!selected ? (
                    <div className="seeding-ws__empty">Chọn chủ đề hoặc bấm + Chủ đề.</div>
                ) : (
                    <>
                        <div className="seeding-ws__main-head">
                            <div>
                                <h3 className="seeding-ws__main-title">{previewText(selected.full_text, 80)}</h3>
                                <div className="seeding-ws__row-meta" style={{ marginTop: 4 }}>
                                    <span className={statusBadgeClass(selected.status)}>{selected.status_label || selected.status}</span>
                                    {selected.social_platform_label ? (
                                        <span style={{ fontSize: 12, color: '#6b7280' }}>{selected.social_platform_label}</span>
                                    ) : null}
                                </div>
                            </div>
                            {canMutate && selected.id ? (
                                showArchived || selected.is_archived ? (
                                    <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={() => restoreTopic(selected)}>
                                        <ArchiveRestore size={14} /> Khôi phục
                                    </button>
                                ) : (
                                    <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={(e) => archiveTopic(selected, e)}>
                                        <Archive size={14} /> Archive
                                    </button>
                                )
                            ) : null}
                        </div>

                        {selected.status === 'active' ? (
                            <div className="seeding-ws__warn">
                                Nội dung này đã được đăng lên social. Thay đổi tại đây chỉ cập nhật dữ liệu trong hệ thống.
                            </div>
                        ) : null}

                        <div>
                            <div className="seeding-ws__social-label" style={{ marginBottom: 6 }}>Nội dung</div>
                            <textarea
                                className="seeding-ws__textarea"
                                value={selected.full_text ?? ''}
                                disabled={!canMutate}
                                onChange={(e) => patchSelected({ full_text: e.target.value })}
                                onPaste={onPasteContent}
                                placeholder="Dán / nhập nội dung bài social…"
                            />
                            <div className="seeding-ws__links" style={{ marginTop: 6 }}>
                                <span>
                                    {selected.links_count ?? selected.links?.length ?? 0} link
                                    {saveState === 'saving' ? ' · đang cập nhật…' : ''}
                                    {saveState === 'error' ? ' · lỗi sync' : ''}
                                </span>
                                {(selected.links || []).slice(0, 6).map((link) => (
                                    <a
                                        key={link.id || link.normalized_url}
                                        className="seeding-ws__chip"
                                        href={link.normalized_url || link.original_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        title={link.normalized_url || link.original_url}
                                    >
                                        {link.domain || link.normalized_url}
                                    </a>
                                ))}
                            </div>
                        </div>

                        <div className="seeding-ws__social">
                            <div className="seeding-ws__social-label">Bài social</div>
                            <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                                <input
                                    className="seeding-ws__field"
                                    type="url"
                                    placeholder="https://…"
                                    value={selected.social_url ?? ''}
                                    disabled={!canMutate}
                                    onChange={(e) => patchSelected({ social_url: e.target.value })}
                                />
                                {selected.social_url ? (
                                    <a
                                        className="seeding-ws__btn seeding-ws__btn--ghost"
                                        href={selected.social_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <ExternalLink size={14} /> Mở
                                    </a>
                                ) : null}
                            </div>
                        </div>

                        <div className="seeding-ws__footer">
                            <button type="button" className="seeding-ws__btn seeding-ws__btn--primary" onClick={onCopy}>
                                <Copy size={14} /> Copy nội dung
                            </button>
                            <div className="seeding-ws__status">
                                {saveState === 'saving' && 'Đang lưu…'}
                                {saveState === 'saved' && 'Đã lưu ✓'}
                                {saveState === 'dirty' && 'Chưa đồng bộ'}
                                {saveState === 'error' && 'Lỗi lưu — giữ local'}
                                {saveState === 'idle' && ' '}
                            </div>
                        </div>
                    </>
                )}
            </main>

            {toast ? (
                <div className="seeding-ws__toast">
                    <span>{toast.message}</span>
                    {toast.action ? (
                        <button type="button" onClick={() => toast.action.onClick?.()}>
                            {toast.action.label}
                        </button>
                    ) : null}
                    <button type="button" aria-label="Đóng" onClick={() => setToast(null)}>
                        <X size={14} />
                    </button>
                </div>
            ) : null}
        </div>
    );
}
