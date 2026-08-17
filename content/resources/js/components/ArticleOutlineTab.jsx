import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { AlertTriangle, Check, Copy, Loader2, MoreHorizontal, Pencil, Plus } from 'lucide-react';
import { csrfToken, seoArticleApiHeaders } from '@seo-addon/utils/seoArticleApi.js';
import { isPersistedOutlineHeadingId } from '../utils/contentDocumentHelpers';
import { findLocalDuplicateHeadingKeys, localDuplicateHeadingKey } from '../utils/articleEditorClientOutline';
import { t } from '../utils/i18n';

const outlineUrl = (articleId) => `/api/seo/articles/${articleId}/outline`;
const outlineRefreshUrl = (articleId) => `/api/seo/articles/${articleId}/outline/refresh`;
const headingUrl = (articleId, headingId) => `/api/seo/articles/${articleId}/outline/${headingId}`;
const generateUrl = (articleId, headingId) =>
    `/api/seo/articles/${articleId}/outline/${headingId}/generate`;

function findOutlineNodeByPredicate(nodes, predicate) {
    for (const node of nodes ?? []) {
        if (predicate(node)) {
            return node;
        }
        const child = findOutlineNodeByPredicate(node.children, predicate);
        if (child) {
            return child;
        }
    }

    return null;
}

function extractOutlineApiErrorMessage(data, response) {
    if (response.status === 419) {
        return t('outline_session_expired');
    }

    const direct = typeof data?.message === 'string' ? data.message.trim() : '';
    if (direct !== '') {
        return direct;
    }

    const errors = data?.errors;
    if (errors && typeof errors === 'object') {
        for (const key of Object.keys(errors)) {
            const first = Array.isArray(errors[key]) ? errors[key][0] : null;
            if (typeof first === 'string' && first.trim() !== '') {
                return first.trim();
            }
        }
    }

    return `Yêu cầu thất bại (HTTP ${response.status}).`;
}

async function requestJson(url, options = {}) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
            ...seoArticleApiHeaders(),
            ...(options.headers ?? {}),
        },
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.success === false) {
        throw new Error(extractOutlineApiErrorMessage(data, response));
    }

    return data;
}

const OUTLINE_HEADING_TEXT_MAX = 255;

function normalizeOutlineHeadingText(text) {
    return String(text ?? '').replace(/\s+/g, ' ').trim();
}

/** Khớp server Str::limit(..., 255) / cột heading_text. */
function truncateOutlineHeadingText(text) {
    return Array.from(normalizeOutlineHeadingText(text)).slice(0, OUTLINE_HEADING_TEXT_MAX).join('');
}

function headingHtmlHasLink(html) {
    return /<a[\s>]/i.test(String(html ?? ''));
}

/** Tìm node outline theo id, kèm groupId (H2 container). */
function findOutlineNodeById(nodes, headingId, groupId = null) {
    const targetId = String(headingId ?? '');

    for (const node of nodes) {
        const ownGroupId = node.level <= 2 ? node.id : groupId;
        if (String(node.id) === targetId) {
            return { node, groupId: ownGroupId };
        }
        if (Array.isArray(node.children) && node.children.length > 0) {
            const found = findOutlineNodeById(node.children, headingId, ownGroupId);
            if (found) {
                return found;
            }
        }
    }

    return null;
}

function nodeContainsHeadingId(node, headingId) {
    if (String(node.id) === String(headingId)) {
        return true;
    }

    return (Array.isArray(node.children) ? node.children : []).some((child) =>
        nodeContainsHeadingId(child, headingId),
    );
}

function insertRootOutlineNodeAfter(tree, afterHeadingId, newNode) {
    const wrapped = {
        ...newNode,
        children: Array.isArray(newNode.children) ? newNode.children : [],
    };
    const afterId = Number(afterHeadingId);

    if (!afterId) {
        return [...tree, wrapped];
    }

    for (let index = 0; index < tree.length; index += 1) {
        if (nodeContainsHeadingId(tree[index], afterId)) {
            const next = [...tree];
            next.splice(index + 1, 0, wrapped);

            return next;
        }
    }

    return [...tree, wrapped];
}

/** Tìm node outline theo level + text, kèm groupId (H2 container). */
function findOutlineNodeWithGroup(nodes, level, headingText, groupId = null) {
    const normalized = truncateOutlineHeadingText(headingText);

    for (const node of nodes) {
        const ownGroupId = node.level <= 2 ? node.id : groupId;
        if (
            node.level === level &&
            truncateOutlineHeadingText(node.heading_text) === normalized
        ) {
            return { node, groupId: ownGroupId };
        }
        if (Array.isArray(node.children) && node.children.length > 0) {
            const found = findOutlineNodeWithGroup(node.children, level, headingText, ownGroupId);
            if (found) {
                return found;
            }
        }
    }

    return null;
}

/** Update 1 node (theo id) trong tree bất kỳ độ sâu. */
function patchTreeNode(nodes, headingId, patch) {
    return nodes.map((node) => {
        if (node.id === headingId) {
            return { ...node, ...patch };
        }
        if (Array.isArray(node.children) && node.children.length > 0) {
            return { ...node, children: patchTreeNode(node.children, headingId, patch) };
        }

        return node;
    });
}

function removeTreeNodeById(nodes, headingId) {
    const targetId = String(headingId);

    return nodes
        .filter((node) => String(node.id) !== targetId)
        .map((node) => ({
            ...node,
            children: Array.isArray(node.children) ? removeTreeNodeById(node.children, headingId) : [],
        }));
}

