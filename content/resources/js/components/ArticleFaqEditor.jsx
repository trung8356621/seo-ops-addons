import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { RefreshCw, Plus, Trash2, AlertCircle, Sparkles, FileCode, ListTree } from 'lucide-react';
import FaqAnswerEditor from './FaqAnswerEditor';
import { answerHtmlForEditor } from '../utils/faqAnswerHtml';
import { useDebouncedCallback } from '../hooks/useDebouncedCallback';
import { loadFaqDraft, saveFaqDraft, clearFaqDraft } from '../utils/articleEditorStorage';
import {
    applyFaqSnapshot,
    generateFaqPreview,
    itemsFromFaqSnapshot,
    rememberFaqSnapshot,
    replaceFaqSnapshot,
} from '../utils/articleEditorFaqSnapshot';
import { runFaqExtractFromToolbar } from '../editor/modules/faq/faqExtractToolbarAction';
import { t } from '../utils/i18n';
import { canMutateEditor } from '../utils/editorSessionState';

const normalizeQuestion = (text) =>
    (text || '')
        .trim()
        .toLowerCase()
        .replace(/\s+/g, ' ');

const newFaqRow = (sortOrder = 1) => ({
    id: null,
    question: '',
    answer: '<p></p>',
    sort_order: sortOrder,
    duplicate: false,
    duplicate_scope: null,
});

const applyLocalDuplicates = (rows) => {
    return rows.map((row) => {
        const duplicateScope = row.duplicate_scope === 'site' ? 'site' : null;

        return {
            ...row,
            duplicate: duplicateScope === 'site',
            duplicate_scope: duplicateScope,
        };
    });
};

const pickFaqField = (row, keys) => {
    for (const key of keys) {
        const value = String(row?.[key] ?? '').trim();
        if (value !== '') {
            return value;
        }
    }

    return '';
};

const normalizeFaqRowShape = (row) => {
    const question = pickFaqField(row, ['question', 'q', 'title', 'name', 'label', 'heading']);
    let answer = pickFaqField(row, ['answer', 'a', 'content', 'body', 'text', 'response', 'value']);
    const more = pickFaqField(row, ['more', 'see_more', 'seeMore', 'xem_them', 'intro', 'lead']);

    if (answer === '' && more !== '') {
        answer = more;
    }

    return {
        ...row,
        question: question || row?.question || '',
        answer: answerHtmlForEditor(answer || row?.answer),
        more: more || row?.more || '',
    };
};

const normalizeFaqRows = (rows) =>
    applyLocalDuplicates((rows ?? []).map(normalizeFaqRowShape).filter((row) => String(row.answer ?? '').trim() !== ''));

const reasonLabels = {
    no_pairs: t('faq_debug_no_pairs'),
    no_valid_pairs: t('faq_debug_no_valid_pairs'),
    body_sync_no_pairs: t('faq_debug_body_sync_no_pairs'),
    wp_sync_empty_faqs: t('faq_debug_wp_sync_empty_faqs'),
    wp_pull_no_pairs: t('faq_debug_wp_pull_no_pairs'),
};

const contextLabels = {
    manual_selection: t('faq_context_manual_selection'),
    article_body: t('faq_context_article_body'),
    sync: t('faq_context_sync'),
    wp_pull: t('faq_context_wp_pull'),
    wp_domain_sync: t('faq_context_wp_domain_sync'),
};

