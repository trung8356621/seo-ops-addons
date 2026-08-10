import React, { useEffect, useMemo, useState } from 'react';
import {
    ChevronDown,
    Clock,
    Link2,
    Mail,
    MapPin,
    MessageCircle,
    Phone,
    Plus,
    Quote,
} from 'lucide-react';
import { t } from '../utils/i18n';
import {
    ctaDisplayLabel,
    formatCtaHref,
    isCtaItemInsertable,
    isCtaPlainTextType,
} from '../utils/ctaLinkFormat';
import { filterUsableCtaContacts } from '../utils/ctaContactUsability';
import {
    getInsertionContextForCommand,
    preserveEditorContextBeforeSidebarAction,
} from '../utils/editorInsertionContext';
import { getEditorCommandHost } from '../utils/editorCommands';
import { canMutateEditor } from '../utils/editorSessionState';
import {
    getDefaultCtaQuickTemplate,
    loadCtaQuickTemplatesFromStorage,
    normalizeCtaQuickTemplateSettings,
    resolveCtaQuickTemplate,
    saveCtaQuickTemplatesToStorage,
    validateCtaQuickTemplate,
} from '../utils/ctaQuickTemplates';
import { seoArticleApiFetch } from '@seo-addon/utils/seoArticleApi.js';

/**
 * Snapshot editor caret before sidebar action. No preventDefault — keep a11y.
 *
 * @param {React.PointerEvent|React.MouseEvent} [_event]
 */
function captureCtaInsertionBeforeFocusSteal(_event) {
    preserveEditorContextBeforeSidebarAction(_event);
}

function ctaTypeIcon(type) {
    switch (String(type || '').toLowerCase()) {
        case 'phone':
        case 'hotline':
            return Phone;
        case 'email':
            return Mail;
        case 'zalo':
        case 'chat':
            return MessageCircle;
        case 'address':
            return MapPin;
        case 'facebook':
        case 'website':
            return Link2;
        case 'working_hours':
        case 'hours':
            return Clock;
        default:
            return Quote;
    }
}

/**
 * @param {{
 *   items: unknown[],
 *   activeKey: string,
 *   onKeywordClick: Function,
 *   onInsertQuickCta: Function,
 *   templatesByType: Record<string, { defaultIndex: number, templates: string[] }>,
 *   emptyText: string,
 * }} props
 */