function replaceTreeNodeId(nodes, oldId, newNode) {
    const targetId = String(oldId);

    return nodes.map((node) => {
        if (String(node.id) === targetId) {
            return {
                ...newNode,
                children: Array.isArray(node.children) ? node.children : [],
            };
        }

        if (Array.isArray(node.children) && node.children.length > 0) {
            return { ...node, children: replaceTreeNodeId(node.children, oldId, newNode) };
        }

        return node;
    });
}

function swapSiblingInTree(nodes, nodeId, direction) {
    if (!Array.isArray(nodes) || nodes.length === 0) {
        return nodes;
    }

    const index = nodes.findIndex((node) => Number(node.id) === Number(nodeId));
    if (index >= 0) {
        const targetIndex = direction === 'prev' ? index - 1 : index + 1;
        if (targetIndex < 0 || targetIndex >= nodes.length) {
            return nodes;
        }

        const next = [...nodes];
        [next[index], next[targetIndex]] = [next[targetIndex], next[index]];

        return next;
    }

    let changed = false;
    const nextNodes = nodes.map((node) => {
        if (!Array.isArray(node.children) || node.children.length === 0) {
            return node;
        }

        const nextChildren = swapSiblingInTree(node.children, nodeId, direction);
        if (nextChildren === node.children) {
            return node;
        }

        changed = true;

        return { ...node, children: nextChildren };
    });

    return changed ? nextNodes : nodes;
}

/**
 * Block 1 heading: click nhảy editor, double-click focus nhóm, icon Pencil/Sparkles để sửa/gen.
 */
