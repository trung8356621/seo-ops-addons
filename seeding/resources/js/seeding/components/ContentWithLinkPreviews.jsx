import React, { useMemo } from 'react';
import LinkPreviewCard from './LinkPreviewCard';
import {
    findLinkMeta,
    hasRichPreview,
    splitContentByUrls,
} from '../features/workspace/content';

/**
 * Render content with URL tokens replaced by rich previews (or plain anchors on fallback).
 *
 * @param {{
 *   text: string,
 *   links?: Array<Record<string, unknown>>,
 *   clampLines?: number,
 *   className?: string,
 * }} props
 */
export default function ContentWithLinkPreviews({
    text,
    links = [],
    clampLines = 0,
    className = '',
}) {
    const parts = useMemo(() => splitContentByUrls(text), [text]);

    return (
        <div className={`seeding-ws__rich-content ${className}`.trim()}>
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
                if (hasRichPreview(meta)) {
                    return (
                        <LinkPreviewCard
                            key={`p-${index}-${part.value}`}
                            link={meta}
                            href={meta.preview_url || meta.url || part.value}
                        />
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
