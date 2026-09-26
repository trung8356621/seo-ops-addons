import React, { useCallback, useEffect, useState } from 'react';
import { Plus, RefreshCw, Star } from 'lucide-react';
import { fetchProductReviewStatus } from '../utils/articleEditorApi';
import { normalizeReviewStatus } from '../utils/articleEditorPayloadAdapters';
import { t } from '../utils/i18n';

function normalizeReviewsPayload(result) {
    if (Array.isArray(result)) {
        return result;
    }

    if (result && typeof result === 'object') {
        if (Array.isArray(result.reviews)) {
            return result.reviews;
        }

        if (Array.isArray(result.params?.reviews)) {
            return result.params.reviews;
        }
    }

    return null;
}

function formatReviewDate(raw) {
    const value = String(raw ?? '').trim();
    if (!value) {
        return '';
    }

    const parsed = new Date(value.replace(' ', 'T'));
    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    return parsed.toLocaleString();
}

function formatClock(raw) {
    const value = String(raw ?? '').trim();
    if (!value) {
        return '';
    }
    const parsed = new Date(value.replace(' ', 'T'));
    if (Number.isNaN(parsed.getTime())) {
        return value;
    }
    return parsed.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function statusPresentation(review) {
    const status = String(review?.status ?? '');
    const scheduledAt = review?.scheduled_at;
    const nextRetryAt = review?.next_retry_at;
    const errorCode = String(review?.last_error_code ?? '');

    switch (status) {
        case 'pending':
            return { label: t('reviews_status_pending_sync'), hint: null };
        case 'syncing':
            return { label: t('reviews_status_syncing_wordpress'), hint: null };
        case 'reviewed':
            return { label: t('reviews_status_synced'), hint: null };
        case 'draft':
            return { label: t('reviews_status_local_draft'), hint: null };
        case 'pending_article':
            return { label: t('reviews_status_pending_article_sync'), hint: null };
        case 'pending_publish':
            return { label: t('reviews_status_pending_automation'), hint: null };
        case 'scheduled': {
            const clock = formatClock(scheduledAt);
            if (clock) {
                return { label: t('reviews_status_scheduled_at', { clock }), hint: null };
            }
            return { label: t('reviews_status_scheduled'), hint: null };
        }
        case 'publishing':
            return { label: t('reviews_status_publishing'), hint: null };
        case 'published':
            return { label: t('reviews_status_published'), hint: null };
        case 'failed_dispatch':
            return {
                label: t('reviews_status_scheduling_failed'),
                hint: errorCode || review?.last_error_message || null,
            };
        case 'failed': {
            if (nextRetryAt) {
                const clock = formatClock(nextRetryAt);
                return {
                    label: t('reviews_status_retry_pending'),
                    hint: clock ? t('reviews_status_retry_around', { clock }) : t('reviews_status_retry_scheduled'),
                };
            }
            return { label: t('failed'), hint: review?.last_error_message || null };
        }
        case 'cancelled':
            return { label: t('reviews_status_cancelled'), hint: null };
        default:
            return { label: status ? String(status) : '', hint: null };
    }
}

function StarRating({ rating }) {
    const value = Number(rating);
    if (!Number.isFinite(value) || value <= 0) {
        return null;
    }

    return (
        <span className="seo-reviews-tab__stars" aria-label={`${value} / 5`}>
            {Array.from({ length: 5 }, (_, index) => (
                <Star
                    key={index}
                    size={14}
                    className={index < Math.round(value) ? 'is-filled' : ''}
                    fill={index < Math.round(value) ? 'currentColor' : 'none'}
                />
            ))}
        </span>
    );
}

/**
 * @param {{
 *   initialReviews?: Array,
 *   onRefresh?: () => Promise<Array|void>,
 *   canQuickCreate?: boolean,
 *   showConfigureReviews?: boolean,
 *   quickCreateConfigUrl?: string,
 *   onQuickCreate?: () => Promise<Array|void>,
 * }} props
 */
export default function ArticleReviewsTab({
    articleId = null,
    initialReviews = [],
    onRefresh,
    loading = false,
    loaded = false,
    count = null,
    countLoading = false,
    warning = null,
    canQuickCreate = false,
    showConfigureReviews = false,
    quickCreateConfigUrl = '',
    onQuickCreate,
}) {
    const [reviews, setReviews] = useState(() => (
        Array.isArray(initialReviews) ? initialReviews : []
    ));
    const [refreshing, setRefreshing] = useState(false);
    const [quickCreating, setQuickCreating] = useState(false);
    const [status, setStatus] = useState(null);
    const [statusLoading, setStatusLoading] = useState(false);

    const blockReasonLabel = (reason) => {
        switch (String(reason || '')) {
            case 'wordpress_real_reviews_exist':
                return t('reviews_block_wordpress_real_exists');
            case 'target_count_reached':
                return t('reviews_block_target_reached');
            case 'local_pending_reviews_exist':
                return t('reviews_block_local_pending_exists');
            case 'wordpress_unavailable':
                return t('reviews_block_wordpress_unavailable');
            case 'not_product':
                return t('reviews_block_not_product');
            case 'feature_disabled':
                return t('reviews_block_feature_disabled');
            default:
                return reason ? String(reason) : null;
        }
    };

    const loadStatus = useCallback(async () => {
        const id = Number(articleId) || 0;
        if (id <= 0) {
            return null;
        }
        setStatusLoading(true);
        try {
            const result = await fetchProductReviewStatus(id);
            if (result.success && result.data) {
                const normalized = normalizeReviewStatus(result.data);
                setStatus({
                    ...result.data,
                    ...normalized,
                    can_create_reviews: normalized.canCreateReviews,
                    create_block_reason: normalized.createBlockReason,
                    warning: normalized.warning,
                });
                return result.data;
            }
            if (!result.success) {
                setStatus((prev) => ({
                    ...(prev && typeof prev === 'object' ? prev : {}),
                    applicable: false,
                    status: null,
                    count: 0,
                    warning: String(result.message ?? t('reviews_status_load_failed')),
                }));
            }
            return null;
        } catch (error) {
            setStatus((prev) => ({
                ...(prev && typeof prev === 'object' ? prev : {}),
                applicable: false,
                status: null,
                count: 0,
                warning: String(error?.message ?? t('reviews_status_load_failed')),
            }));
            return null;
        } finally {
            setStatusLoading(false);
        }
    }, [articleId]);

    // Status fetch only while Reviews module is mounted (parent gates by activeHeavyModule).
    useEffect(() => {
        loadStatus();
    }, [loadStatus]);

    const applyReviewsPayload = useCallback((result) => {
        const next = normalizeReviewsPayload(result);
        if (Array.isArray(next)) {
            setReviews(next);
        }
    }, []);

    useEffect(() => {
        const onSyncLock = (event) => {
            const detail = event?.detail ?? {};
            if (detail.status !== 'success') {
                return;
            }
            void loadStatus();
            if (typeof onRefresh === 'function') {
                void onRefresh().then((result) => applyReviewsPayload(result));
            }
        };

        window.addEventListener('article-wordpress-sync-lock', onSyncLock);

        return () => window.removeEventListener('article-wordpress-sync-lock', onSyncLock);
    }, [applyReviewsPayload, loadStatus, onRefresh]);

    useEffect(() => {
        setReviews(Array.isArray(initialReviews) ? initialReviews : []);
    }, [initialReviews]);

    useEffect(() => {
        const handler = (event) => {
            const detail = event?.detail ?? {};
            applyReviewsPayload(detail.reviews ?? detail.params?.reviews ?? detail);
        };
        window.addEventListener('virtual-reviews-updated', handler);
        return () => window.removeEventListener('virtual-reviews-updated', handler);
    }, [applyReviewsPayload]);

    const handleRefresh = useCallback(async () => {
        if (refreshing) {
            return;
        }
        setRefreshing(true);
        try {
            if (typeof onRefresh === 'function') {
                const result = await onRefresh();
                applyReviewsPayload(result);
            }
            await loadStatus();
        } finally {
            setRefreshing(false);
        }
    }, [applyReviewsPayload, loadStatus, onRefresh, refreshing]);

    const handleQuickCreate = useCallback(async () => {
        if (typeof onQuickCreate !== 'function' || quickCreating) {
            return;
        }
        setQuickCreating(true);
        try {
            const result = await onQuickCreate();
            applyReviewsPayload(result);
            await loadStatus();
        } finally {
            setQuickCreating(false);
        }
    }, [applyReviewsPayload, loadStatus, onQuickCreate, quickCreating]);

    return (
        <div className="seo-reviews-tab">
            {loading || statusLoading ? (
                <p className="seo-reviews-tab__summary" role="status">
                    {t('reviews_status_loading_wordpress')}
                </p>
            ) : null}
            {!loading && !loaded ? (
                <p className="seo-reviews-tab__summary" role="status">
                    {warning || t('reviews_status_not_loaded_retry')}
                </p>
            ) : null}
            {warning || status?.warning ? (
                <p className="seo-reviews-tab__summary" role="alert" style={{ color: '#b45309' }}>
                    {warning || status?.warning}
                </p>
            ) : null}
            {status ? (
                <div className="seo-reviews-tab__summary" style={{ marginBottom: 12 }}>
                    <div><strong>{t('reviews_summary_title')}</strong></div>
                    <div style={{ opacity: 0.85, marginBottom: 8 }}>
                        {t('reviews_summary_hint')}
                    </div>
                    <div>{t('reviews_summary_real_count', { count: Number(status.wordpress_real_review_count ?? 0) })}</div>
                    <div>
                        {t('reviews_summary_generated')}
                        {' '}
                        {Number(
                            status.generated_count
                            ?? status.local_generated_count
                            ?? status.wordpress_generated_review_count
                            ?? 0,
                        )}
                        {Number(status.local_generated_count ?? 0) > 0
                            || Number(status.wordpress_generated_review_count ?? 0) > 0
                            ? (
                                <span style={{ opacity: 0.75 }}>
                                    {' '}
                                    ({t('reviews_summary_wp_short')}:
                                    {' '}
                                    {Number(status.wordpress_generated_review_count ?? 0)}
                                    {' '}
                                    · {t('reviews_summary_local_short')}:
                                    {' '}
                                    {Number(status.local_generated_count ?? 0)}
                                    )
                                </span>
                            )
                            : null}
                    </div>
                    <div>{t('reviews_summary_target_count', { count: Number(status.target_count ?? 0) })}</div>
                    <div>{t('reviews_summary_missing_count', { count: Number(status.missing_count ?? 0) })}</div>
                    <div>
                        {t('reviews_summary_pending_laravel')}
                        {' '}
                        {Number(status.syncable_pending_count ?? status.local_pending_count ?? 0)}
                    </div>
                    <div>{t('reviews_summary_reviewed_laravel', { count: Number(status.local_reviewed_count ?? 0) })}</div>
                    {!status.can_create_reviews && status.create_block_reason ? (
                        <div style={{ color: '#b45309', marginTop: 6 }}>
                            {blockReasonLabel(status.create_block_reason)}
                        </div>
                    ) : null}
                    <div className="seo-reviews-tab__actions" style={{ marginTop: 8, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                        <button type="button" className="seo-reviews-tab__refresh" disabled={refreshing} onClick={handleRefresh}>
                            {t('reviews_status_refresh')}
                        </button>
                    </div>
                </div>
            ) : null}
            <div className="seo-reviews-tab__header">
                <p className="seo-reviews-tab__summary">
                    {t('reviews_tab_summary', {
                        count: loaded ? reviews.length : (count ?? '…'),
                    })}
                </p>
                <div className="seo-reviews-tab__actions">
                    {canQuickCreate && typeof onQuickCreate === 'function' ? (
                        <button
                            type="button"
                            className="seo-reviews-tab__quick-create"
                            disabled={quickCreating || reviews.length > 0}
                            onClick={handleQuickCreate}
                        >
                            <Plus size={14} />
                            {t('reviews_tab_quick_create')}
                        </button>
                    ) : null}
                    {showConfigureReviews && quickCreateConfigUrl ? (
                        <a className="seo-reviews-tab__configure" href={quickCreateConfigUrl}>
                            {t('reviews_tab_configure')}
                        </a>
                    ) : null}
                    {typeof onRefresh === 'function' ? (
                        <button
                            type="button"
                            className="seo-reviews-tab__refresh"
                            disabled={refreshing}
                            onClick={handleRefresh}
                        >
                            <RefreshCw size={14} className={refreshing ? 'is-spinning' : ''} />
                            {t('reviews_tab_refresh')}
                        </button>
                    ) : null}
                </div>
            </div>

            {loaded && reviews.length === 0 ? (
                <p className="seo-reviews-tab__empty">{t('reviews_tab_empty')}</p>
            ) : reviews.length > 0 ? (
                <ul className="seo-reviews-tab__list">
                    {reviews.map((review, index) => {
                        const author = String(review?.author ?? '').trim() || t('reviews_tab_guest');
                        const content = String(review?.content ?? '').trim();
                        const dateLabel = formatReviewDate(review?.date);
                        const status = String(review?.status ?? '');
                        const reviewId = Number(review?.id ?? 0);
                        const presentation = statusPresentation(review);

                        return (
                            <li key={reviewId || `${author}-${index}-${dateLabel}`} className="seo-reviews-tab__item">
                                <div className="seo-reviews-tab__item-head">
                                    <strong className="seo-reviews-tab__author">{author}</strong>
                                    <StarRating rating={review?.rating} />
                                    {presentation.label ? (
                                        <span className={`seo-reviews-tab__status is-${status || 'unknown'}`}>
                                            {presentation.label}
                                        </span>
                                    ) : null}
                                    {dateLabel ? (
                                        <time className="seo-reviews-tab__date" dateTime={String(review?.date ?? '')}>
                                            {dateLabel}
                                        </time>
                                    ) : null}
                                </div>
                                {presentation.hint ? (
                                    <p className="seo-reviews-tab__hint">{presentation.hint}</p>
                                ) : null}
                                <p className="seo-reviews-tab__content">{content}</p>
                                {status === 'published' || status === 'reviewed' ? (
                                    <p className="seo-reviews-tab__meta">
                                        {t('reviews_meta_wp_comment_id')}: {String(review?.wp_comment_id ?? '—')}
                                        {review?.published_at ? ` · ${formatReviewDate(review.published_at)}` : ''}
                                        {review?.synced_at ? ` · ${t('reviews_meta_synced')} ${formatReviewDate(review.synced_at)}` : ''}
                                    </p>
                                ) : null}
                                {status === 'failed' && review?.last_error_message ? (
                                    <p className="seo-reviews-tab__error">{String(review.last_error_message)}</p>
                                ) : null}
                            </li>
                        );
                    })}
                </ul>
            ) : null}
        </div>
    );
}
