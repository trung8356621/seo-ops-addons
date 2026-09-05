import React from 'react';
import { ExternalLink } from 'lucide-react';
import ResourceLinks from './ResourceLinks';

/**
 * Step 1 — create Topic only. Links are auto-detected snapshots.
 *
 * @param {{
 *   topic: Record<string, unknown>,
 *   canMutate: boolean,
 *   onChange: (partial: Record<string, unknown>) => void,
 *   onPasteContent: (event: React.ClipboardEvent) => void,
 *   onCancel: () => void,
 *   onCreate: () => void,
 * }} props
 */
export default function TopicComposer({
    topic,
    canMutate,
    onChange,
    onPasteContent,
    onCancel,
    onCreate,
}) {
    return (
        <div className="seeding-ws__composer" data-composer="step1">
            <div className="seeding-ws__composer-head">
                <h2>Tạo chủ đề</h2>
                <span className="seeding-ws__meta-pill seeding-ws__meta-pill--ok">Local-first</span>
            </div>

            <section className="seeding-ws__section">
                <div className="seeding-ws__section-title">Nội dung gốc</div>
                <textarea
                    className="seeding-ws__textarea"
                    value={topic.full_text || ''}
                    disabled={!canMutate}
                    placeholder="Dán nội dung bài social…"
                    onPaste={onPasteContent}
                    onChange={(e) => onChange({ full_text: e.target.value })}
                    autoFocus
                />
            </section>

            <section className="seeding-ws__section">
                <div className="seeding-ws__section-title">Link bài social (tuỳ chọn)</div>
                <div className="seeding-ws__social-row">
                    <input
                        className="seeding-ws__input"
                        value={topic.social_url || ''}
                        disabled={!canMutate}
                        placeholder="https://threads.com/… hoặc facebook.com/…"
                        onChange={(e) => onChange({ social_url: e.target.value })}
                    />
                    {topic.social_url ? (
                        <a className="seeding-ws__icon-btn" href={topic.social_url} target="_blank" rel="noreferrer" title="Mở">
                            <ExternalLink size={14} />
                        </a>
                    ) : null}
                </div>
            </section>

            <ResourceLinks links={topic.links || []} />

            <footer className="seeding-ws__composer-footer">
                <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={onCancel}>
                    Hủy
                </button>
                <button
                    type="button"
                    className="seeding-ws__btn seeding-ws__btn--primary"
                    onClick={onCreate}
                    disabled={!canMutate || !String(topic.full_text || '').trim()}
                >
                    Tạo chủ đề
                </button>
            </footer>
        </div>
    );
}
