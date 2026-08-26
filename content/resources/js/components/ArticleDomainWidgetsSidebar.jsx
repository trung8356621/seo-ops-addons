import React, { useEffect, useMemo, useRef, useState } from 'react';
import { ChevronDown, ChevronRight, Link2, Settings2 } from 'lucide-react';
import { t } from '../utils/i18n';
import { filterUsableCtaContacts } from '../utils/ctaContactUsability';
import {
    ctaDisplayLabel,
    formatCtaHref,
} from '../utils/ctaLinkFormat';
import {
    normalizeHrefForCompare,
    normalizeLinkLabel,
} from '../utils/articleLinkSuggestionFilter';
import { collectEditorBlocksFromDom } from '../utils/articlePhraseOccurrences';
import { buildDomainLinkListForEditor, nextDomainLinkOccurrenceIndex } from '../utils/domainLinkOccurrenceIndex';
import { scrollToDomainLinkOccurrence } from '../utils/domainLinkNavigator';
import { insertDomainLinkAction } from '../utils/domainLinkInsertAction';
import {
    CtaContactInsertList,
    CtaQuickTemplateSettingsPopover,
    dispatchCtaInsert,
    useCtaQuickTemplates,
} from './CtaContactInsertList';

/**
 * @typedef {{ text?: string, href?: string, target_url?: string, article_count?: number, occurrence_count?: number, can_insert?: boolean, keyword_id?: number|null }} DomainLinkItem
 * @typedef {{ type?: string, value?: string, label?: string, href?: string, can_insert?: boolean }} DomainCtaItem
 */

function InsertableList({
    items,
    activeKey,
    hiddenRowKeys,
    onKeywordClick,
    onInsert,
    emptyText,
}) {
    if (!items.length) {
        return <p className="wp-article-links-empty">{emptyText}</p>;
    }

    return (
        <ul className="wp-article-links-keywords">
            {items.map((item, index) => {
                const label = String(item?.text ?? '').trim();
                const href = String(item?.href ?? item?.target_url ?? '').trim();
                const count = Number(item?.occurrence_count ?? 0);
                const countSuffix = Number.isFinite(count) ? ` (${count})` : '';
                const itemKey = `domain-link-${label}-${index}`;
                const isActive = activeKey === itemKey;
                const insertable = item?.can_insert !== false && label !== '' && href !== '';
                const canLocate = count > 0;
                const isRowHiding = hiddenRowKeys?.has(itemKey) === true;

                return (
                    <li
                        key={itemKey}
                        className={`wp-article-links-keyword-row${isRowHiding ? ' is-row-hiding' : ''}`}
                        aria-hidden={isRowHiding}
                    >
                        <button
                            type="button"
                            className={`wp-article-links-keyword${isActive ? ' is-active' : ''} is-suggestion`}
                            title={t('domain_link_widget_find', { label, count })}
                            disabled={!canLocate}
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => {
                                if (!canLocate) {
                                    return;
                                }
                                onKeywordClick(item, index, itemKey);
                            }}
                        >
                            {`${label}${countSuffix}`}
                        </button>
                        {onInsert ? (
                            <button
                                type="button"
                                className="wp-article-links-insert-btn"
                                aria-label={t('domain_link_widget_insert_for', { label })}
                                title={t('domain_link_widget_insert_for', { label })}
                                disabled={!insertable}
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={(e) => {
                                    e.stopPropagation();
                                    if (insertable) {
                                        onInsert(item, itemKey);
                                    }
                                }}
                            >
                                <Link2 size={14} aria-hidden />
                            </button>
                        ) : null}
                    </li>
                );
            })}
        </ul>
    );
}

function WidgetBox({ title, subtitle, collapsed, onToggle, headerExtra = null, children }) {
    return (
        <div className="wp-postbox wp-article-links-box">
            <div className="wp-postbox-header">
                <h2>
                    {title}
                    {subtitle ? <span className="wp-article-links-counts">{subtitle}</span> : null}
                </h2>
                <div className="wp-postbox-header-actions">
                    {headerExtra}
                    <button
                        type="button"
                        className="wp-postbox-toggle"
                        aria-expanded={!collapsed}
                        title={collapsed ? t('links_expand') : t('links_collapse')}
                        onClick={onToggle}
                    >
                        {collapsed ? <ChevronRight size={16} /> : <ChevronDown size={16} />}
                    </button>
                </div>
            </div>
            {!collapsed ? <div className="wp-postbox-inside">{children}</div> : null}
        </div>
    );
}

