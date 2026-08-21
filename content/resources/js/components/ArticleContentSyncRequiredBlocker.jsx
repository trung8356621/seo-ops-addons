import React, { useCallback, useState } from 'react';
import { Loader2, RefreshCw } from 'lucide-react';
import { t } from '../utils/i18n';

/**
 * Sync-required blocker — WP-linked article without local content snapshot.
 * Reuses Livewire `syncArticleFromWordPress` (no second sync pipeline).
 */
export default function ArticleContentSyncRequiredBlocker({
    allowFetch = true,
    observedPermalink = null,
    onSyncSuccess = null,
}) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const runSync = useCallback(async () => {
        if (!allowFetch || busy) {
            return;
        }

        const wireId = String(window.__SEO_EDIT_ARTICLE_LIVEWIRE_ID__ ?? '').trim();
        const wire = typeof window.__seoResolveEditArticleWire === 'function'
            ? window.__seoResolveEditArticleWire(wireId)
            : (typeof Livewire !== 'undefined' && wireId !== '' ? Livewire.find(wireId) : null);

        if (!wire?.call) {
            setError(t('content_sync_required_wire_missing'));
            return;
        }

        setBusy(true);
        setError(null);
        window.__seoBeginArticleHeavyActionClient?.('restore');
        window.__seoArticleHeavyActionOverlay?.setStatusMessage?.(
            t('content_sync_required_syncing'),
        );

        try {
            const ok = await wire.call('syncArticleFromWordPress');
            if (!ok) {
                window.__seoEndArticleHeavyActionClient?.();
                setError(t('content_sync_required_failed'));
                return;
            }
            if (typeof onSyncSuccess === 'function') {
                onSyncSuccess();
            }
            // Existing sync finishes with page reload — keep busy until unload.
        } catch (err) {
            window.__seoEndArticleHeavyActionClient?.();
            setError(t('content_sync_required_failed'));
        } finally {
            setBusy(false);
        }
    }, [allowFetch, busy, onSyncSuccess]);

    return (
        <div
            className="seo-content-sync-required"
            data-seo-content-lifecycle="SYNC_REQUIRED"
            role="status"
        >
            <div className="seo-content-sync-required__card">
                <h3 className="seo-content-sync-required__title">
                    {t('content_sync_required_title')}
                </h3>
                <p className="seo-content-sync-required__body">
                    {t('content_sync_required_body')}
                </p>
                {observedPermalink ? (
                    <p className="seo-content-sync-required__permalink">
                        <span>{t('content_sync_required_wp_path')}</span>
                        {' '}
                        <a href={observedPermalink} target="_blank" rel="noopener noreferrer">
                            {observedPermalink}
                        </a>
                    </p>
                ) : null}
                <p className="seo-content-sync-required__hint">
                    {t('content_sync_required_hint')}
                </p>
                {error ? (
                    <p className="seo-content-sync-required__error" role="alert">
                        {error}
                    </p>
                ) : null}
                <div className="seo-content-sync-required__actions">
                    <button
                        type="button"
                        className="seo-content-sync-required__btn"
                        disabled={!allowFetch || busy}
                        onClick={() => { void runSync(); }}
                    >
                        {busy ? (
                            <>
                                <Loader2 size={16} className="seo-outline-spin" />
                                {t('content_sync_required_syncing')}
                            </>
                        ) : (
                            <>
                                <RefreshCw size={16} />
                                {error
                                    ? t('content_sync_required_retry')
                                    : t('content_sync_required_action')}
                            </>
                        )}
                    </button>
                </div>
            </div>
        </div>
    );
}