function FaqExtractDebugBanner({ debug, onDismiss, onFixed }) {
    if (!debug || typeof debug !== 'object') {
        return null;
    }

    const heading = debug.heading ?? null;
    const headingText = heading?.text?.trim?.() ?? '';
    const headingSource =
        heading?.source === 'article'
            ? t('faq_debug_heading_article')
            : heading?.source === 'selection'
              ? t('faq_debug_heading_selection')
              : null;
    const candidates = Array.isArray(debug.question_candidates) ? debug.question_candidates : [];

    return (
        <div className="seo-faq-extract-debug" role="alert">
            <div className="seo-faq-extract-debug__head">
                <strong>{t('faq_debug_title')}</strong>
                <div className="seo-faq-extract-debug__actions">
                    <button type="button" className="seo-faq-extract-debug__fixed" onClick={onFixed}>
                        {t('faq_debug_fixed')}
                    </button>
                    <button type="button" className="seo-faq-extract-debug__dismiss" onClick={onDismiss}>
                        {t('faq_debug_hide')}
                    </button>
                </div>
            </div>
            {debug.article_id ? (
                <p className="seo-faq-extract-debug__option text-xs opacity-80">
                    Lưu Laravel <code>omi_channel.wp_options</code> →{' '}
                    <code>seo_faq_extract_debug_{debug.article_id}</code>
                </p>
            ) : null}
            <dl className="seo-faq-extract-debug__meta">
                <div>
                    <dt>{t('faq_debug_source')}</dt>
                    <dd>{contextLabels[debug.context] ?? debug.context ?? '—'}</dd>
                </div>
                <div>
                    <dt>{t('faq_debug_reason')}</dt>
                    <dd>{reasonLabels[debug.reason] ?? debug.reason ?? '—'}</dd>
                </div>
                <div>
                    <dt>{t('faq_debug_heading')}</dt>
                    <dd>
                        {headingText !== '' ? (
                            <>
                                {headingSource ? <span className="block text-xs opacity-80">{headingSource}</span> : null}
                                <span className="seo-faq-extract-debug__heading">{headingText}</span>
                            </>
                        ) : (
                            t('faq_debug_not_found')
                        )}
                    </dd>
                </div>
                <div>
                    <dt>{t('faq_debug_parser')}</dt>
                    <dd>
                        {debug.parsed_total ?? 0} dòng · {debug.valid_pairs ?? 0} cặp hợp lệ
                    </dd>
                </div>
            </dl>
            {candidates.length > 0 ? (
                <div className="seo-faq-extract-debug__candidates">
                    <p className="text-xs font-semibold text-amber-900 dark:text-amber-200">
                        {t('faq_debug_candidates', { count: candidates.length })}
                    </p>
                    <ul>
                        {candidates.map((q, i) => (
                            <li key={`${i}-${q.slice(0, 24)}`}>{q}</li>
                        ))}
                    </ul>
                </div>
            ) : null}
            {debug.fragment_preview ? (
                <p className="seo-faq-extract-debug__preview">
                    <span className="font-semibold">{t('faq_debug_fragment')}</span> {debug.fragment_preview}
                </p>
            ) : null}
            {heading?.html ? (
                <details className="seo-faq-extract-debug__html">
                    <summary>{t('faq_debug_heading_html')}</summary>
                    <pre>{heading.html}</pre>
                </details>
            ) : null}
        </div>
    );
}