export function CtaContactInsertList({
    items,
    activeKey,
    onKeywordClick,
    onInsertQuickCta,
    templatesByType,
    emptyText,
}) {
    const usable = useMemo(() => filterUsableCtaContacts(items), [items]);
    const [menuKey, setMenuKey] = useState('');

    useEffect(() => {
        if (!menuKey) {
            return undefined;
        }

        const onDoc = (event) => {
            if (event.target?.closest?.('[data-cta-quick-menu]')) {
                return;
            }
            setMenuKey('');
        };
        document.addEventListener('mousedown', onDoc);
        return () => document.removeEventListener('mousedown', onDoc);
    }, [menuKey]);

    if (!usable.length) {
        return <p className="wp-article-links-empty">{emptyText}</p>;
    }

    return (
        <ul className="wp-article-links-keywords">
            {usable.map((item, index) => {
                const label = ctaDisplayLabel(item);
                const type = String(item?.type ?? '').toLowerCase();
                const itemKey = `cta-${type}-${label}-${index}`;
                const isActive = activeKey === itemKey;
                const insertable = isCtaItemInsertable(item) && canMutateEditor();
                const contactTooltip = insertable
                    ? t(`cta_widget_insert_${type === 'hotline' ? 'phone' : type}_tooltip`)
                    : t('editor_locked_mutation_tooltip');
                const primaryTooltip = contactTooltip === `cta_widget_insert_${type === 'hotline' ? 'phone' : type}_tooltip`
                    ? t('cta_widget_insert_contact_tooltip')
                    : contactTooltip;
                const TypeIcon = ctaTypeIcon(type);
                const templates =
                    templatesByType?.[type]?.templates
                    ?? templatesByType?.[type === 'hotline' ? 'phone' : type]?.templates
                    ?? [];

                return (
                    <li key={itemKey} className="wp-article-links-keyword-row wp-article-links-keyword-row--cta">
                        <button
                            type="button"
                            className={`wp-article-links-keyword${isActive ? ' is-active' : ''} is-suggestion`}
                            title={t('cta_widget_find', { label, type })}
                            onPointerDown={captureCtaInsertionBeforeFocusSteal}
                            onMouseDown={captureCtaInsertionBeforeFocusSteal}
                            onClick={() => onKeywordClick(item, index, itemKey)}
                        >
                            <span className="wp-article-domain-cta-stack">
                                <span className="wp-article-domain-cta-type-line">{type || 'cta'}</span>
                                <span className="wp-article-domain-cta-value">{label}</span>
                            </span>
                        </button>
                        <div className="wp-article-links-cta-actions" data-cta-quick-menu>
                            <div className="wp-article-links-cta-quick-wrap">
                                <button
                                    type="button"
                                    className="wp-article-links-insert-btn wp-article-links-insert-btn--contact"
                                    aria-label={primaryTooltip}
                                    title={primaryTooltip}
                                    data-cta-action="insert_contact_value"
                                    disabled={!insertable}
                                    onPointerDown={captureCtaInsertionBeforeFocusSteal}
                                    onMouseDown={captureCtaInsertionBeforeFocusSteal}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        if (insertable) {
                                            onInsertQuickCta(item, itemKey, null, 'value');
                                        }
                                    }}
                                >
                                    <TypeIcon size={14} strokeWidth={2} aria-hidden />
                                </button>
                                <button
                                    type="button"
                                    className="wp-article-links-insert-btn wp-article-links-insert-btn--sentence"
                                    aria-label={t('cta_widget_insert_sentence_tooltip')}
                                    title={t('cta_widget_insert_sentence_tooltip')}
                                    data-cta-action="open_cta_templates"
                                    disabled={!insertable}
                                    onPointerDown={captureCtaInsertionBeforeFocusSteal}
                                    onMouseDown={captureCtaInsertionBeforeFocusSteal}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        if (!insertable) {
                                            return;
                                        }
                                        if (templates.length <= 1) {
                                            onInsertQuickCta(item, itemKey, null, 'sentence');
                                            return;
                                        }
                                        setMenuKey((prev) => (prev === itemKey ? '' : itemKey));
                                    }}
                                >
                                    <Quote size={14} strokeWidth={2} aria-hidden />
                                    <ChevronDown
                                        size={10}
                                        strokeWidth={2}
                                        aria-hidden
                                        className={templates.length > 1 ? 'is-visible' : 'is-spacer'}
                                    />
                                </button>
                                {menuKey === itemKey ? (
                                    <ul className="wp-article-links-cta-template-menu">
                                        <li>
                                            <button
                                                type="button"
                                                data-cta-action="insert_contact_cta"
                                                onPointerDown={captureCtaInsertionBeforeFocusSteal}
                                                onMouseDown={captureCtaInsertionBeforeFocusSteal}
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    setMenuKey('');
                                                    if (insertable) {
                                                        onInsertQuickCta(item, itemKey, null, 'sentence');
                                                    }
                                                }}
                                            >
                                                {t('cta_widget_insert_sentence')}
                                            </button>
                                        </li>
                                        {templates.map((template) => (
                                            <li key={template}>
                                                <button
                                                    type="button"
                                                    data-cta-action="insert_contact_cta"
                                                    onPointerDown={captureCtaInsertionBeforeFocusSteal}
                                                    onMouseDown={captureCtaInsertionBeforeFocusSteal}
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        setMenuKey('');
                                                        onInsertQuickCta(item, itemKey, template, 'sentence');
                                                    }}
                                                >
                                                    {resolveCtaQuickTemplate(template, item)}
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                ) : null}
                            </div>
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}

/**
 * @param {{
 *   siteId?: number|null,
 *   open: boolean,
 *   onClose: () => void,
 *   settings: Record<string, { defaultIndex: number, templates: string[] }>,
 *   onSave: (next: Record<string, { defaultIndex: number, templates: string[] }>) => void,
 * }} props
 */
