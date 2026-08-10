import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Loader2, Sparkles, X } from 'lucide-react';
import { t } from '@content-addon/utils/i18n.js';

/**
 * Featured snippet prompt flow — collect context on open, generate on demand,
 * preview, then user confirms insert. No silent auto-insert.
 */
export default function FeaturedSnippetPromptModal({
    open = false,
    canGenerate = false,
    generating = false,
    previewHtml = '',
    context = null,
    onClose,
    onGenerate,
    onConfirmInsert,
}) {
    const [localError, setLocalError] = useState(null);

    useEffect(() => {
        if (!open) {
            setLocalError(null);
        }
    }, [open]);

    const outlinePreview = useMemo(() => {
        const raw = String(context?.outline ?? '').trim();
        if (raw === '') {
            return t('featured_snippet_prompt_no_outline');
        }
        return raw.length > 400 ? `${raw.slice(0, 400)}…` : raw;
    }, [context?.outline]);

    const handleGenerate = useCallback(() => {
        setLocalError(null);
        if (!canGenerate) {
            setLocalError(t('editor_featured_snippet_no_prompt'));
            return;
        }
        onGenerate?.();
    }, [canGenerate, onGenerate]);

    if (!open) {
        return null;
    }

    return (
        <div className="seo-fs-prompt-modal" role="dialog" aria-modal="true" aria-labelledby="seo-fs-prompt-title">
            <div className="seo-fs-prompt-modal__backdrop" onClick={onClose} />
            <div className="seo-fs-prompt-modal__panel">
                <div className="seo-fs-prompt-modal__head">
                    <h2 id="seo-fs-prompt-title">{t('featured_snippet_prompt_title')}</h2>
                    <button type="button" className="seo-fs-prompt-modal__close" onClick={onClose} aria-label={t('close')}>
                        <X size={16} />
                    </button>
                </div>

                <div className="seo-fs-prompt-modal__body space-y-3 text-sm">
                    <p className="text-gray-600 dark:text-gray-300">{t('featured_snippet_prompt_hint')}</p>
                    <dl className="seo-fs-prompt-modal__meta grid gap-2">
                        <div>
                            <dt className="text-xs text-gray-500">{t('featured_snippet_prompt_title_label')}</dt>
                            <dd>{String(context?.title ?? '').trim() || '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-gray-500">{t('featured_snippet_prompt_keyword_label')}</dt>
                            <dd>{String(context?.focusKeyword ?? '').trim() || '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-gray-500">{t('featured_snippet_prompt_outline_label')}</dt>
                            <dd className="whitespace-pre-wrap">{outlinePreview}</dd>
                        </div>
                    </dl>

                    {localError ? (
                        <p className="text-rose-600 dark:text-rose-400">{localError}</p>
                    ) : null}

                    {previewHtml ? (
                        <div className="seo-fs-prompt-modal__preview rounded border border-gray-200 p-3 dark:border-white/15">
                            <p className="mb-2 text-xs font-medium text-gray-500">{t('featured_snippet_prompt_preview')}</p>
                            <div
                                className="prose prose-sm max-w-none dark:prose-invert"
                                dangerouslySetInnerHTML={{ __html: previewHtml }}
                            />
                        </div>
                    ) : null}
                </div>

                <div className="seo-fs-prompt-modal__actions flex flex-wrap justify-end gap-2">
                    <button type="button" className="rounded px-3 py-1.5 text-sm" onClick={onClose}>
                        {t('cancel')}
                    </button>
                    <button
                        type="button"
                        className="inline-flex items-center gap-1.5 rounded bg-primary-600 px-3 py-1.5 text-sm text-white disabled:opacity-50"
                        disabled={generating || !canGenerate}
                        onClick={handleGenerate}
                    >
                        {generating ? <Loader2 size={14} className="animate-spin" /> : <Sparkles size={14} />}
                        {generating ? t('editor_featured_snippet_generating') : t('featured_snippet_prompt_run')}
                    </button>
                    <button
                        type="button"
                        className="rounded bg-emerald-600 px-3 py-1.5 text-sm text-white disabled:opacity-50"
                        disabled={!previewHtml || generating}
                        onClick={onConfirmInsert}
                    >
                        {t('featured_snippet_prompt_insert')}
                    </button>
                </div>
            </div>
        </div>
    );
}