const applyDomainLinkFilters = (allLinks, internalLinks, externalLinks = []) => {
    const blocks = collectEditorBlocksFromDom();
    return buildDomainLinkListForEditor(allLinks, blocks, internalLinks, externalLinks);
};

/**
 * @param {{
 *   siteId?: number|null,
 *   initialDomainLinkList?: DomainLinkItem[],
 *   initialDomainLinkCatalog?: DomainLinkItem[],
 *   initialDomainCtaList?: DomainCtaItem[],
 *   initialCtaQuickTemplates?: unknown,
 * }} props
 */
export default function ArticleDomainWidgetsSidebar({
    siteId = null,
    initialDomainLinkList = [],
    initialDomainLinkCatalog = [],
    initialDomainCtaList = [],
    initialCtaQuickTemplates = null,
}) {
    const allDomainLinksRef = useRef(
        initialDomainLinkCatalog.length > 0 ? initialDomainLinkCatalog : initialDomainLinkList,
    );
    const [domainLinkCatalogCount, setDomainLinkCatalogCount] = useState(
        initialDomainLinkCatalog.length > 0
            ? initialDomainLinkCatalog.length
            : initialDomainLinkList.length,
    );
    const [domainLinks, setDomainLinks] = useState(() =>
        applyDomainLinkFilters(
            initialDomainLinkCatalog.length > 0 ? initialDomainLinkCatalog : initialDomainLinkList,
            [],
            [],
        ),
    );
    const [domainCtas, setDomainCtas] = useState(initialDomainCtaList);
    const [domainLinkActiveKey, setDomainLinkActiveKey] = useState('');
    const [ctaActiveKey, setCtaActiveKey] = useState('');
    const [linksCollapsed, setLinksCollapsed] = useState(false);
    const [ctaCollapsed, setCtaCollapsed] = useState(false);
    const [ctaSettingsOpen, setCtaSettingsOpen] = useState(false);
    const [hiddenRowKeys, setHiddenRowKeys] = useState(() => new Set());
    const [serverCtaTemplates, setServerCtaTemplates] = useState(initialCtaQuickTemplates);
    const [templatesByType, setTemplatesByType] = useCtaQuickTemplates(siteId, serverCtaTemplates);
    const usableDomainCtas = useMemo(() => filterUsableCtaContacts(domainCtas), [domainCtas]);
    const [cycleByKey, setCycleByKey] = useState({});
    const selectedDomainOccurrenceRef = useRef(null);

    const hideRow = (itemKey) => {
        if (!itemKey) {
            return;
        }
        setHiddenRowKeys((prev) => {
            if (prev.has(itemKey)) {
                return prev;
            }
            const next = new Set(prev);
            next.add(itemKey);
            return next;
        });
    };

    useEffect(() => {
        const onLinksUpdate = (event) => {
            const internal = Array.isArray(event.detail?.links?.internal)
                ? event.detail.links.internal
                : Array.isArray(event.detail?.extracted_links?.internal)
                  ? event.detail.extracted_links.internal
                  : [];
            const external = Array.isArray(event.detail?.links?.external)
                ? event.detail.links.external
                : Array.isArray(event.detail?.extracted_links?.external)
                  ? event.detail.extracted_links.external
                  : [];

            setDomainLinks(
                applyDomainLinkFilters(allDomainLinksRef.current, internal, external),
            );
        };

        const onInserted = (event) => {
            const text = normalizeLinkLabel(event.detail?.text);
            const hrefKey = normalizeHrefForCompare(event.detail?.href);
            if (!text && !hrefKey) {
                return;
            }

            setDomainLinks((prev) =>
                prev.filter((item) => {
                    if (text && normalizeLinkLabel(item.text) === text) {
                        return false;
                    }
                    if (hrefKey && normalizeHrefForCompare(item.href ?? item.target_url) === hrefKey) {
                        return false;
                    }
                    return true;
                }),
            );
            setHiddenRowKeys(new Set());
        };

        window.addEventListener('seo-editor-links-updated', onLinksUpdate);
        window.addEventListener('seo-editor-suggested-link-inserted', onInserted);

        return () => {
            window.removeEventListener('seo-editor-links-updated', onLinksUpdate);
            window.removeEventListener('seo-editor-suggested-link-inserted', onInserted);
        };
    }, []);

    const scrollToItem = (item, itemKey, variant) => {
        if (variant === 'cta') {
            setCtaActiveKey(itemKey);
            window.dispatchEvent(
                new CustomEvent('seo-editor-scroll-to-link', {
                    detail: {
                        href: String(item?.href ?? formatCtaHref(item?.type, item?.value)).trim(),
                        text: ctaDisplayLabel(item),
                        type: 'internal',
                        index: 0,
                        searchPlainText: true,
                    },
                }),
            );
            return;
        }

        setDomainLinkActiveKey(itemKey);
        const occurrences = Array.isArray(item?._domain_occurrences) ? item._domain_occurrences : [];
        if (occurrences.length === 0) {
            selectedDomainOccurrenceRef.current = { itemKey, occurrence: null, index: 0 };
            return;
        }

        const currentCycle = Number(cycleByKey[itemKey] ?? 0);
        const occurrenceIndex = nextDomainLinkOccurrenceIndex(currentCycle, occurrences.length);
        const occurrence = occurrences[occurrenceIndex] ?? null;
        setCycleByKey((prev) => ({
            ...prev,
            [itemKey]: currentCycle + 1,
        }));
        selectedDomainOccurrenceRef.current = { itemKey, occurrence, index: occurrenceIndex };
        if (occurrence) {
            scrollToDomainLinkOccurrence(occurrence);
        }
    };

    const insertDomainLink = (item, itemKey) => {
        hideRow(itemKey);
        const selected = selectedDomainOccurrenceRef.current;
        const occurrence =
            selected?.itemKey === itemKey && selected?.occurrence
                ? selected.occurrence
                : (Array.isArray(item?._domain_occurrences) ? item._domain_occurrences[0] : null);
        insertDomainLinkAction({ item, occurrence });
    };

    return (
        <>
            <WidgetBox
                title={t('domain_link_widget_title')}
                subtitle={` (${domainLinks.length})`}
                collapsed={linksCollapsed}
                onToggle={() => setLinksCollapsed((v) => !v)}
            >
                <p className="wp-article-links-hint">{t('domain_link_widget_hint')}</p>
                <InsertableList
                    items={domainLinks}
                    activeKey={domainLinkActiveKey}
                    hiddenRowKeys={hiddenRowKeys}
                    emptyText={
                        domainLinkCatalogCount > 0
                            ? t('domain_link_widget_empty_in_article')
                            : t('domain_link_widget_empty')
                    }
                    onKeywordClick={(item, _index, itemKey) => scrollToItem(item, itemKey, 'domain-link')}
                    onInsert={insertDomainLink}
                />
            </WidgetBox>

            <WidgetBox
                title={t('cta_widget_title')}
                subtitle={` (${usableDomainCtas.length})`}
                collapsed={ctaCollapsed}
                onToggle={() => setCtaCollapsed((v) => !v)}
                headerExtra={(
                    <div className="wp-article-links-cta-quick-wrap">
                        <button
                            type="button"
                            className="wp-article-links-insert-btn"
                            aria-label={t('cta_widget_settings_title')}
                            title={t('cta_widget_settings_title')}
                            onClick={() => setCtaSettingsOpen(true)}
                        >
                            <Settings2 size={14} aria-hidden />
                        </button>
                        <CtaQuickTemplateSettingsPopover
                            siteId={siteId}
                            open={ctaSettingsOpen}
                            onClose={() => setCtaSettingsOpen(false)}
                            settings={templatesByType}
                            onSave={setTemplatesByType}
                        />
                    </div>
                )}
            >
                <p className="wp-article-links-hint">{t('cta_widget_hint')}</p>
                <CtaContactInsertList
                    items={domainCtas}
                    activeKey={ctaActiveKey}
                    templatesByType={templatesByType}
                    emptyText={t('cta_widget_empty')}
                    onKeywordClick={(item, _index, itemKey) => scrollToItem(item, itemKey, 'cta')}
                    onInsertQuickCta={(item, _itemKey, templateOverride, mode = 'sentence') =>
                        dispatchCtaInsert(item, mode, templateOverride, templatesByType)
                    }
                />
            </WidgetBox>
        </>
    );
}