export function CtaQuickTemplateSettingsPopover({ siteId = 0, open, onClose, settings, onSave }) {
    const [draft, setDraft] = useState(() => normalizeCtaQuickTemplateSettings(settings));
    const [error, setError] = useState('');
    const [activeType, setActiveType] = useState('hotline');
    const [newTemplate, setNewTemplate] = useState('');

    useEffect(() => {
        if (open) {
            setDraft(normalizeCtaQuickTemplateSettings(settings));
            setError('');
            setNewTemplate('');
        }
    }, [open, settings]);

    if (!open) {
        return null;
    }

    const row = draft[activeType] ?? { defaultIndex: 0, templates: [] };

    return (
        <div className="wp-article-cta-settings" role="dialog" aria-label={t('cta_widget_settings_title')}>
            <div className="wp-article-cta-settings__header">
                <strong>{t('cta_widget_settings_title')}</strong>
                <button type="button" className="wp-article-cta-settings__close" onClick={onClose}>
                    ×
                </button>
            </div>
            <div className="wp-article-cta-settings__types">
                {Object.keys(draft).map((type) => (
                    <button
                        key={type}
                        type="button"
                        className={type === activeType ? 'is-active' : ''}
                        onClick={() => setActiveType(type)}
                    >
                        {type}
                    </button>
                ))}
            </div>
            <ul className="wp-article-cta-settings__list">
                {row.templates.map((template, index) => (
                    <li key={`${activeType}-${index}`}>
                        <label>
                            <input
                                type="radio"
                                name={`cta-default-${activeType}`}
                                checked={row.defaultIndex === index}
                                onChange={() => {
                                    setDraft((prev) => ({
                                        ...prev,
                                        [activeType]: { ...prev[activeType], defaultIndex: index },
                                    }));
                                }}
                            />
                            <span>{template}</span>
                        </label>
                        <button
                            type="button"
                            title={t('cta_widget_delete_template')}
                            onClick={() => {
                                setDraft((prev) => {
                                    const templates = prev[activeType].templates.filter((_, i) => i !== index);
                                    return {
                                        ...prev,
                                        [activeType]: {
                                            templates,
                                            defaultIndex: Math.max(
                                                0,
                                                Math.min(prev[activeType].defaultIndex, templates.length - 1),
                                            ),
                                        },
                                    };
                                });
                            }}
                        >
                            ×
                        </button>
                    </li>
                ))}
            </ul>
            <div className="wp-article-cta-settings__add">
                <input
                    type="text"
                    value={newTemplate}
                    onChange={(e) => setNewTemplate(e.target.value)}
                    placeholder={t('cta_widget_template_placeholder', { type: activeType })}
                />
                <button
                    type="button"
                    onClick={() => {
                        const validation = validateCtaQuickTemplate(newTemplate, activeType);
                        if (!validation.ok) {
                            setError(validation.error);
                            return;
                        }
                        setError('');
                        setDraft((prev) => ({
                            ...prev,
                            [activeType]: {
                                ...prev[activeType],
                                templates: [...prev[activeType].templates, newTemplate.trim()],
                            },
                        }));
                        setNewTemplate('');
                    }}
                >
                    <Plus size={14} />
                </button>
            </div>
            {error ? <p className="wp-article-cta-settings__error">{error}</p> : null}
            <div className="wp-article-cta-settings__footer">
                <button type="button" onClick={onClose}>
                    {t('cancel')}
                </button>
                <button
                    type="button"
                    className="is-primary"
                    onClick={() => {
                        const normalized = normalizeCtaQuickTemplateSettings(draft);
                        for (const [type, row] of Object.entries(normalized)) {
                            for (const template of row.templates) {
                                const check = validateCtaQuickTemplate(template, type);
                                if (!check.ok) {
                                    setError(check.error || 'Invalid template');
                                    return;
                                }
                            }
                        }
                        void (async () => {
                            try {
                                const { response, data } = await seoArticleApiFetch('/api/seo/domain-cta/quick-templates', {
                                    method: 'PUT',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({
                                        cta_quick_templates: Object.fromEntries(
                                            Object.entries(normalized).map(([type, row]) => [
                                                type,
                                                {
                                                    default_index: row.defaultIndex,
                                                    templates: row.templates,
                                                },
                                            ]),
                                        ),
                                    }),
                                });
                                if (!response.ok || data?.success === false) {
                                    setError(String(data?.message || data?.error || 'cta_template_save_failed'));
                                    return;
                                }
                                const saved = normalizeCtaQuickTemplateSettings(
                                    data?.cta_quick_templates ?? normalized,
                                );
                                // Legacy LS write removed as SoT — optional discard only.
                                try {
                                    window.localStorage?.removeItem(`seo-cta-quick-templates:v1:${Number(siteId) || 0}`);
                                } catch {
                                    // ignore
                                }
                                onSave(saved);
                                onClose();
                            } catch (error) {
                                setError(String(error?.message || 'cta_template_save_failed'));
                            }
                        })();
                    }}
                >
                    {t('apply')}
                </button>
            </div>
        </div>
    );
}

/**
 * Dispatch CTA insert using current EditorInsertionContext.
 *
 * @param {{ type?: string, value?: string, label?: string, href?: string, plain_text?: boolean }} item
 * @param {'value'|'sentence'} mode
 * @param {string|null} templateOverride
 * @param {Record<string, { defaultIndex: number, templates: string[] }>} templatesByType
 * @param {number} [occurrenceIndex]
 */
function notifyCta(detail) {
    const host = getEditorCommandHost();
    if (typeof host?.notify === 'function') {
        host.notify(detail);
        return;
    }
    window.dispatchEvent(new CustomEvent('seo-article-editor-notify', { detail }));
}

/**
 * CTA insert via command-host action (Phase 6C.2 — no internal CustomEvent bus).
 */
