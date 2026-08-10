import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { fetchKeywordReviewReasons, submitKeywordReview } from '../utils/keywordReviewApi.js';
import {
    hasExactKeywordReviewReason,
    loadRecentKeywordReviewReasonIds,
    rankKeywordReviewReasons,
    rememberKeywordReviewReasonId,
} from '../utils/keywordReviewReasonUtils.js';
import { t } from '@content-addon/utils/i18n.js';

const CUSTOM_REASON_MAX_LENGTH = 255;

/**
 * @typedef {{
 *   itemKey: string,
 *   keywordId: number,
 *   text: string,
 *   severity: 'warning'|'danger',
 *   anchorEl: HTMLElement,
 * }} KeywordReviewPopoverState
 */

/**
 * @param {{
 *   state: KeywordReviewPopoverState|null,
 *   articleId?: number|null,
 *   onClose: () => void,
 *   onSubmitted: (payload: { keywordId: number, itemKey: string, text: string }) => void,
 *   onError: (payload: { itemKey: string, message: string }) => void,
 *   onLoadingChange: (itemKey: string|null) => void,
 * }} props
 */
export default function KeywordReviewPopover({
    state,
    articleId = null,
    onClose,
    onSubmitted,
    onError,
    onLoadingChange,
}) {
    const panelRef = useRef(null);
    const inputRef = useRef(null);
    const [reasons, setReasons] = useState([]);
    const [reasonsLoading, setReasonsLoading] = useState(false);
    const [query, setQuery] = useState('');
    const [highlightedIndex, setHighlightedIndex] = useState(0);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState('');
    const [recentIds, setRecentIds] = useState(() => loadRecentKeywordReviewReasonIds());
    const [position, setPosition] = useState({ top: 0, left: 0, width: 240 });

    useEffect(() => {
        if (!state) {
            return;
        }

        setQuery('');
        setHighlightedIndex(0);
        setError('');
        setRecentIds(loadRecentKeywordReviewReasonIds());

        if (reasons.length > 0) {
            return;
        }

        setReasonsLoading(true);
        fetchKeywordReviewReasons()
            .then((payload) => {
                setReasons(Array.isArray(payload.reasons) ? payload.reasons : []);
            })
            .catch((exception) => {
                onError({
                    itemKey: state.itemKey,
                    message: exception instanceof Error ? exception.message : String(exception),
                });
                onClose();
            })
            .finally(() => setReasonsLoading(false));
    }, [state?.itemKey, state?.severity]);

    useEffect(() => {
        if (!state?.anchorEl || !panelRef.current) {
            return;
        }

        const updatePosition = () => {
            const anchorRect = state.anchorEl.getBoundingClientRect();
            const panelWidth = Math.max(220, Math.min(280, anchorRect.width + 72));
            const viewportPadding = 8;
            let left = anchorRect.left;
            const maxLeft = window.innerWidth - panelWidth - viewportPadding;
            if (left > maxLeft) {
                left = Math.max(viewportPadding, maxLeft);
            }

            setPosition({
                top: anchorRect.bottom + 6 + window.scrollY,
                left: left + window.scrollX,
                width: panelWidth,
            });
        };

        updatePosition();
        window.addEventListener('resize', updatePosition);
        window.addEventListener('scroll', updatePosition, true);

        return () => {
            window.removeEventListener('resize', updatePosition);
            window.removeEventListener('scroll', updatePosition, true);
        };
    }, [state?.anchorEl, state?.itemKey, state?.severity]);

    useEffect(() => {
        if (!state) {
            return;
        }

        const focusTimer = window.setTimeout(() => {
            inputRef.current?.focus();
            inputRef.current?.select();
        }, 0);

        return () => window.clearTimeout(focusTimer);
    }, [state?.itemKey, state?.severity]);

    useEffect(() => {
        if (!state) {
            return undefined;
        }

        const handlePointerDown = (event) => {
            const target = event.target;
            if (!(target instanceof Node)) {
                return;
            }

            if (panelRef.current?.contains(target)) {
                return;
            }

            if (state.anchorEl.contains(target)) {
                return;
            }

            onClose();
        };

        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                onClose();
            }
        };

        document.addEventListener('mousedown', handlePointerDown);
        document.addEventListener('keydown', handleKeyDown);

        return () => {
            document.removeEventListener('mousedown', handlePointerDown);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [state, onClose]);

    const suggestions = useMemo(() => {
        if (!state) {
            return [];
        }

        return rankKeywordReviewReasons(reasons, {
            severity: state.severity,
            query,
            recentIds,
        });
    }, [reasons, query, recentIds, state?.severity]);

    const trimmedQuery = query.trim();
    const showCustomOption =
        trimmedQuery !== '' && !hasExactKeywordReviewReason(reasons, trimmedQuery) && !submitting;

    const options = useMemo(() => {
        const entries = suggestions.map((reason) => ({
            type: 'reason',
            id: Number(reason.id),
            label: String(reason.name),
        }));

        if (showCustomOption) {
            entries.push({
                type: 'custom',
                id: null,
                label: t('keyword_review_popover_use_custom', { label: trimmedQuery }),
            });
        }

        return entries;
    }, [suggestions, showCustomOption, trimmedQuery]);

    useEffect(() => {
        if (highlightedIndex >= options.length) {
            setHighlightedIndex(Math.max(0, options.length - 1));
        }
    }, [options.length, highlightedIndex]);

    if (!state || typeof document === 'undefined') {
        return null;
    }

    const submitSelection = async (selection) => {
        if (submitting) {
            return;
        }

        const keywordId = Number(state.keywordId);
        if (keywordId <= 0) {
            return;
        }

        let payload;
        if (selection.type === 'reason') {
            payload = {
                reason_id: Number(selection.id),
                custom_reason_text: null,
                severity: state.severity,
                article_id: articleId && articleId > 0 ? articleId : null,
                source: 'article_suggestion',
            };
        } else {
            const customText = String(selection.label ?? trimmedQuery).trim().slice(0, CUSTOM_REASON_MAX_LENGTH);
            if (customText === '') {
                onError({
                    itemKey: state.itemKey,
                    message: t('keyword_review_validation_required'),
                });
                return;
            }

            payload = {
                reason_id: null,
                custom_reason_text: customText,
                severity: state.severity,
                article_id: articleId && articleId > 0 ? articleId : null,
                source: 'article_suggestion',
            };
        }

        setSubmitting(true);
        setError('');
        onLoadingChange(state.itemKey);

        try {
            await submitKeywordReview(keywordId, payload);

            if (selection.type === 'reason') {
                rememberKeywordReviewReasonId(Number(selection.id));
            }

            onSubmitted({
                keywordId,
                itemKey: state.itemKey,
                text: state.text,
            });
            onClose();
        } catch (exception) {
            const message = exception instanceof Error ? exception.message : String(exception);
            setError(message);
            onError({
                itemKey: state.itemKey,
                message,
            });
        } finally {
            setSubmitting(false);
            onLoadingChange(null);
        }
    };

    const handleInputKeyDown = (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            if (options.length === 0) {
                return;
            }
            setHighlightedIndex((index) => (index + 1) % options.length);
            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            if (options.length === 0) {
                return;
            }
            setHighlightedIndex((index) => (index - 1 + options.length) % options.length);
            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();
            if (options.length > 0) {
                submitSelection(options[highlightedIndex] ?? options[0]);
                return;
            }

            if (trimmedQuery !== '') {
                submitSelection({ type: 'custom', id: null, label: trimmedQuery });
            }
        }
    };

    const severityClass = state.severity === 'danger' ? 'is-danger' : 'is-warning';

    return createPortal(
        <div
            ref={panelRef}
            className={`seo-keyword-review-popover ${severityClass}`}
            style={{
                top: `${position.top}px`,
                left: `${position.left}px`,
                width: `${position.width}px`,
            }}
            role="dialog"
            aria-label={t('keyword_review_field_reason')}
        >
            <label className="seo-keyword-review-popover__label">{t('keyword_review_field_reason')}</label>
            <input
                ref={inputRef}
                type="text"
                className="seo-keyword-review-popover__input"
                value={query}
                placeholder={t('keyword_review_reason_placeholder')}
                disabled={reasonsLoading || submitting}
                onChange={(event) => {
                    setQuery(event.target.value);
                    setHighlightedIndex(0);
                }}
                onKeyDown={handleInputKeyDown}
            />

            {reasonsLoading ? (
                <div className="seo-keyword-review-popover__loading animate-pulse">
                    <span />
                    <span />
                    <span />
                </div>
            ) : null}

            {!reasonsLoading && options.length > 0 ? (
                <ul className="seo-keyword-review-popover__options" role="listbox">
                    {options.map((option, index) => (
                        <li key={`${option.type}-${option.id ?? option.label}`} role="presentation">
                            <button
                                type="button"
                                role="option"
                                aria-selected={highlightedIndex === index}
                                className={`seo-keyword-review-popover__option${highlightedIndex === index ? ' is-active' : ''}${option.type === 'custom' ? ' is-custom' : ''}`}
                                onMouseEnter={() => setHighlightedIndex(index)}
                                onMouseDown={(event) => event.preventDefault()}
                                onClick={() => submitSelection(option)}
                                disabled={submitting}
                            >
                                {option.label}
                            </button>
                        </li>
                    ))}
                </ul>
            ) : null}

            {!reasonsLoading && trimmedQuery !== '' && options.length === 0 ? (
                <p className="seo-keyword-review-popover__hint">{t('keyword_review_popover_press_enter')}</p>
            ) : null}

            {error ? <p className="seo-keyword-review-popover__error">{error}</p> : null}
        </div>,
        document.body,
    );
}