function HeadingBlock({
    node,
    groupId,
    activeGroupId,
    activeHeadingId,
    editingHeadingId,
    onEditingHeadingEnd,
    onSelectGroup,
    onJumpToEditor,
    onSaveText,
    onSaveHtml,
    onGenerate,
    onMoveUp,
    onMoveDown,
    onDelete,
    onAddNode = null,
    onChangeLevel = null,
    onDeleteKeepContent = null,
    onDeleteWithContent = null,
    onToggleVisible = null,
    canMoveUp = false,
    canMoveDown = false,
    canDelete = false,
    canGenerateOutlineHeading = false,
    resolveHeadingInnerHtml = null,
    busyHeadingId,
    isLocalDuplicate = false,
}) {
    const [editing, setEditing] = useState(false);
    const [htmlEditMode, setHtmlEditMode] = useState(false);
    const [draft, setDraft] = useState(node.heading_text);
    const inputRef = useRef(null);
    const textareaRef = useRef(null);
    const clickTimerRef = useRef(null);
    const copyTimerRef = useRef(null);
    const [copied, setCopied] = useState(false);
    const [menuOpen, setMenuOpen] = useState(false);
    const [addOpen, setAddOpen] = useState(false);
    const isBusy = busyHeadingId === node.id;
    const isHeadingFocused = activeHeadingId === node.id;

    useEffect(() => {
        if (!editing) {
            setDraft(node.heading_text);
            setHtmlEditMode(false);
        }
    }, [node.heading_text, editing]);

    useEffect(() => {
        if (editing) {
            if (htmlEditMode) {
                textareaRef.current?.focus();
                textareaRef.current?.select();
                return;
            }

            inputRef.current?.focus();
            inputRef.current?.select();
        }
    }, [editing, htmlEditMode]);

    const startEditing = useCallback(() => {
        const innerHtml = typeof resolveHeadingInnerHtml === 'function'
            ? String(resolveHeadingInnerHtml(node) ?? '').trim()
            : '';
        const useHtml = headingHtmlHasLink(innerHtml);

        setHtmlEditMode(useHtml);
        setDraft(useHtml ? innerHtml : node.heading_text);
        setEditing(true);
    }, [node, resolveHeadingInnerHtml]);

    useEffect(() => {
        if (editingHeadingId === node.id && !editing) {
            startEditing();
        }
    }, [editingHeadingId, node.id, editing, startEditing]);

    const endEditing = useCallback(() => {
        setEditing(false);
        if (editingHeadingId === node.id) {
            onEditingHeadingEnd?.();
        }
    }, [editingHeadingId, node.id, onEditingHeadingEnd]);

    const commitDraft = useCallback(() => {
        endEditing();

        if (htmlEditMode) {
            const nextHtml = draft.trim();
            const currentHtml = typeof resolveHeadingInnerHtml === 'function'
                ? String(resolveHeadingInnerHtml(node) ?? '').trim()
                : '';

            if (nextHtml === '' || nextHtml === currentHtml) {
                setDraft(node.heading_text);
                return;
            }

            const doc = new DOMParser().parseFromString(`<div>${nextHtml}</div>`, 'text/html');
            const plainText = normalizeOutlineHeadingText(doc.body.textContent);
            if (plainText === '') {
                setDraft(node.heading_text);
                return;
            }

            onSaveHtml?.(node, nextHtml, plainText);
            return;
        }

        const next = draft.replace(/\s+/g, ' ').trim();
        if (next === '' || next === node.heading_text) {
            setDraft(node.heading_text);
            return;
        }
        onSaveText(node, next);
    }, [draft, endEditing, htmlEditMode, node, onSaveHtml, onSaveText, resolveHeadingInnerHtml]);

    useEffect(
        () => () => {
            if (clickTimerRef.current) {
                window.clearTimeout(clickTimerRef.current);
            }
            if (copyTimerRef.current) {
                window.clearTimeout(copyTimerRef.current);
            }
        },
        [],
    );

    const handleCopyHeading = useCallback(
        async (event) => {
            event.stopPropagation();

            const text = String(node.heading_text ?? '')
                .replace(/\s+/g, ' ')
                .trim();
            if (text === '') {
                return;
            }

            try {
                if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(text);
                } else {
                    const textarea = document.createElement('textarea');
                    textarea.value = text;
                    textarea.setAttribute('readonly', '');
                    textarea.style.position = 'fixed';
                    textarea.style.opacity = '0';
                    document.body.appendChild(textarea);
                    textarea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textarea);
                }

                setCopied(true);
                if (copyTimerRef.current) {
                    window.clearTimeout(copyTimerRef.current);
                }
                copyTimerRef.current = window.setTimeout(() => setCopied(false), 1500);
            } catch {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('outline_notify_title'),
                            body: t('outline_copy_failed'),
                            status: 'warning',
                        },
                    }),
                );
            }
        },
        [node.heading_text],
    );

    return (
        <div
            data-outline-heading-id={node.id}
            className={[
                'seo-outline-block',
                `seo-outline-block--h${node.level}`,
                isHeadingFocused ? 'is-heading-focused' : '',
                isBusy ? 'is-busy' : '',
            ]
                .filter(Boolean)
                .join(' ')}
            onClick={(e) => {
                e.stopPropagation();
                if (editing || isBusy) {
                    return;
                }
                if (clickTimerRef.current) {
                    window.clearTimeout(clickTimerRef.current);
                }
                clickTimerRef.current = window.setTimeout(() => {
                    clickTimerRef.current = null;
                    onSelectGroup(groupId, node.id);
                    onJumpToEditor?.(node);
                }, 220);
            }}
            onDoubleClick={(e) => {
                e.stopPropagation();
                if (clickTimerRef.current) {
                    window.clearTimeout(clickTimerRef.current);
                    clickTimerRef.current = null;
                }
                onSelectGroup(groupId, node.id);
                if (!isBusy) {
                    startEditing();
                }
            }}
        >
            <div className="seo-outline-block__main">
                <span className="seo-outline-block__level">H{node.level}</span>
                {editing ? (
                    htmlEditMode ? (
                        <textarea
                            ref={textareaRef}
                            className="seo-outline-block__input seo-outline-block__textarea"
                            value={draft}
                            rows={3}
                            onChange={(e) => setDraft(e.target.value)}
                            onBlur={commitDraft}
                            onKeyDown={(e) => {
                                if (e.key === 'Escape') {
                                    setDraft(
                                        typeof resolveHeadingInnerHtml === 'function'
                                            ? String(resolveHeadingInnerHtml(node) ?? '').trim()
                                            : node.heading_text,
                                    );
                                    endEditing();
                                }
                            }}
                            placeholder={t('outline_html_placeholder')}
                        />
                    ) : (
                        <input
                            ref={inputRef}
                            type="text"
                            className="seo-outline-block__input"
                            value={draft}
                            maxLength={255}
                            onChange={(e) => setDraft(e.target.value)}
                            onBlur={commitDraft}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    commitDraft();
                                }
                                if (e.key === 'Escape') {
                                    setDraft(node.heading_text);
                                    endEditing();
                                }
                            }}
                        />
                    )
                ) : (
                    <span className="seo-outline-block__text" title={t('outline_click_jump_hint')}>
                        {node.heading_text}
                    </span>
                )}
                {!editing ? (
                    <>
                        {isLocalDuplicate ? (
                            <span className="seo-outline-block__local-dup" title={t('outline_local_duplicate')}>
                                <AlertTriangle size={12} strokeWidth={1.75} />
                            </span>
                        ) : null}
                        <button
                            type="button"
                            className="seo-outline-block__copy-btn"
                            title={copied ? t('outline_copied') : t('outline_copy_heading')}
                            aria-label={copied ? t('outline_copied_heading') : t('outline_copy_heading')}
                            onClick={handleCopyHeading}
                        >
                            {copied ? (
                                <Check size={14} strokeWidth={1.75} />
                            ) : (
                                <Copy size={14} strokeWidth={1.75} />
                            )}
                        </button>
                        <button
                            type="button"
                            className="seo-outline-block__hover-btn"
                            title={t('outline_edit_manual')}
                            onClick={(e) => {
                                e.stopPropagation();
                                if (!isBusy) {
                                    startEditing();
                                }
                            }}
                        >
                            <Pencil size={13} strokeWidth={1.75} />
                        </button>
                        <div className="seo-outline-block__hover-wrap">
                            <button
                                type="button"
                                className="seo-outline-block__hover-btn"
                                title={t('outline_add_heading')}
                                onClick={(e) => {
                                    e.stopPropagation();
                                    setAddOpen((open) => !open);
                                    setMenuOpen(false);
                                }}
                            >
                                <Plus size={13} strokeWidth={1.75} />
                            </button>
                            {addOpen ? (
                                <div className="seo-outline-block__menu" onClick={(e) => e.stopPropagation()}>
                                    {node.level <= 2 ? (
                                        <button type="button" onClick={() => { setAddOpen(false); onAddNode?.(node, 'h2-below'); }}>
                                            {t('outline_add_h2_below')}
                                        </button>
                                    ) : null}
                                    {node.level <= 3 ? (
                                        <button type="button" onClick={() => { setAddOpen(false); onAddNode?.(node, 'h3-child'); }}>
                                            {t('outline_add_h3_child')}
                                        </button>
                                    ) : null}
                                    <button type="button" onClick={() => { setAddOpen(false); onAddNode?.(node, 'paragraph'); }}>
                                        {t('outline_add_paragraph')}
                                    </button>
                                </div>
                            ) : null}
                        </div>
                        <div className="seo-outline-block__hover-wrap">
                            <button
                                type="button"
                                className="seo-outline-block__hover-btn"
                                title={t('outline_more_actions')}
                                onClick={(e) => {
                                    e.stopPropagation();
                                    setMenuOpen((open) => !open);
                                    setAddOpen(false);
                                }}
                            >
                                <MoreHorizontal size={13} strokeWidth={1.75} />
                            </button>
                            {menuOpen ? (
                                <div className="seo-outline-block__menu" onClick={(e) => e.stopPropagation()}>
                                    {node.level !== 2 ? (
                                        <button type="button" onClick={() => { setMenuOpen(false); onChangeLevel?.(node, 2); }}>
                                            {t('outline_change_h2')}
                                        </button>
                                    ) : null}
                                    {node.level !== 3 ? (
                                        <button type="button" onClick={() => { setMenuOpen(false); onChangeLevel?.(node, 3); }}>
                                            {t('outline_change_h3')}
                                        </button>
                                    ) : null}
                                    {node.level !== 4 ? (
                                        <button type="button" onClick={() => { setMenuOpen(false); onChangeLevel?.(node, 4); }}>
                                            {t('outline_change_h4')}
                                        </button>
                                    ) : null}
                                    <button type="button" onClick={() => { setMenuOpen(false); onToggleVisible?.(node, false); }}>
                                        {t('outline_hide_from_outline')}
                                    </button>
                                    <button type="button" onClick={() => { setMenuOpen(false); onDeleteKeepContent?.(node); }}>
                                        {t('outline_delete_keep_content')}
                                    </button>
                                    <button
                                        type="button"
                                        className="is-danger"
                                        onClick={() => {
                                            setMenuOpen(false);
                                            if (node.level <= 2) {
                                                onDelete?.(node);
                                                return;
                                            }
                                            if (window.confirm(t('outline_delete_with_content_confirm', { heading: node.heading_text }))) {
                                                onDeleteWithContent?.(node);
                                            }
                                        }}
                                    >
                                        {t('outline_delete_with_content')}
                                    </button>
                                    {canGenerateOutlineHeading ? (
                                        <button type="button" disabled={isBusy} onClick={() => { setMenuOpen(false); onGenerate(node); }}>
                                            {t('outline_ai_gen')}
                                        </button>
                                    ) : null}
                                </div>
                            ) : null}
                        </div>
                    </>
                ) : null}
            </div>
        </div>
    );
}