export function dispatchCtaInsert(item, mode, templateOverride, templatesByType, occurrenceIndex = 0) {
    if (!canMutateEditor()) {
        notifyCta({
            title: t('editor_locked_title'),
            body: t('editor_locked_mutation_tooltip'),
            status: 'warning',
            reason_code: 'editor_read_only',
        });
        return;
    }
    const type = String(item?.type ?? '').toLowerCase();
    if (!type) {
        return;
    }

    const effectiveMode = mode === 'value' ? 'value' : 'sentence';
    const resolvedOccurrence = Math.max(0, Number(occurrenceIndex) || 0);

    // Prefer bookmark frozen on pointerdown (before dropdown stole focus).
    const ctx = getInsertionContextForCommand();
    const target = {
        sectionId: ctx.activeSectionId,
        blockId: ctx.activeBlockId,
        selectionBookmark: ctx.selection,
    };

    const runInsert = (detail) => {
        const actions = getEditorCommandHost()?.actions;
        if (typeof actions?.insertCtaLink === 'function') {
            actions.insertCtaLink(detail);
            return;
        }
        // Deprecated fallback for external consumers mid-rollout.
        window.dispatchEvent(new CustomEvent('seo-editor-insert-cta-link', { detail }));
    };

    if (effectiveMode === 'sentence') {
        const template =
            String(templateOverride ?? '').trim()
            || getDefaultCtaQuickTemplate(type, templatesByType)
            || getDefaultCtaQuickTemplate(type === 'hotline' ? 'phone' : type, templatesByType);
        const resolved = resolveCtaQuickTemplate(template, item);
        if (!resolved || resolved.includes('[') && /\[(phone|email|zalo|address|facebook|working_hours|website|label)\]/i.test(resolved)) {
            notifyCta({
                title: t('cta_widget_missing_data_title'),
                body: t('cta_widget_missing_data_body', { type }),
                status: 'warning',
            });
            return;
        }

        const plainText = isCtaPlainTextType(type) || item?.plain_text === true;
        const href = plainText ? '' : String(item?.href ?? formatCtaHref(type, item?.value)).trim();
        const valueLabel = ctaDisplayLabel(item);
        const stillHasPlaceholder = /\[[^\]]+\]/u.test(resolved);
        if (!resolved || stillHasPlaceholder) {
            notifyCta({
                title: t('cta_widget_missing_data_title'),
                body: t('cta_widget_missing_data_body', { type }),
                status: 'warning',
            });
            return;
        }

        runInsert({
            text: resolved,
            href: plainText ? '' : href,
            type,
            value_label: valueLabel,
            sentence: resolved,
            is_sentence: true,
            is_cta_sentence: true,
            is_cta_block: true,
            target,
        });
        return;
    }

    // Value mode: wrap existing phrase like internal-link insert (occurrence from find cycle).
    const text = ctaDisplayLabel(item);
    const plainText = isCtaPlainTextType(type) || item?.plain_text === true;
    const href = plainText ? '' : String(item?.href ?? formatCtaHref(type, item?.value)).trim();
    if (!text || (!href && !plainText)) {
        notifyCta({
            title: t('cta_widget_missing_data_title'),
            body: t('cta_widget_missing_data_body', { type }),
            status: 'warning',
        });
        return;
    }

    runInsert({
        text,
        href,
        type,
        target,
        occurrence_index: resolvedOccurrence,
        is_cta_block: false,
        is_sentence: false,
        is_contact_value: true,
    });
}

/**
 * @param {number|string|null|undefined} siteId
 * @param {unknown} serverTemplates
 */
export function useCtaQuickTemplates(siteId, serverTemplates = null) {
    const [templatesByType, setTemplatesByType] = useState(() => {
        // Phase 2C: server canonical; localStorage is not SoT.
        if (serverTemplates && typeof serverTemplates === 'object') {
            return normalizeCtaQuickTemplateSettings(normalizeServerTemplates(serverTemplates));
        }
        return normalizeCtaQuickTemplateSettings(null);
    });
    const [settingsVersion, setSettingsVersion] = useState('');

    useEffect(() => {
        if (serverTemplates && typeof serverTemplates === 'object') {
            setTemplatesByType(normalizeCtaQuickTemplateSettings(normalizeServerTemplates(serverTemplates)));
            // Discard legacy LS shadow SoT.
            try {
                const id = Number(siteId) || 0;
                window.localStorage?.removeItem(`seo-cta-quick-templates:v1:${id}`);
            } catch {
                // ignore
            }
        }
    }, [serverTemplates, siteId]);

    return [templatesByType, setTemplatesByType, settingsVersion, setSettingsVersion];
}

function normalizeServerTemplates(serverTemplates) {
    /** @type {Record<string, { defaultIndex: number, templates: string[] }>} */
    const next = {};
    for (const [type, row] of Object.entries(serverTemplates || {})) {
        if (!row || typeof row !== 'object') {
            continue;
        }
        next[type] = {
            defaultIndex: Number(row.default_index ?? row.defaultIndex ?? 0) || 0,
            templates: Array.isArray(row.templates) ? row.templates.map((v) => String(v)) : [],
        };
    }
    return next;
}
