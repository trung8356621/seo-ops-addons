import React, { useMemo } from 'react';
import { ExternalLink } from 'lucide-react';
import {
    deriveTopicAssignedLinks,
    deriveTopicSocialTarget,
} from '../features/workspace/selectors';

/**
 * Self-contained Topic work package: social destination + assigned seeding links.
 * Progress = Seeder local today / DB target_per_day.
 *
 * @param {{
 *   topic: Record<string, unknown>,
 *   dailyProgressMap?: Record<string, number>,
 *   linkPreviewCache?: Record<string, Record<string, unknown>>,
 * }} props
 */
export default function TopicWorkTargets({
    topic,
    dailyProgressMap = {},
    linkPreviewCache = {},
}) {
    const social = useMemo(() => deriveTopicSocialTarget(topic), [topic]);
    const rows = useMemo(
        () => deriveTopicAssignedLinks(topic, dailyProgressMap, linkPreviewCache),
        [topic, dailyProgressMap, linkPreviewCache],
    );

    if (!social && rows.length === 0) return null;

    return (
        <div className="seeding-ws__topic-work" data-topic-work>
            {social ? (
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
            ) : null}

            {rows.length > 0 ? (
                <section className="seeding-ws__topic-work-block" data-topic-assigned-links>
                    <div className="seeding-ws__section-title">Assigned links today</div>
                    <ul className="seeding-ws__topic-work-list">
                        {rows.map((row) => (
                            <li key={row.key} className="seeding-ws__topic-work-row" data-assignment-id={row.id}>
                                <div className="seeding-ws__topic-work-main">
                                    <span className="seeding-ws__topic-work-title">{row.title}</span>
                                    <a
                                        className="seeding-ws__topic-work-url"
                                        href={row.url}
                                        target="_blank"
                                        rel="noreferrer"
                                        title={row.url}
                                    >
                                        {row.urlShort}
                                    </a>
                                </div>
                                <div className="seeding-ws__topic-work-meta">
                                    <span className="seeding-ws__topic-work-count" title="local hôm nay / target/ngày">
                                        {row.localDone}
                                        {' / '}
                                        {row.targetPerDay > 0 ? row.targetPerDay : '—'}
                                    </span>
                                    <a
                                        className="seeding-ws__icon-btn"
                                        href={row.url}
                                        target="_blank"
                                        rel="noreferrer"
                                        title={row.url}
                                        aria-label="Mở link"
                                    >
                                        <ExternalLink size={14} />
                                    </a>
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}
        </div>
    );
}
