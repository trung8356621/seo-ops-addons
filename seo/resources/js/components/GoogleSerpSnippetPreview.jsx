import React from 'react';
import { t } from '@content-addon/utils/i18n.js';

export function scoreTone(score) {
    if (score >= 80) {
        return 'is-good';
    }

    if (score >= 50) {
        return 'is-warn';
    }

    return 'is-bad';
}

function SerpScoreBadge({ score, device = 'desktop', className = '' }) {
    if (score == null || Number.isNaN(Number(score))) {
        return null;
    }

    const rounded = Math.round(Number(score));
    const label = t('google_serp_score_label', { score: rounded });

    return (
        <span
            className={`google-serp-score-badge google-serp-score-badge--${device} ${scoreTone(Number(score))} ${className}`.trim()}
            aria-label={label}
            title={label}
        />
    );
}

function StarRow({ ratingValue }) {
    const rating = Number(ratingValue ?? 0);

    return (
        <span className="wp-seo-snippet__stars" aria-hidden="true">
            {Array.from({ length: 5 }, (_, index) => {
                const filled = rating >= index + 1 - 0.25;

                return (
                    <svg
                        key={index}
                        className={`wp-seo-snippet__star${filled ? ' is-filled' : ''}`}
                        viewBox="0 0 20 20"
                        fill="currentColor"
                        aria-hidden="true"
                    >
                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                    </svg>
                );
            })}
        </span>
    );
}

function SerpPreviewLine({ score, device, lineType, children, className = '' }) {
    return (
        <div className={`google-serp-snippet-line google-serp-snippet-line--${lineType} google-serp-snippet-line--${device} ${className}`.trim()}>
            <SerpScoreBadge score={score} device={device} />
            <div className="google-serp-snippet-line__content">{children}</div>
        </div>
    );
}

export default function GoogleSerpSnippetPreview({
    device = 'desktop',
    title = '',
    url = '',
    description = '',
    lineScores = null,
    showScore = true,
    previewMeta = null,
    previewType = 'article',
    onClick = null,
    clickable = false,
    variant = 'card',
}) {
    const meta = previewMeta && typeof previewMeta === 'object' ? previewMeta : {};
    const isProduct = String(previewType ?? '') === 'product';
    const ratingValue = meta.rating_value ?? null;
    const reviewCount = meta.review_count ?? null;
    const priceDisplay = String(meta.price ?? '').trim();
    const availabilityLabel = String(meta.availability_label ?? '').trim();
    const scores = showScore && lineScores && typeof lineScores === 'object' ? lineScores : null;
    const titleScore = scores?.title ?? null;
    const urlScore = scores?.slug ?? null;
    const descScore = scores?.description ?? null;

    const titleText = String(title ?? '').trim() !== '' ? String(title).trim() : t('google_serp_title_placeholder');
    const urlText = String(url ?? '').trim() !== '' ? String(url).trim() : 'www.example.com';
    const descText = String(description ?? '').trim() !== '' ? String(description).trim() : t('google_serp_desc_placeholder');

    const snippetClassName = [
        'google-serp-snippet',
        `google-serp-snippet--${device}`,
        `google-serp-snippet--${variant}`,
        clickable ? 'google-serp-snippet--clickable' : '',
    ]
        .filter(Boolean)
        .join(' ');

    const Wrapper = clickable ? 'button' : 'div';
    const wrapperProps = clickable
        ? {
              type: 'button',
              onClick,
              title: t('google_serp_edit_fields'),
              'aria-label': t('google_serp_edit_fields'),
          }
        : {};

    return (
        <Wrapper className={snippetClassName} {...wrapperProps}>
            <SerpPreviewLine score={titleScore} device={device} lineType="title">
                <p className="google-serp-snippet__title line-clamp-1">{titleText}</p>
            </SerpPreviewLine>

            <SerpPreviewLine score={urlScore} device={device} lineType="url">
                <p className="google-serp-snippet__url line-clamp-1">{urlText}</p>
            </SerpPreviewLine>

            {isProduct && (ratingValue !== null || priceDisplay !== '' || availabilityLabel !== '') ? (
                <div className="google-serp-snippet__rich">
                    {ratingValue !== null ? (
                        <>
                            <StarRow ratingValue={ratingValue} />
                            {reviewCount !== null && Number(reviewCount) > 0 ? (
                                <span className="wp-seo-snippet__reviews">
                                    {Number(reviewCount).toLocaleString()} reviews
                                </span>
                            ) : null}
                        </>
                    ) : null}
                    {priceDisplay !== '' ? (
                        <span className="wp-seo-snippet__price">{priceDisplay}</span>
                    ) : null}
                    {availabilityLabel !== '' ? (
                        <span className="wp-seo-snippet__availability">· {availabilityLabel}</span>
                    ) : null}
                </div>
            ) : null}

            <SerpPreviewLine score={descScore} device={device} lineType="desc">
                <p className="google-serp-snippet__desc line-clamp-2">{descText}</p>
            </SerpPreviewLine>
        </Wrapper>
    );
}