/** Render đệ quy. H2 là group container — click vào sẽ highlight cả nhóm con. */
function OutlineTree({
    nodes,
    groupId = null,
    activeGroupId,
    activeHeadingId,
    editingHeadingId,
    onEditingHeadingEnd,
    onSelectGroup,
    onJumpToEditor,
    onSaveText,
    onSaveHtml,
    onGenerate,
    onMoveHeading,
    onDeleteHeading,
    onAddNode = null,
    onChangeLevel = null,
    onDeleteKeepContent = null,
    onDeleteWithContent = null,
    onToggleVisible = null,
    canGenerateOutlineHeading = false,
    resolveHeadingInnerHtml = null,
    busyHeadingId,
    localDuplicateKeys = null,
}) {
    return (
        <>
            {nodes.map((node, index) => {
                const ownGroupId = node.level <= 2 ? node.id : groupId;
                const hasChildren = Array.isArray(node.children) && node.children.length > 0;
                const isSectionFocused =
                    node.level <= 2 &&
                    activeHeadingId === node.id;
                const canMoveUp = index > 0;
                const canMoveDown = index < nodes.length - 1;

                return (
                    <div
                        key={node.id}
                        className={[
                            'seo-outline-group',
                            node.level <= 2 ? 'seo-outline-group--root' : '',
                            isSectionFocused ? 'is-active' : '',
                        ]
                            .filter(Boolean)
                            .join(' ')}
                    >
                        <HeadingBlock
                            node={node}
                            groupId={ownGroupId}
                            activeGroupId={activeGroupId}
                            activeHeadingId={activeHeadingId}
                            editingHeadingId={editingHeadingId}
                            onEditingHeadingEnd={onEditingHeadingEnd}
                            onSelectGroup={onSelectGroup}
                            onJumpToEditor={onJumpToEditor}
                            onSaveText={onSaveText}
                            onSaveHtml={onSaveHtml}
                            onGenerate={onGenerate}
                            onMoveUp={(headingNode) => onMoveHeading?.(headingNode, 'prev')}
                            onMoveDown={(headingNode) => onMoveHeading?.(headingNode, 'next')}
                            onDelete={onDeleteHeading}
                            onAddNode={onAddNode}
                            onChangeLevel={onChangeLevel}
                            onDeleteKeepContent={onDeleteKeepContent}
                            onDeleteWithContent={onDeleteWithContent}
                            onToggleVisible={onToggleVisible}
                            canMoveUp={canMoveUp}
                            canMoveDown={canMoveDown}
                            canDelete
                            canGenerateOutlineHeading={canGenerateOutlineHeading}
                            resolveHeadingInnerHtml={resolveHeadingInnerHtml}
                            busyHeadingId={busyHeadingId}
                            isLocalDuplicate={Boolean(localDuplicateKeys?.has(localDuplicateHeadingKey(node.heading_text)))}
                        />
                        {hasChildren && (
                            <div className="seo-outline-children">
                                <OutlineTree
                                    nodes={node.children}
                                    groupId={ownGroupId}
                                    activeGroupId={activeGroupId}
                                    activeHeadingId={activeHeadingId}
                                    editingHeadingId={editingHeadingId}
                                    onEditingHeadingEnd={onEditingHeadingEnd}
                                    onSelectGroup={onSelectGroup}
                                    onJumpToEditor={onJumpToEditor}
                                    onSaveText={onSaveText}
                                    onSaveHtml={onSaveHtml}
                                    onGenerate={onGenerate}
                                    onMoveHeading={onMoveHeading}
                                    onDeleteHeading={onDeleteHeading}
                                    onAddNode={onAddNode}
                                    onChangeLevel={onChangeLevel}
                                    onDeleteKeepContent={onDeleteKeepContent}
                                    onDeleteWithContent={onDeleteWithContent}
                                    onToggleVisible={onToggleVisible}
                                    canGenerateOutlineHeading={canGenerateOutlineHeading}
                                    resolveHeadingInnerHtml={resolveHeadingInnerHtml}
                                    busyHeadingId={busyHeadingId}
                                    localDuplicateKeys={localDuplicateKeys}
                                />
                            </div>
                        )}
                    </div>
                );
            })}
        </>
    );
}

