import React from 'react';
import { hasRichPreview } from '../features/workspace/content';

/**
 * Shared link preview card — Topic (vertical) + Comment (compact horizontal).
 *
 * @param {{
 *   link: Record<string, unknown>,
 *   href?: string,
 *   variant?: 'topic' | 'comment',
 *   onNavigate?: (e: React.MouseEvent) => void,
 * }} props
 */
export default function LinkPreviewCard({
    link,
    href,
    variant = 'topic',
    onNavigate,
}) {
    if (!hasRichPreview(link)) return null;
    const url = href || link.preview_url || link.url;
    const title = link.preview_title || link.preview_domain || 'Link';
    const domain = link.preview_domain || '';
    const desc = link.preview_description || '';
    const image = link.preview_image_url || null;
    const isComment = variant === 'comment';

    return (
        <a
            className={`seeding-ws__link-preview seeding-ws__link-preview--${variant}`}
            href={url}
            target="_blank"
            rel="noreferrer"
            data-preview-variant={variant}
            onClick={(e) => {
                e.stopPropagation();
                onNavigate?.(e);
            }}
        >
            {image ? (
                <div className="seeding-ws__link-preview-media" data-preview-media>
                    <img
                        src={String(image)}
                        alt=""
                        loading="lazy"
                        referrerPolicy="no-referrer"
                        className="seeding-ws__link-preview-img"
                    />
                </div>
            ) : (
                <div className="seeding-ws__link-preview-media seeding-ws__link-preview-media--empty" data-preview-media />
            )}
            <div className="seeding-ws__link-preview-body">
                <div className="seeding-ws__link-preview-title">{title}</div>
                {domain ? <div className="seeding-ws__link-preview-domain">{domain}</div> : null}
                {!isComment && desc ? (
                    <div className="seeding-ws__link-preview-desc">{desc}</div>
                ) : null}
            </div>
        </a>
    );
}
