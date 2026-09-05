import React from 'react';
import { Link2 } from 'lucide-react';

/**
 * Auto-detected links — read-only snapshot. No add/edit/delete.
 *
 * @param {{ links?: Array<{ url: string, normalized_url?: string }> }} props
 */
export default function ResourceLinks({ links }) {
    const list = Array.isArray(links) ? links : [];

    return (
        <section className="seeding-ws__section" data-section="links-readonly">
            <div className="seeding-ws__section-title">Link &amp; tài nguyên</div>
            {list.length === 0 ? (
                <div className="seeding-ws__muted">Không phát hiện link khi tạo chủ đề.</div>
            ) : (
                <ul className="seeding-ws__link-list">
                    {list.map((link, index) => (
                        <li key={`${link.normalized_url || link.url}-${index}`}>
                            <Link2 size={12} />
                            <a href={link.url} target="_blank" rel="noreferrer">{link.url}</a>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
