import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Archive, ArchiveRestore, Copy, ExternalLink, Plus } from 'lucide-react';
import {
    createDebouncedWriter,
    documentKey,
    importLegacyV2IfEmpty,
    makeLocalDraftId,
    previewText,
    readDocument,
    topicKeyOf,
    writeDocument,
} from './storage';

const EVENT_NAME = 'domain-context-changed';
const SITE_EVENT = 'seeding-site-changed';

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
        updated_at: new Date().toISOString(),
    };
}

function statusBadgeClass(status) {
    if (status === 'active') return 'seeding-ws__badge seeding-ws__badge--active';
    if (status === 'done') return 'seeding-ws__badge seeding-ws__badge--done';
    return 'seeding-ws__badge seeding-ws__badge--draft';
}

function detectLinks(text) {
    const matches = String(text || '').match(/https?:\/\/[^\s<>"']+/gi) || [];
    const unique = [...new Set(matches.map((u) => u.replace(/[),.;]+$/g, '')))];
    return unique.map((url) => ({ url, normalized_url: url }));
}

/**
 * @param {{
 *   siteId: number|null,
 *   canMutate?: boolean,
 *   bootstrap?: {
 *     client?: { installation_id?: string },
 *     user?: { id?: number },
 *   }
 * }} props
 */
export default function SeedingWorkspace({ siteId: initialSiteId, canMutate = true, bootstrap = null }) {
    const installationId = bootstrap?.client?.installation_id || 'app:local';
    const userId = bootstrap?.user?.id ?? 0;

    const [siteId, setSiteId] = useState(initialSiteId);
    const [topics, setTopics] = useState([]);
    const [showArchived, setShowArchived] = useState(false);
    const [search, setSearch] = useState('');
    const [selectedKey, setSelectedKey] = useState(null);
    const [selected, setSelected] = useState(null);
    const [saveState, setSaveState] = useState('idle'); // idle | dirty | saved
    const [toast, setToast] = useState(null);

    const selectedRef = useRef(null);
    const toastTimer = useRef(null);
    const writer = useRef(createDebouncedWriter());
    const scopeRef = useRef({ installationId, userId, siteId });

    useEffect(() => {
        selectedRef.current = selected;
    }, [selected]);

    useEffect(() => {
        setSiteId(initialSiteId);
    }, [initialSiteId]);

    useEffect(() => {
        scopeRef.current = { installationId, userId, siteId };
    }, [installationId, userId, siteId]);

    useEffect(() => {
        const onDomain = (event) => {
            const next = Number(event?.detail?.siteId ?? event?.detail?.site_id ?? 0);
            if (Number.isFinite(next) && next > 0) {
                setSiteId(next);
            }
        };
        window.addEventListener(EVENT_NAME, onDomain);
        window.addEventListener(SITE_EVENT, onDomain);
        return () => {
            window.removeEventListener(EVENT_NAME, onDomain);
            window.removeEventListener(SITE_EVENT, onDomain);
        };
    }, []);

    const showToast = useCallback((message) => {
        if (toastTimer.current) clearTimeout(toastTimer.current);
        setToast({ message });
        toastTimer.current = setTimeout(() => setToast(null), 3500);
    }, []);

    const persistNow = useCallback(
        (nextTopics, workspacePartial = {}) => {
            const scope = scopeRef.current;
            if (!scope.siteId) return;
            const current = readDocument(scope);
            writeDocument(scope, {
                ...current,
                topics: nextTopics,
                workspace: {
                    ...current.workspace,
                    ...workspacePartial,
                },
            });
            setSaveState('saved');
        },
        [],
    );

    const schedulePersist = useCallback(
        (nextTopics, workspacePartial = {}) => {
            setSaveState('dirty');
            writer.current.schedule(() => persistNow(nextTopics, workspacePartial));
        },
        [persistNow],
    );

    useEffect(() => {
        if (!siteId) {
            setTopics([]);
            setSelected(null);
            setSelectedKey(null);
            return;
        }
        const scope = { installationId, userId, siteId };
        const doc = importLegacyV2IfEmpty(scope);
        setTopics(Array.isArray(doc.topics) ? doc.topics : []);
        setSearch(doc.workspace?.search || '');
        setShowArchived(Boolean(doc.workspace?.showArchived));
        const selectedId = doc.workspace?.selectedTopicId ? String(doc.workspace.selectedTopicId) : null;
        setSelectedKey(selectedId);
        setSaveState('idle');
    }, [siteId, installationId, userId]);

    useEffect(() => {
        if (!siteId || !selectedKey) {
            setSelected(null);
            return;
        }
        const found = topics.find((t) => topicKeyOf(t) === String(selectedKey));
        setSelected(found || null);
    }, [selectedKey, siteId, topics]);

    useEffect(
        () => () => {
            writer.current.cancel();
            if (toastTimer.current) clearTimeout(toastTimer.current);
        },
        [],
    );

    const archivedCount = useMemo(
        () => topics.filter((t) => t.is_archived).length,
        [topics],
    );

    const filteredTopics = useMemo(() => {
        const q = search.trim().toLowerCase();
        const list = topics.filter((t) => (showArchived ? t.is_archived : !t.is_archived));
        if (q === '') return list;
        return list.filter((t) => {
            const hay = `${t.preview || ''} ${t.full_text || ''}`.toLowerCase();
            return hay.includes(q);
        });
    }, [topics, search, showArchived]);

    const selectTopic = (topic) => {
        const key = topicKeyOf(topic);
        setSelectedKey(key);
        schedulePersist(topics, { selectedTopicId: key, search, showArchived });
    };

    const createTopic = () => {
        if (!siteId || !canMutate) return;
        const localId = makeLocalDraftId();
        const draft = emptyTopic(localId);
        const next = [draft, ...topics];
        setTopics(next);
        setSelected(draft);
        selectedRef.current = draft;
        setSelectedKey(localId);
        if (showArchived) setShowArchived(false);
        schedulePersist(next, { selectedTopicId: localId, showArchived: false, search });
        setSaveState('saved');
    };

    const patchSelected = (partial) => {
        setSelected((prev) => {
            if (!prev) return prev;
            const nextTopic = {
                ...prev,
                ...partial,
                preview: previewText(partial.full_text !== undefined ? partial.full_text : prev.full_text),
                links: partial.full_text !== undefined
                    ? detectLinks(partial.full_text)
                    : prev.links,
                links_count: partial.full_text !== undefined
                    ? detectLinks(partial.full_text).length
                    : prev.links_count,
                updated_at: new Date().toISOString(),
                status: partial.social_url !== undefined
                    ? (partial.social_url ? 'active' : 'draft')
                    : prev.status,
                status_label: partial.social_url !== undefined
                    ? (partial.social_url ? 'ACTIVE' : 'DRAFT')
                    : prev.status_label,
            };
            if (partial.social_url !== undefined) {
                nextTopic.social_url = partial.social_url || '';
            }
            selectedRef.current = nextTopic;
            const key = topicKeyOf(nextTopic);
            const nextTopics = topics.map((t) => (topicKeyOf(t) === key ? nextTopic : t));
            if (!topics.some((t) => topicKeyOf(t) === key)) {
                nextTopics.unshift(nextTopic);
            }
            setTopics(nextTopics);
            schedulePersist(nextTopics, { selectedTopicId: key, search, showArchived });
            return nextTopic;
        });
    };

    const onPasteContent = (event) => {
        const html = event.clipboardData?.getData('text/html');
        if (html && html.trim() !== '') {
            patchSelected({ source_html: html });
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

    const archiveTopic = (topic, event) => {
        event?.stopPropagation?.();
        if (!canMutate) return;
        const key = topicKeyOf(topic);
        const nextTopics = topics.map((t) =>
            topicKeyOf(t) === key
                ? { ...t, is_archived: true, archived_at: new Date().toISOString() }
                : t,
        );
        setTopics(nextTopics);
        if (selectedKey === key) {
            setSelected((s) => (s ? { ...s, is_archived: true, archived_at: new Date().toISOString() } : s));
        }
        schedulePersist(nextTopics, { selectedTopicId: selectedKey, search, showArchived });
        showToast('Đã lưu trữ chủ đề (local)');
    };

    const restoreTopic = (topic) => {
        if (!canMutate) return;
        const key = topicKeyOf(topic);
        const nextTopics = topics.map((t) =>
            topicKeyOf(t) === key
                ? { ...t, is_archived: false, archived_at: null }
                : t,
        );
        setTopics(nextTopics);
        if (selectedKey === key) {
            setSelected((s) => (s ? { ...s, is_archived: false, archived_at: null } : s));
        }
        schedulePersist(nextTopics, { selectedTopicId: selectedKey, search, showArchived });
        showToast('Đã khôi phục chủ đề (local)');
    };

    if (!siteId) {
        return <div className="seeding-ws__empty">Chọn domain để mở Seeding.</div>;
    }

    return (
        <div className="seeding-ws" data-storage-key={documentKey({ installationId, userId, siteId })}>
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
                            schedulePersist(topics, { search: value, selectedTopicId: selectedKey, showArchived });
                        }}
                        placeholder="Tìm…"
                    />
                </div>
                <div className="seeding-ws__list">
                    <div className="seeding-ws__section-label">
                        {showArchived ? 'Archived' : 'Đang làm'}
                        {saveState === 'saved' ? ' · local' : saveState === 'dirty' ? ' · …' : ''}
                    </div>
                    {filteredTopics.length === 0 ? (
                        <div className="seeding-ws__empty" style={{ padding: '0.75rem' }}>
                            {showArchived ? 'Không có topic đã lưu trữ.' : 'Chưa có chủ đề.'}
                        </div>
                    ) : (
                        filteredTopics.map((topic) => {
                            const key = topicKeyOf(topic);
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
                                    {canMutate ? (
                                        showArchived || topic.is_archived ? (
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
                    onClick={() => {
                        setShowArchived((v) => {
                            const next = !v;
                            schedulePersist(topics, { showArchived: next, selectedTopicId: selectedKey, search });
                            return next;
                        });
                    }}
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
                                    {saveState === 'saved' ? (
                                        <span style={{ fontSize: 12, color: '#6b7280' }}>Saved locally</span>
                                    ) : null}
                                </div>
                            </div>
                            {canMutate ? (
                                selected.is_archived || showArchived ? (
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

                        <label className="seeding-ws__label">Nội dung</label>
                        <textarea
                            className="seeding-ws__textarea"
                            value={selected.full_text || ''}
                            disabled={!canMutate}
                            placeholder="Dán / nhập nội dung bài social…"
                            onPaste={onPasteContent}
                            onChange={(e) => patchSelected({ full_text: e.target.value })}
                        />

                        <label className="seeding-ws__label">Link bài social (tuỳ chọn)</label>
                        <div className="seeding-ws__social-row">
                            <input
                                className="seeding-ws__input"
                                value={selected.social_url || ''}
                                disabled={!canMutate}
                                placeholder="https://…"
                                onChange={(e) => patchSelected({ social_url: e.target.value })}
                            />
                            {selected.social_url ? (
                                <a className="seeding-ws__icon-btn" href={selected.social_url} target="_blank" rel="noreferrer" title="Mở">
                                    <ExternalLink size={14} />
                                </a>
                            ) : null}
                        </div>

                        <div className="seeding-ws__actions">
                            <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={onCopy}>
                                <Copy size={14} /> Copy nội dung
                            </button>
                        </div>

                        {Array.isArray(selected.links) && selected.links.length > 0 ? (
                            <div className="seeding-ws__links">
                                <div className="seeding-ws__section-label">Links phát hiện ({selected.links.length})</div>
                                <ul>
                                    {selected.links.map((link) => (
                                        <li key={link.url || link.normalized_url}>
                                            <a href={link.url || link.normalized_url} target="_blank" rel="noreferrer">
                                                {link.url || link.normalized_url}
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ) : null}
                    </>
                )}
            </main>

            {toast ? (
                <div className="seeding-ws__toast">{toast.message}</div>
            ) : null}
        </div>
    );
}
