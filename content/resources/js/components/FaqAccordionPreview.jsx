import React from 'react';
import { t } from '../utils/i18n';
import { useFaqGenerationState } from '../utils/faqGenerationCommand';
import {
    getFaqGenerationCreateDisabledReason,
    isFaqGenerationCreateDisabled,
} from '../utils/faqGenerationCommandStore';

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
    const generation = useFaqGenerationState();
    const generationBusy = generation.phase === 'opening' || generation.phase === 'generating' || generation.phase === 'applying';
    const isPreviewPhase = generation.phase === 'preview';
    const createDisabled = isFaqGenerationCreateDisabled({
        phase: generation.phase,
        canGenerateFaq,
        onCreateFaq,
    });
    const disabledReason = createDisabled
        ? getFaqGenerationCreateDisabledReason({
            phase: generation.phase,
            canGenerateFaq,
            onCreateFaq,
            t,
        })
        : '';

    let cardBody = t('faq_shortcode_empty');
    if (hasFaq) {
        cardBody = t('faq_shortcode_count', { count: resolvedCount });
    } else if (isPreviewPhase) {
        cardBody = t('faq_shortcode_preview_pending');
    } else if (generationBusy) {
        cardBody = t('faq_generate_ai_loading');
    }

    return (
        <div className="omi-faq-editor-preview omi-faq-editor-preview--compact" data-omi-faq="1">
            <div className="omi-faq-placeholder omi-faq-shortcode-card">
                <div className="omi-faq-shortcode-card__title">{t('faq_shortcode_title')}</div>
                <div className="omi-faq-shortcode-card__body">{cardBody}</div>
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
                    ) : isPreviewPhase ? (
                        <button
                            type="button"
                            className="omi-faq-shortcode-card__btn"
                            onClick={(event) => {
                                event.stopPropagation();
                                onEditFaq?.();
                            }}
                        >
                            {t('faq_shortcode_view_preview')}
                        </button>
                    ) : (
                        <button
                            type="button"
                            className="omi-faq-shortcode-card__btn"
                            disabled={generationBusy || createDisabled}
                            title={disabledReason || undefined}
                            onClick={(event) => {
                                event.stopPropagation();
                                if (!generationBusy && canGenerateFaq) onCreateFaq?.();
                            }}
                        >
                            {generationBusy ? t('faq_generate_ai_loading') : t('faq_shortcode_create')}
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