/**
 * Tab "Outline / Dàn ý" — canonical article structure for writing/editing/generation.
 * Cross-article duplicate detection is not part of this tab.
 */
export default function ArticleOutlineTab({
    articleId,
    headingCommand = null,
    outlineTreeSync = null,
    canGenerateOutlineHeading = false,
    resolveHeadingInnerHtml = null,
    onOutlineLoaded,
    onHeadingTextChange,
    onHeadingHtmlChange,
    onSaveOutlineHeadingTitle = null,
    onJumpToEditorHeading,
    onOutlineMoveHeading,
    onOutlineDeleteHeading,
    onOutlineAddSection,
    onOutlineAddNode = null,
    onOutlineChangeLevel = null,
    onOutlineDeleteKeepContent = null,
    onOutlineDeleteWithContent = null,
    onOutlineToggleVisible = null,
    onNotify,
    onRequestEditorHtml = null,
    /** Phase 4: prefer ProseMirror/blocks-derived outline; skip GET /outline on mount. */
    preferClientSource = false,
    clientOutline = null,
    onClientRefresh = null,
}) {
    const [tree, setTree] = useState(() => (preferClientSource && Array.isArray(clientOutline) ? clientOutline : []));
    const [loading, setLoading] = useState(!preferClientSource);
    const [error, setError] = useState('');
    const [activeGroupId, setActiveGroupId] = useState(null);
    const [activeHeadingId, setActiveHeadingId] = useState(null);
    const [editingHeadingId, setEditingHeadingId] = useState(null);
    const [busyHeadingId, setBusyHeadingId] = useState(null);
    const [overflowOpen, setOverflowOpen] = useState(false);
    const pendingFocusRef = useRef(null);

    const localDuplicateKeys = useMemo(() => findLocalDuplicateHeadingKeys(tree), [tree]);

    const handleSelectGroup = useCallback((groupId, headingId = null) => {
        setActiveGroupId(groupId);
        setActiveHeadingId(headingId);
    }, []);

    const handleJumpToEditor = useCallback(
        (node) => {
            onJumpToEditorHeading?.(node);
        },
        [onJumpToEditorHeading],
    );

    const notify = useCallback(
        (title, body, status = 'success') => {
            onNotify?.({ title, body, status });
        },
        [onNotify],
    );

    const loadOutline = useCallback(async ({ reextract = false } = {}) => {
        // Phase 4: client-first outline — rebuild from editor blocks, no GET /outline.
        if (preferClientSource && !reextract) {
            const outline = Array.isArray(clientOutline) ? clientOutline : [];
            setTree(outline);
            setError('');
            setLoading(false);
            onOutlineLoaded?.(outline);
            return;
        }

        if (preferClientSource && reextract) {
            setLoading(true);
            setError('');
            try {
                if (typeof onClientRefresh === 'function') {
                    const outline = await onClientRefresh();
                    const next = Array.isArray(outline) ? outline : (Array.isArray(clientOutline) ? clientOutline : []);
                    setTree(next);
                    onOutlineLoaded?.(next);
                } else {
                    const outline = Array.isArray(clientOutline) ? clientOutline : [];
                    setTree(outline);
                    onOutlineLoaded?.(outline);
                }
            } catch (e) {
                setError(e.message || t('outline_load_failed'));
            } finally {
                setLoading(false);
            }
            return;
        }

        setLoading(true);
        setError('');
        try {
            let data;
            if (reextract) {
                const html =
                    typeof onRequestEditorHtml === 'function' ? String(onRequestEditorHtml() ?? '').trim() : '';
                data = await requestJson(outlineRefreshUrl(articleId), {
                    method: 'POST',
                    body: JSON.stringify({
                        reextract: true,
                        html,
                    }),
                });
            } else {
                data = await requestJson(outlineUrl(articleId));
            }

            const outline = Array.isArray(data.outline) ? data.outline : [];
            setTree(outline);
            onOutlineLoaded?.(outline);
        } catch (e) {
            setError(e.message || t('outline_load_failed'));
        } finally {
            setLoading(false);
        }
    }, [articleId, clientOutline, onClientRefresh, onOutlineLoaded, onRequestEditorHtml, preferClientSource]);

    useEffect(() => {
        if (preferClientSource) {
            const outline = Array.isArray(clientOutline) ? clientOutline : [];
            setTree(outline);
            setLoading(false);
            onOutlineLoaded?.(outline);
            return;
        }
        void loadOutline();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- mount / source-mode only; clientOutline sync below
    }, [preferClientSource, articleId]);

    useEffect(() => {
        if (!preferClientSource || !Array.isArray(clientOutline)) {
            return;
        }
        setTree(clientOutline);
    }, [preferClientSource, clientOutline]);

    const applyPendingOutlineFocus = useCallback((nodes) => {
        const pending = pendingFocusRef.current;
        if (!pending || !Array.isArray(nodes) || nodes.length === 0) {
            return false;
        }

        const matchText = String(pending.matchText ?? '').replace(/\s+/g, ' ').trim();
        const found = findOutlineNodeByPredicate(nodes, (node) => {
            if (pending.headingId != null && String(node.id) === String(pending.headingId)) {
                return true;
            }
            if (matchText === '' || pending.blockId == null) {
                return false;
            }

            return String(node.block_id ?? '') === String(pending.blockId)
                && String(node.heading_text ?? '').replace(/\s+/g, ' ').trim() === matchText
                && String(node.id) !== String(pending.parentHeadingId ?? '');
        });
        if (!found) {
            return false;
        }

        pendingFocusRef.current = null;
        setActiveHeadingId(found.id);
        setActiveGroupId(found.id);
        window.requestAnimationFrame(() => {
            const el = document.querySelector(`[data-outline-heading-id="${found.id}"]`);
            el?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            if (pending.focusEdit) {
                setEditingHeadingId(found.id);
            }
        });

        return true;
    }, []);

    useEffect(() => {
        applyPendingOutlineFocus(tree);
    }, [applyPendingOutlineFocus, tree]);

    useEffect(() => {
        if (!headingCommand?.token) {
            return;
        }

        const { level, headingText, headingId, action } = headingCommand;

        if (action === 'clear') {
            setActiveGroupId(null);
            setActiveHeadingId(null);
            setEditingHeadingId(null);
            return;
        }

        const match =
            headingId != null
                ? findOutlineNodeById(tree, headingId)
                : findOutlineNodeWithGroup(tree, level, headingText);
        if (!match) {
            if (tree.length > 0 && headingId == null) {
                notify(t('outline_notify_title'), t('outline_heading_not_found'), 'warning');
            }
            return;
        }

        const { node, groupId } = match;
        setEditingHeadingId(null);
        setActiveGroupId(groupId);
        setActiveHeadingId(node.id);

        window.requestAnimationFrame(() => {
            const el = document.querySelector(`[data-outline-heading-id="${node.id}"]`);
            el?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });

        if (action === 'edit') {
            setEditingHeadingId(node.id);
        }
    }, [
        headingCommand?.token,
        headingCommand?.level,
        headingCommand?.headingText,
        headingCommand?.headingId,
        headingCommand?.action,
        notify,
        tree,
    ]);

    useEffect(() => {
        if (!outlineTreeSync?.token) {
            return;
        }

        if (outlineTreeSync.action === 'focus' || outlineTreeSync.action === 'focusNew') {
            pendingFocusRef.current = {
                headingId: outlineTreeSync.headingId ?? null,
                matchText: outlineTreeSync.matchText ?? '',
                blockId: outlineTreeSync.blockId ?? null,
                parentHeadingId: outlineTreeSync.parentHeadingId ?? null,
                focusEdit: outlineTreeSync.focusEdit === true,
            };
            applyPendingOutlineFocus(tree);
            return;
        }

        if (outlineTreeSync.action === 'append' && outlineTreeSync.heading) {
            const heading = outlineTreeSync.heading;
            setTree((prev) => {
                if (prev.some((node) => Number(node.id) === Number(heading.id))) {
                    return prev;
                }

                return [...prev, { ...heading, children: [] }];
            });
            setEditingHeadingId(null);
            setActiveGroupId(heading.id);
            setActiveHeadingId(heading.id);

            window.requestAnimationFrame(() => {
                const el = document.querySelector(`[data-outline-heading-id="${heading.id}"]`);
                el?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });

            if (outlineTreeSync.focusEdit) {
                window.requestAnimationFrame(() => {
                    const el = document.querySelector(`[data-outline-heading-id="${heading.id}"]`);
                    el?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    setEditingHeadingId(heading.id);
                });
            }

            return;
        }

        if (outlineTreeSync.action === 'insertAfter' && outlineTreeSync.heading) {
            const heading = outlineTreeSync.heading;
            setTree((prev) => {
                if (prev.some((node) => Number(node.id) === Number(heading.id))) {
                    return prev;
                }

                return insertRootOutlineNodeAfter(prev, outlineTreeSync.afterHeadingId, heading);
            });
            setEditingHeadingId(null);
            setActiveGroupId(heading.id);
            setActiveHeadingId(heading.id);

            window.requestAnimationFrame(() => {
                const el = document.querySelector(`[data-outline-heading-id="${heading.id}"]`);
                el?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });

            if (outlineTreeSync.focusEdit) {
                window.requestAnimationFrame(() => {
                    const el = document.querySelector(`[data-outline-heading-id="${heading.id}"]`);
                    el?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    setEditingHeadingId(heading.id);
                });
            }

            return;
        }

        if (outlineTreeSync.action === 'remove' && outlineTreeSync.headingId != null) {
            setTree((prev) => removeTreeNodeById(prev, outlineTreeSync.headingId));
            setEditingHeadingId((current) =>
                Number(current) === Number(outlineTreeSync.headingId) ? null : current,
            );
            setActiveHeadingId((current) =>
                Number(current) === Number(outlineTreeSync.headingId) ? null : current,
            );

            return;
        }

        if (outlineTreeSync.action === 'confirmHeading' && outlineTreeSync.tempHeadingId != null) {
            const heading = outlineTreeSync.heading;
            if (!heading?.id) {
                return;
            }

            setTree((prev) => {
                if (prev.some((node) => String(node.id) === String(heading.id))) {
                    return prev;
                }

                const hasPending = prev.some(
                    (node) => String(node.id) === String(outlineTreeSync.tempHeadingId),
                );
                if (hasPending) {
                    return replaceTreeNodeId(prev, outlineTreeSync.tempHeadingId, {
                        ...heading,
                        children: [],
                    });
                }

                if (outlineTreeSync.afterHeadingId != null) {
                    return insertRootOutlineNodeAfter(prev, outlineTreeSync.afterHeadingId, heading);
                }

                return [...prev, { ...heading, children: [] }];
            });
            setEditingHeadingId(null);
            setActiveGroupId(heading.id);
            setActiveHeadingId(heading.id);

            window.requestAnimationFrame(() => {
                const el = document.querySelector(`[data-outline-heading-id="${heading.id}"]`);
                el?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });

            if (outlineTreeSync.focusEdit) {
                window.requestAnimationFrame(() => {
                    const el = document.querySelector(`[data-outline-heading-id="${heading.id}"]`);
                    el?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    setEditingHeadingId(heading.id);
                });
            }

            return;
        }

        if (outlineTreeSync.action === 'patchText' && outlineTreeSync.headingId != null) {
            const nextText = String(outlineTreeSync.newText ?? '').replace(/\s+/g, ' ').trim();
            if (nextText === '') {
                return;
            }

            setTree((prev) =>
                patchTreeNode(prev, outlineTreeSync.headingId, { heading_text: nextText }),
            );
        }
    }, [
        outlineTreeSync?.token,
        outlineTreeSync?.action,
        outlineTreeSync?.afterHeadingId,
        outlineTreeSync?.heading,
        outlineTreeSync?.headingId,
        outlineTreeSync?.tempHeadingId,
        outlineTreeSync?.focusEdit,
        outlineTreeSync?.newText,
        outlineTreeSync?.matchText,
        outlineTreeSync?.blockId,
        outlineTreeSync?.parentHeadingId,
        applyPendingOutlineFocus,
    ]);

    const handleEditingHeadingEnd = useCallback(() => {
        setEditingHeadingId(null);
    }, []);

    const applyHeadingPatch = useCallback(
        (node, newText) => {
            setTree((prev) => patchTreeNode(prev, node.id, { heading_text: newText }));
            onHeadingTextChange?.({
                level: node.level,
                oldText: node.heading_text,
                newText,
                headingId: node.id,
                headingIndex: node.heading_index,
                blockId: node.block_id ?? null,
            });
        },
        [onHeadingTextChange],
    );

    const handleSaveText = useCallback(
        async (node, newText) => {
            const trimmed = truncateOutlineHeadingText(newText);
            if (trimmed === '') {
                return;
            }

            if (typeof onSaveOutlineHeadingTitle === 'function') {
                const result = await onSaveOutlineHeadingTitle({
                    level: node.level,
                    oldText: node.heading_text,
                    newText: trimmed,
                    headingId: node.id,
                    blockId: node.block_id ?? null,
                    headingIndex: node.heading_index ?? null,
                });

                if (result?.ok === false) {
                    notify(t('outline_notify_title'), result.error?.message || t('outline_heading_save_failed'), 'danger');
                    return;
                }

                if (isPersistedOutlineHeadingId(node.id)) {
                    notify(t('outline_notify_title'), t('outline_heading_saved'), 'success');
                }

                return;
            }

            applyHeadingPatch(node, trimmed);
            try {
                await requestJson(headingUrl(articleId, node.id), {
                    method: 'PUT',
                    body: JSON.stringify({ heading_text: trimmed }),
                });
                notify(t('outline_notify_title'), t('outline_heading_saved'), 'success');
            } catch (e) {
                notify(t('outline_notify_title'), e.message || t('outline_heading_save_failed'), 'danger');
            }
        },
        [applyHeadingPatch, articleId, notify, onSaveOutlineHeadingTitle],
    );

    const handleSaveHtml = useCallback(
        async (node, headingHtml, plainText) => {
            const newText = truncateOutlineHeadingText(plainText);
            if (newText === '') {
                return;
            }

            if (typeof onSaveOutlineHeadingTitle === 'function') {
                const result = await onSaveOutlineHeadingTitle({
                    level: node.level,
                    oldText: node.heading_text,
                    newText,
                    headingHtml,
                    headingId: node.id,
                    blockId: node.block_id ?? null,
                });

                if (result?.ok === false) {
                    notify(t('outline_notify_title'), result.error?.message || t('outline_heading_save_failed'), 'danger');
                    return;
                }

                if (isPersistedOutlineHeadingId(node.id)) {
                    notify(t('outline_notify_title'), t('outline_heading_html_saved'), 'success');
                }

                return;
            }

            applyHeadingPatch(node, newText);
            onHeadingHtmlChange?.({
                level: node.level,
                oldText: node.heading_text,
                headingHtml,
                newText,
                headingId: node.id,
            });

            try {
                await requestJson(headingUrl(articleId, node.id), {
                    method: 'PUT',
                    body: JSON.stringify({ heading_text: newText }),
                });
                notify(t('outline_notify_title'), t('outline_heading_html_saved'), 'success');
            } catch (e) {
                notify(t('outline_notify_title'), e.message || t('outline_heading_save_failed'), 'danger');
            }
        },
        [applyHeadingPatch, articleId, notify, onHeadingHtmlChange, onSaveOutlineHeadingTitle],
    );

    const handleGenerate = useCallback(
        async (node) => {
            setBusyHeadingId(node.id);
            try {
                const data = await requestJson(generateUrl(articleId, node.id), {
                    method: 'POST',
                    body: JSON.stringify({}),
                });

                const newText = String(data.heading?.heading_text ?? '').trim();
                if (newText !== '' && newText !== node.heading_text) {
                    applyHeadingPatch(node, newText);
                }
                notify(t('outline_notify_title'), t('outline_ai_regen_success'), 'success');
            } catch (e) {
                notify(t('outline_notify_title'), e.message || t('outline_ai_regen_failed'), 'danger');
            } finally {
                setBusyHeadingId(null);
            }
        },
        [applyHeadingPatch, articleId, notify],
    );

    const handleMoveHeading = useCallback(
        (node, direction) => {
            setTree((prev) => swapSiblingInTree(prev, node.id, direction));
            onOutlineMoveHeading?.(node, direction);
        },
        [onOutlineMoveHeading],
    );

    const handleDeleteHeading = useCallback(
        (node) => {
            onOutlineDeleteHeading?.(node);
        },
        [onOutlineDeleteHeading],
    );

    return (
        <div
            className="seo-tab-panel seo-outline-panel"
            onClick={() => {
                setActiveGroupId(null);
                setActiveHeadingId(null);
                setEditingHeadingId(null);
            }}
        >
            <div className="seo-outline-toolbar">
                <h3 className="seo-outline-title">{t('outline_title')}</h3>
                <div className="seo-outline-toolbar__actions">
                    {onOutlineAddSection ? (
                        <button
                            type="button"
                            className="seo-outline-reload seo-outline-add-section-btn"
                            title={t('outline_add_h2')}
                            disabled={loading}
                            onClick={(e) => {
                                e.stopPropagation();
                                onOutlineAddSection();
                            }}
                        >
                            <Plus size={15} strokeWidth={1.75} />
                            {t('outline_add_h2')}
                        </button>
                    ) : null}
                    <div className="seo-outline-overflow">
                        <button
                            type="button"
                            className="seo-outline-reload"
                            title={t('outline_more_actions')}
                            disabled={loading}
                            onClick={(e) => {
                                e.stopPropagation();
                                setOverflowOpen((open) => !open);
                            }}
                        >
                            <MoreHorizontal size={15} strokeWidth={1.75} />
                        </button>
                        {overflowOpen ? (
                            <div className="seo-outline-block__menu seo-outline-overflow__menu" onClick={(e) => e.stopPropagation()}>
                                <button
                                    type="button"
                                    disabled={loading}
                                    onClick={() => {
                                        setOverflowOpen(false);
                                        void loadOutline({ reextract: true });
                                    }}
                                >
                                    {t('outline_rebuild_from_document')}
                                </button>
                            </div>
                        ) : null}
                    </div>
                </div>
            </div>

            {loading ? (
                <div className="seo-outline-empty">
                    <Loader2 size={18} className="seo-outline-spin" /> {t('outline_loading')}
                </div>
            ) : error !== '' ? (
                <div className="seo-outline-empty seo-outline-empty--error">{error}</div>
            ) : tree.length === 0 ? (
                <div className="seo-outline-empty">
                    {t('outline_empty')}
                </div>
            ) : (
                <div className="seo-outline-tree">
                    <OutlineTree
                        nodes={tree}
                        activeGroupId={activeGroupId}
                        activeHeadingId={activeHeadingId}
                        editingHeadingId={editingHeadingId}
                        onEditingHeadingEnd={handleEditingHeadingEnd}
                        onSelectGroup={handleSelectGroup}
                        onJumpToEditor={handleJumpToEditor}
                        onSaveText={handleSaveText}
                        onSaveHtml={handleSaveHtml}
                        onGenerate={handleGenerate}
                        onMoveHeading={handleMoveHeading}
                        onDeleteHeading={handleDeleteHeading}
                        onAddNode={onOutlineAddNode}
                        onChangeLevel={onOutlineChangeLevel}
                        onDeleteKeepContent={onOutlineDeleteKeepContent}
                        onDeleteWithContent={onOutlineDeleteWithContent}
                        onToggleVisible={onOutlineToggleVisible}
                        canGenerateOutlineHeading={canGenerateOutlineHeading}
                        resolveHeadingInnerHtml={resolveHeadingInnerHtml}
                        busyHeadingId={busyHeadingId}
                        localDuplicateKeys={localDuplicateKeys}
                    />
                </div>
            )}
        </div>
    );
}
