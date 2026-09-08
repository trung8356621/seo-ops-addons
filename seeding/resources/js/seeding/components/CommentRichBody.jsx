import React, { useCallback } from 'react';
import ContentWithLinkPreviews from './ContentWithLinkPreviews';
import useEnsureLinkPreviews from '../hooks/useEnsureLinkPreviews';
import { syncLinksWithText } from '../services/linkPreviewPipeline';

/**
 * Comment body with shared Topic link-preview pipeline (compact variant on feed).
 *
 * @param {{
 *   comment: Record<string, unknown>,
 *   variant?: 'topic' | 'comment',
 *   clampLines?: number,
 *   maxRichPreviews?: number,
 *   linkPreviewCache?: Record<string, Record<string, unknown>>,
 *   onCommentLinksChange?: (commentId: string, links: Array<Record<string, unknown>>) => void,
 *   onCacheUpdate?: (cache: Record<string, Record<string, unknown>>) => void,
 *   className?: string,
 * }} props
 */
export default function CommentRichBody({
    comment,
    variant = 'comment',
    clampLines = 2,
    maxRichPreviews = 1,
    linkPreviewCache = {},
    onCommentLinksChange,
    onCacheUpdate,
    className = '',
}) {
    const text = String(comment.text || '');
    const links = Array.isArray(comment.links) && comment.links.length > 0
        ? comment.links
        : syncLinksWithText(text, [], linkPreviewCache);

    const onLinksChange = useCallback((next) => {
        if (!comment.id || !onCommentLinksChange) return;
        onCommentLinksChange(String(comment.id), next);
    }, [comment.id, onCommentLinksChange]);

    useEnsureLinkPreviews(links, {
        cache: linkPreviewCache,
        onLinksChange,
        onCacheUpdate,
        enabled: Boolean(onCommentLinksChange),
    });

    return (
        <ContentWithLinkPreviews
            text={text}
            links={links}
            clampLines={clampLines}
            maxRichPreviews={maxRichPreviews}
            variant={variant}
            className={className}
        />
    );
}