export default function ArticleFaqEditor({
    articleId,
    initialFaqs = [],
    initialExtractDebug = null,
    canGenerateFaq = false,
    canImportMarkdownFaq = false,
    initialFaqSnapshot = null,
}) {
    const [faqs, setFaqs] = useState(() => {
        // Phase 2C: server/initial snapshot wins — never hydrate SoT from localStorage.
        if (Array.isArray(initialFaqs) && initialFaqs.length > 0) {
            return normalizeFaqRows(initialFaqs);
        }
        if (initialFaqSnapshot?.items) {
            return normalizeFaqRows(itemsFromFaqSnapshot(initialFaqSnapshot));
        }
        // Recovery-only: local draft if server empty (shown once; cleared after first canonical save).
        const localFaqs = loadFaqDraft(articleId);
        return normalizeFaqRows(localFaqs ?? initialFaqs);
    });
    const [extractDebug, setExtractDebug] = useState(
        initialExtractDebug && typeof initialExtractDebug === 'object' ? initialExtractDebug : null,
    );
    const [aiPreviewPending, setAiPreviewPending] = useState(false);
    const faqsRef = React.useRef(faqs);
    faqsRef.current = faqs;

    useEffect(() => {
        if (initialFaqSnapshot) {
            rememberFaqSnapshot(articleId, initialFaqSnapshot);
        }
        // Drop legacy canonical shadow LS when server items present.
        if (Array.isArray(initialFaqs) && initialFaqs.length > 0) {
            clearFaqDraft(articleId);
        }
    }, [articleId, initialFaqSnapshot, initialFaqs]);

    useEffect(() => {
        window.__seoCollectArticleFaqs = () => [...(faqsRef.current ?? [])];

        return () => {
            delete window.__seoCollectArticleFaqs;
        };
    }, []);

    useEffect(() => {
        window.dispatchEvent(
            new CustomEvent('article-faq-rows-changed', {
                detail: { faqs },
            }),
        );
    }, [faqs]);

    const skipBlurDuplicateCheckRef = useRef(false);
    const flushFaqsInFlightRef = useRef(false);
    const [renewingIndex, setRenewingIndex] = useState(null);
    const [generatingAll, setGeneratingAll] = useState(false);
    const [markdownImportOpen, setMarkdownImportOpen] = useState(false);
    const [markdownImportDraft, setMarkdownImportDraft] = useState('');
    const [importingMarkdown, setImportingMarkdown] = useState(false);
    const [hasEditorSelection, setHasEditorSelection] = useState(false);
    const [saveStatus, setSaveStatus] = useState('saved');

    useEffect(() => {
        const onSelection = (event) => {
            setHasEditorSelection(Boolean(event.detail?.hasSelection && event.detail?.text));
        };

        window.addEventListener('seo-editor-text-selection', onSelection);

        return () => {
            window.removeEventListener('seo-editor-text-selection', onSelection);
        };
    }, []);

    const extractFaqFromSelection = useCallback(() => {
        void runFaqExtractFromToolbar({ articleId });
    }, [articleId]);

    const flushFaqs = useCallback(() => {
        if (!articleId) return;
        if (flushFaqsInFlightRef.current) {
            return;
        }
        if (!canMutateEditor()) {
            setSaveStatus('pending');
            return;
        }
        flushFaqsInFlightRef.current = true;
        setSaveStatus('saving');
        void (async () => {
            try {
                const snap = await replaceFaqSnapshot(articleId, faqsRef.current);
                const rows = itemsFromFaqSnapshot(snap);
                setFaqs(normalizeFaqRows(rows));
                clearFaqDraft(articleId);
                setSaveStatus('saved');
                window.dispatchEvent(new CustomEvent('article-faqs-save-finished'));
            } catch (error) {
                setSaveStatus('pending');
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('faq_saving'),
                            body: String(error?.code || error?.message || 'faq_snapshot_save_failed'),
                            status: 'danger',
                        },
                    }),
                );
            } finally {
                flushFaqsInFlightRef.current = false;
            }
        })();
    }, [articleId]);

    const { debounced: debouncedSave } = useDebouncedCallback((rows) => {
        if (!articleId) return;
        // Transient recovery only — canonical persist via FAQ snapshot API.
        saveFaqDraft(articleId, rows);
        flushFaqs();
    }, 1200);

    const persistRows = useCallback(
        (rows) => {
            setFaqs(rows);
            setSaveStatus('pending');
            debouncedSave(rows);
        },
        [debouncedSave],
    );

    const publishFaqsForLinks = useCallback(() => {
        const items = faqs
            .map((row, index) => ({
                text: String(row.question ?? '').trim(),
                index,
            }))
            .filter((item) => item.text !== '');

        window.dispatchEvent(
            new CustomEvent('seo-editor-faqs-updated', {
                detail: { faq: items },
            }),
        );
    }, [faqs]);

    useEffect(() => {
        publishFaqsForLinks();
    }, [publishFaqsForLinks]);

    useEffect(() => {
        const onFaqNavigate = () => {
            skipBlurDuplicateCheckRef.current = true;
            window.setTimeout(() => {
                skipBlurDuplicateCheckRef.current = false;
            }, 400);
        };

        window.addEventListener('seo-editor-faq-navigate', onFaqNavigate);

        return () => window.removeEventListener('seo-editor-faq-navigate', onFaqNavigate);
    }, []);

    const updateRow = useCallback(
        (index, patch) => {
            persistRows(
                applyLocalDuplicates(
                    faqs.map((row, i) => (i === index ? { ...row, ...patch } : row)),
                ),
            );
        },
        [faqs, persistRows],
    );

    const requestCrossDuplicateCheck = useCallback((index, question, faqId) => {
        if (!question.trim()) return;

        window.dispatchEvent(
            new CustomEvent('check-faq-question', {
                detail: {
                    index,
                    question,
                    faqId: faqId ?? null,
                    requestId: `${index}-${Date.now()}`,
                },
            }),
        );
    }, []);

    useEffect(() => {
        const onRenewed = (event) => {
            const { index, question, answer } = event.detail ?? {};
            if (typeof index !== 'number') return;

            setRenewingIndex(null);
            setFaqs((prev) => {
                const next = applyLocalDuplicates(
                    prev.map((row, i) =>
                        i === index
                            ? {
                                  ...row,
                                  question: question ?? row.question,
                                  answer: answer ?? row.answer,
                              }
                            : row,
                    ),
                );
                setSaveStatus('pending');
                debouncedSave(next);

                return next;
            });
        };

        const onDuplicateResult = (event) => {
            const { index, duplicate, duplicate_scope: scope } = event.detail ?? {};
            if (typeof index !== 'number') return;

            setFaqs((prev) =>
                applyLocalDuplicates(
                    prev.map((row, i) =>
                        i === index
                            ? {
                                  ...row,
                                  duplicate: Boolean(duplicate),
                                  duplicate_scope: scope === 'site' ? 'site' : null,
                              }
                            : row,
                    ),
                ),
            );
        };

        const onExtracted = (event) => {
            const incoming = Array.isArray(event.detail?.faqs) ? event.detail.faqs : null;
            if (incoming === null) {
                return;
            }

            if (incoming.length === 0 && !event.detail?.editorHtml) {
                return;
            }

            setExtractDebug(null);
            const next = normalizeFaqRows(incoming);
            setFaqs(next);
            saveFaqDraft(articleId, next);
            setSaveStatus('saved');
        };

        const onExtractDebug = (event) => {
            const payload = event.detail?.debug;
            if (payload && typeof payload === 'object') {
                setExtractDebug(payload);
            }
        };

        window.addEventListener('article-faq-renewed', onRenewed);
        window.addEventListener('faq-duplicate-checked', onDuplicateResult);
        window.addEventListener('article-faqs-extracted', onExtracted);
        const onExtractDebugCleared = () => {
            setExtractDebug(null);
        };

        window.addEventListener('article-faq-extract-debug', onExtractDebug);
        const onFaqsSaveFinished = () => {
            setSaveStatus('saved');
        };

        window.addEventListener('article-faq-extract-debug-cleared', onExtractDebugCleared);
        window.addEventListener('flush-article-faqs', flushFaqs);
        window.addEventListener('article-faqs-save-finished', onFaqsSaveFinished);
        const onGenerateStarted = () => setGeneratingAll(true);
        const onGenerateFinished = () => setGeneratingAll(false);

        window.addEventListener('article-faq-generate-started', onGenerateStarted);
        window.addEventListener('article-faq-generate-finished', onGenerateFinished);

        const onMarkdownImportFinished = (event) => {
            setImportingMarkdown(false);
            if (event?.detail?.success === true) {
                setMarkdownImportDraft('');
                setMarkdownImportOpen(false);
            }
        };

        window.addEventListener('article-faq-markdown-import-finished', onMarkdownImportFinished);

        return () => {
            window.removeEventListener('article-faq-renewed', onRenewed);
            window.removeEventListener('faq-duplicate-checked', onDuplicateResult);
            window.removeEventListener('article-faqs-extracted', onExtracted);
            window.removeEventListener('article-faq-extract-debug', onExtractDebug);
            window.removeEventListener('article-faq-extract-debug-cleared', onExtractDebugCleared);
            window.removeEventListener('flush-article-faqs', flushFaqs);
            window.removeEventListener('article-faqs-save-finished', onFaqsSaveFinished);
            window.removeEventListener('article-faq-generate-started', onGenerateStarted);
            window.removeEventListener('article-faq-generate-finished', onGenerateFinished);
            window.removeEventListener('article-faq-markdown-import-finished', onMarkdownImportFinished);
        };
    }, [articleId, debouncedSave, flushFaqs]);

    const importMarkdownFaq = () => {
        const markdown = String(markdownImportDraft ?? '').trim();
        if (!markdown || importingMarkdown) {
            return;
        }

        setImportingMarkdown(true);
        window.dispatchEvent(
            new CustomEvent('import-markdown-faq-debug', {
                detail: { markdown },
            }),
        );
    };

    const generateAllFaqs = () => {
        if (!canGenerateFaq || generatingAll) {
            return;
        }
        if (!canMutateEditor()) {
            return;
        }
        setGeneratingAll(true);
        void (async () => {
            try {
                const html = typeof window.__seoExportEditorHtml === 'function'
                    ? String(window.__seoExportEditorHtml() ?? '')
                    : '';
                const preview = await generateFaqPreview(articleId, html);
                const rows = normalizeFaqRows(preview?.faqs ?? []);
                setFaqs(rows);
                setAiPreviewPending(true);
                setSaveStatus('pending');
            } catch (error) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('faq_generate_failed') || 'FAQ AI',
                            body: String(error?.message || error?.code || 'faq_generation_failed'),
                            status: 'danger',
                        },
                    }),
                );
            } finally {
                setGeneratingAll(false);
            }
        })();
    };

    const applyAiFaqPreview = () => {
        if (!aiPreviewPending || !articleId) {
            return;
        }
        if (!canMutateEditor()) {
            return;
        }
        setGeneratingAll(true);
        void (async () => {
            try {
                const html = typeof window.__seoExportEditorHtml === 'function'
                    ? String(window.__seoExportEditorHtml() ?? '')
                    : '';
                const result = await applyFaqSnapshot(articleId, faqsRef.current, html);
                if (result?.faq_snapshot) {
                    setFaqs(normalizeFaqRows(itemsFromFaqSnapshot(result.faq_snapshot)));
                }
                if (result?.editor_html) {
                    window.dispatchEvent(
                        new CustomEvent('article-faqs-extracted', {
                            detail: {
                                faqs: faqsRef.current,
                                editorHtml: result.editor_html,
                            },
                        }),
                    );
                }
                clearFaqDraft(articleId);
                setAiPreviewPending(false);
                setSaveStatus('saved');
            } catch (error) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('faq_saving'),
                            body: String(error?.code || error?.message || 'faq_apply_failed'),
                            status: 'danger',
                        },
                    }),
                );
            } finally {
                setGeneratingAll(false);
            }
        })();
    };

    const addFaq = () => {
        persistRows([...faqs, newFaqRow(faqs.length + 1)]);
    };

    const removeFaq = (index) => {
        persistRows(applyLocalDuplicates(faqs.filter((_, i) => i !== index)));
    };

    const renewFaq = (index) => {
        const row = faqs[index];
        if (!row) return;

        setRenewingIndex(index);
        window.dispatchEvent(
            new CustomEvent('renew-article-faq', {
                detail: {
                    index,
                    question: row.question,
                    answer: row.answer,
                },
            }),
        );
    };

    const duplicateHint = useMemo(
        () => ({
            article: t('faq_duplicate_in_article'),
            site: t('faq_duplicate_in_site'),
        }),
        [],
    );

    const saveLabel =
        saveStatus === 'saving'
            ? t('faq_saving')
            : saveStatus === 'pending'
              ? t('faq_pending')
              : t('faq_saved');

    return (
        <div className="seo-article-faq-panel wp-postbox">
            <div className="wp-postbox-header">
                <h2>FAQ</h2>
                <div className="flex items-center gap-2 flex-wrap justify-end">
                    <span className="text-xs text-gray-500">{saveLabel}</span>
                    <button
                        type="button"
                        className="seo-faq-btn-extract"
                        disabled={!hasEditorSelection}
                        onClick={extractFaqFromSelection}
                        title={
                            hasEditorSelection
                                ? t('toolbar_extract_faq')
                                : t('faq_extract_need_selection')
                        }
                    >
                        <ListTree size={14} />
                        {t('faq_extract')}
                    </button>
                    {canGenerateFaq ? (
                        <button
                            type="button"
                            className="seo-faq-btn-generate"
                            disabled={generatingAll}
                            onClick={generateAllFaqs}
                            title={t('faq_generate_ai')}
                        >
                            <Sparkles size={14} className={generatingAll ? 'animate-pulse' : ''} />
                            {generatingAll ? t('faq_generate_ai_loading') : t('faq_generate_ai')}
                        </button>
                    ) : null}
                    {aiPreviewPending ? (
                        <button
                            type="button"
                            className="seo-faq-btn-generate"
                            disabled={generatingAll}
                            onClick={applyAiFaqPreview}
                            title={t('faq_apply_ai_preview') || 'Apply AI FAQ'}
                        >
                            {t('faq_apply_ai_preview') || 'Apply AI FAQ'}
                        </button>
                    ) : null}
                    {canImportMarkdownFaq ? (
                        <button
                            type="button"
                            className="seo-faq-btn-import-md"
                            disabled={importingMarkdown}
                            onClick={() => setMarkdownImportOpen((open) => !open)}
                            title={t('faq_import_markdown_debug')}
                        >
                            <FileCode size={14} />
                            {importingMarkdown ? t('faq_import_markdown_loading') : t('faq_import_markdown_debug')}
                        </button>
                    ) : null}
                    <button type="button" className="seo-faq-btn-add" onClick={addFaq}>
                        <Plus size={14} />
                        {t('faq_add_question')}
                    </button>
                </div>
            </div>
            <div className="wp-postbox-inside space-y-4">
                {canImportMarkdownFaq && markdownImportOpen ? (
                    <div className="seo-faq-markdown-import">
                        <p className="seo-faq-markdown-import__hint">{t('faq_import_markdown_hint')}</p>
                        <textarea
                            className="seo-faq-markdown-import__textarea"
                            rows={8}
                            value={markdownImportDraft}
                            onChange={(event) => setMarkdownImportDraft(event.target.value)}
                            placeholder={t('faq_import_markdown_placeholder')}
                        />
                        <div className="seo-faq-markdown-import__actions">
                            <button
                                type="button"
                                className="seo-faq-btn-import-md is-primary"
                                disabled={importingMarkdown || markdownImportDraft.trim() === ''}
                                onClick={importMarkdownFaq}
                            >
                                {importingMarkdown ? t('faq_import_markdown_loading') : t('faq_import_markdown_submit')}
                            </button>
                            <button
                                type="button"
                                className="seo-faq-btn-import-md"
                                disabled={importingMarkdown}
                                onClick={() => setMarkdownImportOpen(false)}
                            >
                                {t('cancel')}
                            </button>
                        </div>
                    </div>
                ) : null}
                <FaqExtractDebugBanner
                    debug={extractDebug}
                    onDismiss={() => setExtractDebug(null)}
                    onFixed={() => {
                        setExtractDebug(null);
                        document.getElementById('seo-faq-debug-dismiss-wire')?.click();
                    }}
                />
                {faqs.length === 0 ? (
                    <p className="text-sm text-gray-500 italic">
                        {t('faq_empty_hint')}
                    </p>
                ) : (
                    faqs.map((row, index) => (
                        <div
                            key={row.id ?? `new-${index}`}
                            data-seo-faq-index={index}
                            className={`seo-faq-item ${row.duplicate ? 'is-duplicate' : ''}`}
                        >
                            <div className="seo-faq-item__head">
                                <label className="seo-faq-label">{t('faq_question')}</label>
                                <div className="seo-faq-item__actions">
                                    <button
                                        type="button"
                                        className="seo-faq-btn-icon"
                                        title={t('faq_renew_ai')}
                                        disabled={renewingIndex === index}
                                        onClick={() => renewFaq(index)}
                                    >
                                        <RefreshCw
                                            size={16}
                                            className={renewingIndex === index ? 'animate-spin' : ''}
                                        />
                                    </button>
                                    <button
                                        type="button"
                                        className="seo-faq-btn-icon text-red-600"
                                        title={t('faq_delete')}
                                        onClick={() => removeFaq(index)}
                                    >
                                        <Trash2 size={16} />
                                    </button>
                                </div>
                            </div>
                            <input
                                type="text"
                                className={`seo-faq-question-input ${row.duplicate ? 'is-duplicate' : ''}`}
                                value={row.question}
                                placeholder={t('faq_question_placeholder')}
                                maxLength={500}
                                onChange={(e) => updateRow(index, { question: e.target.value })}
                                onBlur={(e) => {
                                    if (skipBlurDuplicateCheckRef.current) {
                                        return;
                                    }
                                    requestCrossDuplicateCheck(index, e.target.value, row.id);
                                }}
                            />
                            {row.duplicate ? (
                                <p className="seo-faq-duplicate-msg">
                                    <AlertCircle size={14} />
                                    {duplicateHint[row.duplicate_scope] || t('faq_duplicate_generic')}
                                </p>
                            ) : null}

                            <label className="seo-faq-label mt-3 block">{t('faq_answer')}</label>
                            <FaqAnswerEditor
                                key={row.id ?? `faq-answer-${index}`}
                                html={row.answer}
                                onChange={(html) => updateRow(index, { answer: html })}
                            />
                        </div>
                    ))
                )}
            </div>
        </div>
    );
}
