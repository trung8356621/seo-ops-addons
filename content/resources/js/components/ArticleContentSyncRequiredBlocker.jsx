import React from 'react';
import { Loader2, RefreshCw } from 'lucide-react';
import { t } from '../utils/i18n';

/**
 * WP content auto-load panel — loading while fetching cache-miss HTML,
 * error+retry only when the WP JSON fetch actually fails.
 */
export default function ArticleContentSyncRequiredBlocker({
    status = 'loading',
    allowFetch = true,
    observedPermalink = null,
    onRetry = null,
}) {
    const isError = status === 'error';

    return (
        <div
            className="seo-content-sync-required"
            data-seo-content-lifecycle={isError ? 'ERROR' : 'CONTENT_LOADING'}
            role="status"
        >
            <div className="seo-content-sync-required__card">
                <h3 className="seo-content-sync-required__title">
                    {isError
                        ? t('content_wp_load_failed')
                        : t('content_wp_loading')}
                </h3>
                {observedPermalink ? (
                    <p className="seo-content-sync-required__permalink">
                        <span>{t('content_sync_required_wp_path')}</span>
                        {' '}
                        <a href={observedPermalink} target="_blank" rel="noopener noreferrer">
                            {observedPermalink}
                        </a>
                    </p>
                ) : null}
                {isError ? (
                    <div className="seo-content-sync-required__actions">
                        <button
                            type="button"
                            className="seo-content-sync-required__btn"
                            disabled={!allowFetch}
                            onClick={() => {
                                if (typeof onRetry === 'function') {
                                    onRetry();
                                }
                            }}
                        >
                            <RefreshCw size={16} />
                            {t('content_wp_load_retry')}
                        </button>
                    </div>
                ) : (
                    <p className="seo-content-sync-required__hint">
                        <Loader2 size={16} className="seo-outline-spin" />
                        {' '}
                        {t('content_wp_loading')}
                    </p>
                )}
            </div>
        </div>
    );
}
