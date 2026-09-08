import React, { useMemo } from 'react';
import LinkPreviewCard from './LinkPreviewCard';
import {
    findLinkMeta,
    hasRichPreview,
    splitContentByUrls,
} from '../features/workspace/content';

/**
 * Render content with URL tokens replaced by rich previews (or plain anchors on fallback).
 * Shared by Topic + Comment — same extraction / metadata / fallback path.
 *
 * @param {{
 *   text: string,
 *   links?: Array<Record<string, unknown>>,
 *   clampLines?: number,
 *   maxRichPreviews?: number,
 *   variant?: 'topic' | 'comment',
 *   className?: string,
 * }} props
 */
export default function ContentWithLinkPreviews({
    text,
    links = [],
    clampLines = 0,
    maxRichPreviews = Infinity,
    variant = 'topic',
    className = '',
}) {
    const parts = useMemo(() => splitContentByUrls(text), [text]);
    let richShown = 0;

    return (
        <div
            className={`seeding-ws__rich-content seeding-ws__rich-content--${variant} ${className}`.trim()}
            data-rich-variant={variant}
        >
            {parts.map((part, index) => {
                if (part.type === 'text') {
                    const value = part.value;
                    if (!value.trim() && parts.length > 1) {
                        return null;
                    }
                    return (
                        <p
                            key={`t-${index}`}
                            className={clampLines > 0 ? 'seeding-ws__rich-text seeding-ws__rich-text--clamp' : 'seeding-ws__rich-text'}
                            style={clampLines > 0 ? { WebkitLineClamp: clampLines } : undefined}
                        >
                            {value}
                        </p>
                    );
                }
                const meta = findLinkMeta(links, part.value);
                if (hasRichPreview(meta) && richShown < maxRichPreviews) {
                    richShown += 1;
                    return (
                        <LinkPreviewCard
                            key={`p-${index}-${part.value}`}
                            link={meta}
                            href={meta.preview_url || meta.url || part.value}
                            variant={variant}
                        />
                    );
                }
                if (hasRichPreview(meta)) {
                    // Extra rich URLs beyond max — compact clickable text (feed comments).
                    return (
                        <a
                            key={`u-${index}`}
                            className="seeding-ws__inline-url"
                            href={meta.preview_url || meta.url || part.value}
                            target="_blank"
                            rel="noreferrer"
                            onClick={(e) => e.stopPropagation()}
                        >
                            {meta.preview_domain || meta.preview_title || part.value}
                        </a>
                    );
                }
                return (
                    <a
                        key={`u-${index}`}
                        className="seeding-ws__inline-url"
                        href={part.value}
                        target="_blank"
                        rel="noreferrer"
                        onClick={(e) => e.stopPropagation()}
                    >
                        {part.value}
                    </a>
                );
            })}
        </div>
    );
}
