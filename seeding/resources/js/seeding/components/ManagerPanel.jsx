import React, { useEffect, useState } from 'react';
import { Loader2, X } from 'lucide-react';
import {
    fetchManagerTopics,
    pauseManagerTopic,
    resumeManagerTopic,
    cancelManagerTopic,
    fetchCommentPrompt,
    fetchCommentPromptHistoryDetail,
} from '../api';
import { notifyError, notifySuccess } from '../services/toast';

const STATUS_FILTERS = [
    { id: 'all', label: 'Tất cả' },
    { id: 'running', label: 'Đang chạy' },
    { id: 'pending', label: 'Chờ bắt đầu' },
    { id: 'done', label: 'Đã hoàn thành' },
    { id: 'paused', label: 'Tạm dừng' },
];

const SUB_TABS = [
    { id: 'topics', label: 'Chủ đề' },
    { id: 'reports', label: 'Báo cáo' },
    { id: 'members', label: 'Thành viên' },
    { id: 'summary', label: 'Tổng kết' },
];

function statusLabel(status) {
    if (status === 'success') return 'Thành công';
    if (status === 'failed') return 'Thất bại';
    return status || '—';
}

/**
 * Manager-only table + stats + Gen Comment prompt/history.
 */
export default function ManagerPanel({ websiteStats = null }) {
    const [subTab, setSubTab] = useState('topics');
    const [status, setStatus] = useState('all');
    const [social, setSocial] = useState('');
    const [search, setSearch] = useState('');
    const [topics, setTopics] = useState([]);
    const [stats, setStats] = useState(null);
    const [loading, setLoading] = useState(false);

    const [promptBody, setPromptBody] = useState('');
    const [promptLoading, setPromptLoading] = useState(false);
    const [promptError, setPromptError] = useState('');
    const [promptEditUrl, setPromptEditUrl] = useState('');
    const [promptHookKey, setPromptHookKey] = useState('seeding.comment.generate');
    const [history, setHistory] = useState([]);
    const [detailOpen, setDetailOpen] = useState(false);
    const [detailLoading, setDetailLoading] = useState(false);
    const [detail, setDetail] = useState(null);

    const load = async () => {
        setLoading(true);
        try {
            const data = await fetchManagerTopics({ status, social, search });
            setTopics(Array.isArray(data?.topics) ? data.topics : []);
            setStats(data?.stats || null);
        } catch (e) {
            notifyError(e?.message || 'Không tải được bảng quản lý');
        } finally {
            setLoading(false);
        }
    };

    const loadPrompt = async () => {
        setPromptLoading(true);
        setPromptError('');
        try {
            const data = await fetchCommentPrompt();
            setPromptBody(String(data?.prompt_body ?? ''));
            setPromptEditUrl(String(data?.edit_url ?? ''));
            setPromptHookKey(String(data?.hook_key ?? 'seeding.comment.generate'));
            setHistory(Array.isArray(data?.history) ? data.history : []);
        } catch (e) {
            const message = e?.message || 'Không tải được Prompt Gen Comment';
            setPromptError(message);
            notifyError(message);
            // Keep sections visible — empty prompt/history + error banner.
        } finally {
            setPromptLoading(false);
        }
    };

    useEffect(() => {
        load();
    }, [status, social]);

    useEffect(() => {
        if (subTab === 'summary') {
            loadPrompt();
        }
    }, [subTab]);

    const onAction = async (topic, action) => {
        try {
            if (action === 'pause') await pauseManagerTopic(topic.id);
            if (action === 'resume') await resumeManagerTopic(topic.id);
            if (action === 'cancel') {
                if (!window.confirm('Hủy chủ đề này?')) return;
                await cancelManagerTopic(topic.id);
            }
            notifySuccess('Đã cập nhật');
            await load();
        } catch (e) {
            notifyError(e?.message || 'Thao tác thất bại');
        }
    };

    const onSavePrompt = async () => {
        notifyError('Prompt Gen Comment được chỉnh trong Prompt Management — không lưu tại đây.');
    };

    const onViewHistory = async (row) => {
        setDetailOpen(true);
        setDetail(null);
        setDetailLoading(true);
        try {
            const data = await fetchCommentPromptHistoryDetail(row.slot);
            setDetail(data?.log || null);
        } catch (e) {
            notifyError(e?.message || 'Không tải được chi tiết log');
            setDetailOpen(false);
        } finally {
            setDetailLoading(false);
        }
    };

    return (
        <div data-panel="manager">
            <div className="seeding-ws__manager-subtabs">
                {SUB_TABS.map((tab) => (
                    <button
                        key={tab.id}
                        type="button"
                        className={`seeding-ws__main-tab${subTab === tab.id ? ' is-active' : ''}`}
                        onClick={() => setSubTab(tab.id)}
                    >
                        {tab.label}
                    </button>
                ))}
            </div>

            {subTab === 'summary' ? (
                <div className="seeding-ws__summary-stack">
                    <div className="seeding-ws__stats-grid">
                        <div className="seeding-ws__stat-tile"><strong>{stats?.topics_running ?? '—'}</strong><span>Đang chạy</span></div>
                        <div className="seeding-ws__stat-tile"><strong>{stats?.topics_done ?? '—'}</strong><span>Hoàn thành</span></div>
                        <div className="seeding-ws__stat-tile"><strong>{stats?.topics_pending ?? '—'}</strong><span>Pending</span></div>
                        <div className="seeding-ws__stat-tile"><strong>{stats?.topics_paused ?? '—'}</strong><span>Tạm dừng</span></div>
                        <div className="seeding-ws__stat-tile"><strong>{stats?.comments_required ?? '—'}</strong><span>Comments required</span></div>
                        <div className="seeding-ws__stat-tile"><strong>{stats?.comments_completed ?? '—'}</strong><span>Comments completed</span></div>
                        <div className="seeding-ws__stat-tile"><strong>{websiteStats?.pending ?? '—'}</strong><span>Website pending</span></div>
                        <div className="seeding-ws__stat-tile"><strong>{websiteStats?.ready ?? '—'}</strong><span>Website ready</span></div>
                        <div className="seeding-ws__stat-tile"><strong>{websiteStats?.completed ?? '—'}</strong><span>Website completed</span></div>
                    </div>

                    <section className="seeding-ws__prompt-section" data-section="gen-comment-prompt">
                        <h3 className="seeding-ws__section-title">Prompt Gen Comment</h3>
                        {promptError ? (
                            <p className="seeding-ws__prompt-error" role="alert">{promptError}</p>
                        ) : null}
                        <p className="seeding-ws__prompt-help" data-authority="shared_prompt">
                            Nguồn SSOT: Prompt Management (<code>{promptHookKey}</code>). Seeding chỉ xem — không sửa tại đây.
                        </p>
                        <textarea
                            className="seeding-ws__textarea seeding-ws__textarea--prompt"
                            value={promptBody}
                            readOnly
                            rows={12}
                            spellCheck={false}
                            disabled={promptLoading}
                            aria-busy={promptLoading}
                            aria-readonly="true"
                        />
                        <p className="seeding-ws__prompt-help">
                            Biến hỗ trợ duy nhất: <code>{'{{mcp_context}}'}</code>
                        </p>
                        {promptEditUrl ? (
                            <a
                                className="seeding-ws__btn seeding-ws__btn--primary"
                                href={promptEditUrl}
                                data-link="shared-prompt-editor"
                            >
                                Mở Prompt Management
                            </a>
                        ) : (
                            <button
                                type="button"
                                className="seeding-ws__btn seeding-ws__btn--ghost"
                                onClick={onSavePrompt}
                                disabled
                            >
                                Chỉnh sửa tại Prompt Management
                            </button>
                        )}
                    </section>

                    <section className="seeding-ws__prompt-section" data-section="gen-comment-history">
                        <h3 className="seeding-ws__section-title">Lịch sử Gen Comment</h3>
                        <div className="seeding-ws__manager-table-wrap">
                            <table className="seeding-ws__manager-table seeding-ws__history-table">
                                <thead>
                                    <tr>
                                        <th>Thời gian</th>
                                        <th>Chủ đề</th>
                                        <th>MXH</th>
                                        <th>Số lượng</th>
                                        <th>Model</th>
                                        <th>Trạng thái</th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody>
                                    {history.length === 0 ? (
                                        <tr>
                                            <td colSpan={7}>
                                                {promptLoading
                                                    ? 'Đang tải…'
                                                    : promptError
                                                        ? 'Không tải được lịch sử. Thử lại sau.'
                                                        : 'Chưa có lần Gen nào.'}
                                            </td>
                                        </tr>
                                    ) : history.map((row) => (
                                        <tr key={`${row.slot}-${row.sequence}`}>
                                            <td>{row.generated_at_label || '—'}</td>
                                            <td>{row.topic_id ?? '—'}</td>
                                            <td>{row.social || '—'}</td>
                                            <td>{row.quantity ?? '—'}</td>
                                            <td className="seeding-ws__mono-cell">
                                                {[row.provider, row.model].filter(Boolean).join('/') || '—'}
                                            </td>
                                            <td>{statusLabel(row.status)}</td>
                                            <td>
                                                <button
                                                    type="button"
                                                    className="seeding-ws__btn seeding-ws__btn--ghost"
                                                    onClick={() => onViewHistory(row)}
                                                >
                                                    Xem
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            ) : null}

            {subTab === 'topics' ? (
                <>
                    <div className="seeding-ws__manager-filters">
                        {STATUS_FILTERS.map((f) => (
                            <button
                                key={f.id}
                                type="button"
                                className={`seeding-ws__tab${status === f.id ? ' is-active' : ''}`}
                                onClick={() => setStatus(f.id)}
                            >
                                {f.label}
                            </button>
                        ))}
                        <select
                            className="seeding-ws__input"
                            style={{ maxWidth: '10rem' }}
                            value={social}
                            onChange={(e) => setSocial(e.target.value)}
                        >
                            <option value="">Tất cả social</option>
                            <option value="facebook">Facebook</option>
                            <option value="threads">Threads</option>
                            <option value="tiktok">TikTok</option>
                            <option value="pinterest">Pinterest</option>
                            <option value="reddit">Reddit</option>
                        </select>
                        <input
                            className="seeding-ws__input"
                            style={{ maxWidth: '16rem' }}
                            placeholder="Search title/link"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => { if (e.key === 'Enter') load(); }}
                        />
                        <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={load} disabled={loading}>
                            {loading ? 'Đang tải…' : 'Lọc'}
                        </button>
                    </div>

                    <div className="seeding-ws__manager-table-wrap">
                        <table className="seeding-ws__manager-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Chủ đề</th>
                                    <th>Link</th>
                                    <th>MXH</th>
                                    <th>Yêu cầu / HT</th>
                                    <th>Tiến độ</th>
                                    <th>Trạng thái</th>
                                    <th>Người tạo</th>
                                    <th>Ngày tạo</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                {topics.length === 0 ? (
                                    <tr>
                                        <td colSpan={10}>{loading ? 'Đang tải…' : 'Chưa có chủ đề.'}</td>
                                    </tr>
                                ) : topics.map((topic, idx) => (
                                    <tr key={topic.id}>
                                        <td>{idx + 1}</td>
                                        <td>{topic.title || topic.preview || '—'}</td>
                                        <td style={{ maxWidth: '12rem', wordBreak: 'break-all' }}>
                                            {topic.social_url || (topic.links?.[0]?.url) || '—'}
                                        </td>
                                        <td>{topic.social_platform_label || topic.social_platform || '—'}</td>
                                        <td>{topic.progress_label || `${topic.completed_comments || 0} / ${topic.target_comments || 0}`}</td>
                                        <td>{topic.progress_percent ?? 0}%</td>
                                        <td>{topic.status_label || topic.status}</td>
                                        <td>{topic.creator_name || topic.created_by_display_name || '—'}</td>
                                        <td>{topic.created_date_label || '—'}</td>
                                        <td>
                                            <div style={{ display: 'flex', gap: '0.25rem', flexWrap: 'wrap' }}>
                                                {topic.status === 'paused' ? (
                                                    <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={() => onAction(topic, 'resume')}>Resume</button>
                                                ) : (
                                                    <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={() => onAction(topic, 'pause')}>Pause</button>
                                                )}
                                                <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={() => onAction(topic, 'cancel')}>Hủy</button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            ) : null}

            {subTab === 'reports' || subTab === 'members' ? (
                <div className="seeding-ws__empty-feed">
                    <p>Bảng {subTab === 'reports' ? 'báo cáo' : 'thành viên'} sẽ mở rộng từ seeding_reports (đang dùng dữ liệu Tổng kết).</p>
                </div>
            ) : null}

            {detailOpen ? (
                <div className="seeding-ws__modal-backdrop" role="presentation" onClick={() => setDetailOpen(false)}>
                    <div
                        className="seeding-ws__modal seeding-ws__modal--wide"
                        role="dialog"
                        aria-modal="true"
                        aria-label="Chi tiết Gen Comment"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="seeding-ws__modal-head">
                            <h3>Chi tiết Gen Comment</h3>
                            <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={() => setDetailOpen(false)}>
                                <X size={16} />
                            </button>
                        </div>
                        {detailLoading ? (
                            <div className="seeding-ws__prompt-skeleton" aria-busy="true">
                                <div className="seeding-ws__skeleton-block" />
                                <div className="seeding-ws__skeleton-block" />
                            </div>
                        ) : detail ? (
                            <div className="seeding-ws__history-detail">
                                <dl className="seeding-ws__history-meta">
                                    <div><dt>Thời gian</dt><dd>{detail.generated_at_label || '—'}</dd></div>
                                    <div><dt>Provider</dt><dd>{detail.provider || '—'}</dd></div>
                                    <div><dt>Model</dt><dd>{detail.model || '—'}</dd></div>
                                    <div><dt>Quantity</dt><dd>{detail.quantity ?? '—'}</dd></div>
                                    <div><dt>Status</dt><dd>{statusLabel(detail.status)}</dd></div>
                                    <div><dt>Social</dt><dd>{detail.social || '—'}</dd></div>
                                    <div><dt>Topic</dt><dd>{detail.topic_id ?? '—'}</dd></div>
                                </dl>
                                {detail.error_message ? (
                                    <div className="seeding-ws__history-error">
                                        <strong>Error</strong>
                                        <pre className="seeding-ws__pre">{detail.error_message}</pre>
                                    </div>
                                ) : null}
                                <div>
                                    <h4>1. MCP Context</h4>
                                    <pre className="seeding-ws__pre">{detail.mcp_context || ''}</pre>
                                </div>
                                <div>
                                    <h4>2. Final AI Request / Final Prompt</h4>
                                    <pre className="seeding-ws__pre">{detail.final_prompt || ''}</pre>
                                </div>
                                <div>
                                    <h4>3. AI Output</h4>
                                    <pre className="seeding-ws__pre">{detail.ai_output || '—'}</pre>
                                </div>
                            </div>
                        ) : (
                            <p>Không có dữ liệu.</p>
                        )}
                    </div>
                </div>
            ) : null}
        </div>
    );
}
