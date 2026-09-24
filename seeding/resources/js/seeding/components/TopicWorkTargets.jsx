import React, { useMemo } from 'react';
import { ExternalLink } from 'lucide-react';
import { deriveTopicSocialTarget } from '../features/workspace/selectors';

/**
 * Topic card social destination only (FLOW A).
 * Shared assignment progress (FLOW B) lives in workspace sidebar — not on Topic cards.
 *
 * @param {{
 *   topic: Record<string, unknown>,
 * }} props
 */
export default function TopicWorkTargets({ topic }) {
    const social = useMemo(() => deriveTopicSocialTarget(topic), [topic]);

    if (!social) return null;

    return (
        <div className="seeding-ws__topic-work" data-topic-work>
            <section className="seeding-ws__topic-work-block" data-topic-social>
                <div className="seeding-ws__section-title">Social target</div>
                <div className="seeding-ws__topic-work-row">
                    <div className="seeding-ws__topic-work-main">
                        <span className="seeding-ws__topic-work-title">{social.platform}</span>
                        <a
                            className="seeding-ws__topic-work-url"
                            href={social.url}
                            target="_blank"
                            rel="noreferrer"
                            title={social.url}
                        >
                            {social.urlShort}
                        </a>
                    </div>
                    <a
                        className="seeding-ws__icon-btn"
                        href={social.url}
                        target="_blank"
                        rel="noreferrer"
                        title={social.url}
                        aria-label="Mở social target"
                    >
                        <ExternalLink size={14} />
                    </a>
                </div>
            </section>
        </div>
    );
}
