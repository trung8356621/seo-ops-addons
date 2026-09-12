import React, { useEffect, useState } from 'react';
import {
    fetchManagerTopics,
    pauseManagerTopic,
    resumeManagerTopic,
    cancelManagerTopic,
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

/**
 * Manager-only table + stats.
 */
export default function ManagerPanel({ websiteStats = null }) {
    const [subTab, setSubTab] = useState('topics');
    const [status, setStatus] = useState('all');
    const [social, setSocial] = useState('');
    const [search, setSearch] = useState('');
    const [topics, setTopics] = useState([]);
    const [stats, setStats] = useState(null);
    const [loading, setLoading] = useState(false);

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

    useEffect(() => {
        load();
    }, [status, social]);

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
        </div>
    );
}
