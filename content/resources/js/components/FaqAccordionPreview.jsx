import React from 'react';
import { t } from '../utils/i18n';

/**
 * Compact FAQ shortcode surface — count + Edit/Create. Full rows stay lazy in FAQ module.
 *
 * @param {{
 *   faqs?: Array<{ question?: string, answer?: string, more?: string, id?: number }>,
 *   faqCount?: number|null,
 *   canGenerateFaq?: boolean,
 *   onEditFaq?: () => void,
 *   onCreateFaq?: () => void,
 *   showHint?: boolean,
 * }} props
 */
export default function FaqAccordionPreview({
    faqs = [],
    faqCount = null,
    canGenerateFaq = false,
    onEditFaq,
    onCreateFaq,
    showHint = true,
}) {
    const rows = (faqs ?? []).filter((row) => String(row?.answer ?? '').trim() !== '');
    const countFromRows = rows.length;
    const resolvedCount = Number.isFinite(Number(faqCount)) && Number(faqCount) > 0
        ? Number(faqCount)
        : countFromRows;
    const hasFaq = resolvedCount > 0 || countFromRows > 0;

    return (
        <div className="omi-faq-editor-preview omi-faq-editor-preview--compact" data-omi-faq="1">
            <div className="omi-faq-placeholder omi-faq-shortcode-card">
                <div className="omi-faq-shortcode-card__title">{t('faq_shortcode_title')}</div>
                <div className="omi-faq-shortcode-card__body">
                    {hasFaq
                        ? t('faq_shortcode_count', { count: resolvedCount })
                        : t('faq_shortcode_empty')}
                </div>
                <div className="omi-faq-shortcode-card__actions">
                    {hasFaq ? (
                        <button
                            type="button"
                            className="omi-faq-shortcode-card__btn"
                            onClick={(event) => {
                                event.stopPropagation();
                                onEditFaq?.();
                            }}
                        >
                            {t('faq_shortcode_edit')}
                        </button>
                    ) : (
                        <button
                            type="button"
                            className="omi-faq-shortcode-card__btn"
                            disabled={!canGenerateFaq && !onCreateFaq}
                            onClick={(event) => {
                                event.stopPropagation();
                                onCreateFaq?.();
                            }}
                        >
                            {t('faq_shortcode_create')}
                        </button>
                    )}
                </div>
            </div>
            {showHint && hasFaq && countFromRows > 0 ? (
                <p className="omi-faq-editor-preview__hint">
                    Shortcode [omi_faq] — {t('faq_shortcode_count', { count: countFromRows })}
                </p>
            ) : null}
        </div>
    );
}
