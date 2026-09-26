import { auditT } from '../../i18n-audit.js';
import React, { useEffect, useMemo, useState } from 'react';
import { Lock, Pencil, Plus, Unlock, X } from 'lucide-react';
import {
    copySocialAccountPassword,
    copySocialAccountUsername,
    createSocialAccount,
    deleteSocialAccount,
    fetchSocialAccounts,
    lockSocialAccount,
    unlockSocialAccount,
    updateSocialAccount,
} from '../api';
import { writeClipboard } from '../services/clipboardWrite';
import { notifyError, notifySuccess } from '../services/toast';

const EMPTY_FORM = {
    site_id: '',
    domain: '',
    platform: 'facebook',
    label: '',
    username: '',
    password: '',
    status: 'active',
};

/**
 * Manager: Tài khoản Social — domain-grouped CRUD + copy credentials (no show-password).
 */
export default function SocialAccountsPanel() {
    const [accounts, setAccounts] = useState([]);
    const [sites, setSites] = useState([]);
    const [platforms, setPlatforms] = useState([]);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [formOpen, setFormOpen] = useState(false);
    const [editingId, setEditingId] = useState(null);
    const [form, setForm] = useState(EMPTY_FORM);

    const load = async () => {
        setLoading(true);
        try {
            const data = await fetchSocialAccounts();
            setAccounts(Array.isArray(data?.accounts) ? data.accounts : []);
            setSites(Array.isArray(data?.sites) ? data.sites : []);
            setPlatforms(Array.isArray(data?.platforms) ? data.platforms : []);
        } catch (e) {
            notifyError(e?.message || auditT('audit_a621f31754ba'));
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        load();
    }, []);

    const grouped = useMemo(() => {
        const map = new Map();
        for (const row of accounts) {
            const key = row.site_id
                ? `site:${row.site_id}`
                : `domain:${String(row.domain || '').toLowerCase()}`;
            const title = row.domain || (row.site_id ? `Site #${row.site_id}` : '—');
            if (!map.has(key)) {
                map.set(key, { key, title, siteId: row.site_id ?? null, accounts: [] });
            }
            map.get(key).accounts.push(row);
        }
        return Array.from(map.values());
    }, [accounts]);

    const openCreate = () => {
        const defaultSite = sites[0];
        setEditingId(null);
        setForm({
            ...EMPTY_FORM,
            site_id: defaultSite ? String(defaultSite.id) : '',
            domain: defaultSite?.domain || '',
            platform: platforms[0]?.value || 'facebook',
        });
        setFormOpen(true);
    };

    const openEdit = (row) => {
        setEditingId(row.id);
        setForm({
            site_id: row.site_id != null ? String(row.site_id) : '',
            domain: row.domain || '',
            platform: row.platform || 'facebook',
            label: row.label || '',
            username: row.username || '',
            password: '',
            status: row.status || 'active',
        });
        setFormOpen(true);
    };

    const closeForm = () => {
        setFormOpen(false);
        setEditingId(null);
        setForm(EMPTY_FORM);
    };

    const onSave = async (e) => {
        e?.preventDefault?.();
        if (!form.site_id && !String(form.domain || '').trim()) {
            notifyError(auditT('audit_d2e8cbf8d0f2'));
            return;
        }
        setSaving(true);
        try {
            const payload = {
                site_id: form.site_id ? Number(form.site_id) : undefined,
                domain: form.domain || undefined,
                platform: form.platform,
                label: form.label || null,
                username: form.username || null,
                status: form.status,
            };
            if (form.password && String(form.password).trim() !== '') {
                payload.password = form.password;
            } else if (!editingId) {
                payload.password = form.password || null;
            }

            if (editingId) {
                await updateSocialAccount(editingId, payload);
                notifySuccess(auditT('audit_3250ac3ede1f'));
            } else {
                await createSocialAccount(payload);
                notifySuccess(auditT('audit_9e0be2688c4e'));
            }
            closeForm();
            await load();
        } catch (err) {
            notifyError(err?.message || auditT('audit_62191805517f'));
        } finally {
            setSaving(false);
        }
    };

    const onLockToggle = async (row) => {
        try {
            if (row.status === 'locked') {
                await unlockSocialAccount(row.id);
                notifySuccess(auditT('audit_2f47212eb4b6'));
            } else {
                await lockSocialAccount(row.id);
                notifySuccess(auditT('audit_afb07025bddc'));
            }
            await load();
        } catch (err) {
            notifyError(err?.message || auditT('audit_941196265df9'));
        }
    };

    const onDelete = async (row) => {
        if (!window.confirm(`Xóa tài khoản ${row.platform_label || row.platform} trên ${row.domain}?`)) {
            return;
        }
        try {
            await deleteSocialAccount(row.id);
            notifySuccess(auditT('audit_3947afd7c08c'));
            await load();
        } catch (err) {
            notifyError(err?.message || auditT('audit_acbc2cf74ab2'));
        }
    };

    const copyCredential = async (kind, accountId) => {
        try {
            const data = kind === 'password'
                ? await copySocialAccountPassword(accountId)
                : await copySocialAccountUsername(accountId);
            const value = String(data?.value ?? '');
            if (!value) {
                notifyError(kind === 'password' ? auditT('audit_0abec7692f17') : auditT('audit_b8ee40c1a03e'));
                return;
            }
            const wrote = await writeClipboard(value);
            // Discard plaintext immediately — never keep in component state / never render.
            if (!wrote.ok) {
                notifyError(wrote.error || auditT('audit_3c2cdeeee00d'));
                return;
            }
            notifySuccess(kind === 'password' ? auditT('audit_f56f048ebe7e') : auditT('audit_8d03cab7d74a'));
        } catch (err) {
            notifyError(err?.message || auditT('audit_1cdc97b3a598'));
        }
    };

    return (
        <div data-section="manager-social-accounts">
            <div className="seeding-ws__manager-filters">
                <button
                    type="button"
                    className="seeding-ws__btn seeding-ws__btn--primary"
                    onClick={openCreate}
                    data-action="social-account-create"
                >
                    <Plus size={14} />
                    Thêm tài khoản
                </button>
                <button
                    type="button"
                    className="seeding-ws__btn seeding-ws__btn--ghost"
                    onClick={load}
                    disabled={loading}
                >
                    {loading ? auditT('audit_577a1d591051') : auditT('audit_61b0561227b1')}
                </button>
            </div>

            {loading && accounts.length === 0 ? (
                <p className="seeding-ws__muted">Đang tải…</p>
            ) : grouped.length === 0 ? (
                <div className="seeding-ws__empty-feed">
                    <p>Chưa có tài khoản social. Thêm theo từng Domain/Site.</p>
                </div>
            ) : (
                <div className="seeding-ws__social-acct-groups">
                    {grouped.map((group) => (
                        <section
                            key={group.key}
                            className="seeding-ws__social-acct-group"
                            data-domain={group.title}
                        >
                            <h3 className="seeding-ws__social-acct-domain">{group.title}</h3>
                            <div className="seeding-ws__social-acct-list">
                                {group.accounts.map((row) => {
                                    const isLocked = row.status === 'locked';
                                    return (
                                        <article
                                            key={row.id}
                                            className={`seeding-ws__social-acct-card${isLocked ? ' is-locked' : ''}`}
                                            data-account-id={row.id}
                                            data-status={row.status}
                                            data-platform={row.platform}
                                        >
                                            <div className="seeding-ws__social-acct-head">
                                                <span className="seeding-ws__social-acct-platform">
                                                    {row.platform_label || row.platform}
                                                </span>
                                                <span
                                                    className={`seeding-ws__report-status-pill${isLocked ? ' is-pending' : ' is-approved'}`}
                                                    data-status={row.status}
                                                >
                                                    {isLocked ? 'Locked' : 'Active'}
                                                </span>
                                                {row.label ? (
                                                    <span className="seeding-ws__muted seeding-ws__social-acct-label">
                                                        {row.label}
                                                    </span>
                                                ) : null}
                                            </div>

                                            <div className="seeding-ws__social-acct-creds">
                                                <div className="seeding-ws__social-acct-cred-row">
                                                    <span className="seeding-ws__social-acct-cred-label">Login</span>
                                                    <span className="seeding-ws__social-acct-cred-value seeding-ws__mono-cell">
                                                        {row.has_username ? (row.username || '—') : '—'}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        className="seeding-ws__btn seeding-ws__btn--ghost"
                                                        disabled={!row.has_username}
                                                        onClick={() => copyCredential('username', row.id)}
                                                        data-action="copy-username"
                                                    >
                                                        Copy ID
                                                    </button>
                                                </div>
                                                <div className="seeding-ws__social-acct-cred-row">
                                                    <span className="seeding-ws__social-acct-cred-label">Password</span>
                                                    <span
                                                        className="seeding-ws__social-acct-cred-value seeding-ws__mono-cell"
                                                        aria-label="password hidden"
                                                    >
                                                        {row.has_password ? '••••••••' : '—'}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        className="seeding-ws__btn seeding-ws__btn--ghost"
                                                        disabled={!row.has_password}
                                                        onClick={() => copyCredential('password', row.id)}
                                                        data-action="copy-password"
                                                    >
                                                        Copy password
                                                    </button>
                                                </div>
                                            </div>

                                            <div className="seeding-ws__social-acct-actions">
                                                <button
                                                    type="button"
                                                    className="seeding-ws__btn seeding-ws__btn--ghost"
                                                    onClick={() => openEdit(row)}
                                                    data-action="edit"
                                                >
                                                    <Pencil size={14} />
                                                    Edit
                                                </button>
                                                <button
                                                    type="button"
                                                    className="seeding-ws__btn seeding-ws__btn--ghost"
                                                    onClick={() => onLockToggle(row)}
                                                    data-action={isLocked ? 'unlock' : 'lock'}
                                                >
                                                    {isLocked ? <Unlock size={14} /> : <Lock size={14} />}
                                                    {isLocked ? 'Unlock' : 'Lock'}
                                                </button>
                                                <button
                                                    type="button"
                                                    className="seeding-ws__btn seeding-ws__btn--ghost"
                                                    onClick={() => onDelete(row)}
                                                    data-action="delete"
                                                >
                                                    Xóa
                                                </button>
                                            </div>
                                        </article>
                                    );
                                })}
                            </div>
                        </section>
                    ))}
                </div>
            )}

            {formOpen ? (
                <div className="seeding-ws__modal-backdrop" role="presentation" onClick={closeForm}>
                    <div
                        className="seeding-ws__modal"
                        role="dialog"
                        aria-modal="true"
                        aria-label={editingId ? auditT('audit_0e8ff1ac03ec') : auditT('audit_920699a57507')}
                        data-form="social-account"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="seeding-ws__modal-head">
                            <h3>{editingId ? auditT('audit_0e8ff1ac03ec') : auditT('audit_920699a57507')}</h3>
                            <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={closeForm}>
                                <X size={16} />
                            </button>
                        </div>
                        <form className="seeding-ws__social-acct-form" onSubmit={onSave}>
                            <label className="seeding-ws__field">
                                <span>Domain / Site</span>
                                {sites.length > 0 ? (
                                    <select
                                        className="seeding-ws__input"
                                        value={form.site_id}
                                        onChange={(e) => {
                                            const site = sites.find((item) => String(item.id) === e.target.value);
                                            setForm((f) => ({ ...f, site_id: e.target.value, domain: site?.domain || f.domain }));
                                        }}
                                        required={!String(form.domain || '').trim()}
                                    >
                                        <option value="">— Chọn site —</option>
                                        {sites.map((s) => (
                                            <option key={s.id} value={String(s.id)}>{s.domain}</option>
                                        ))}
                                    </select>
                                ) : (
                                    <input
                                        className="seeding-ws__input"
                                        value={form.domain}
                                        onChange={(e) => setForm((f) => ({ ...f, site_id: '', domain: e.target.value }))}
                                        placeholder="example.com"
                                        required
                                    />
                                )}
                            </label>
                            <label className="seeding-ws__field">
                                <span>Platform</span>
                                <select
                                    className="seeding-ws__input"
                                    value={form.platform}
                                    onChange={(e) => setForm((f) => ({ ...f, platform: e.target.value }))}
                                    required
                                >
                                    {(platforms.length > 0 ? platforms : [
                                        { value: 'facebook', label: 'Facebook' },
                                        { value: 'threads', label: 'Threads' },
                                        { value: 'tiktok', label: 'TikTok' },
                                        { value: 'pinterest', label: 'Pinterest' },
                                        { value: 'reddit', label: 'Reddit' },
                                        { value: 'other', label: 'Other' },
                                    ]).map((p) => (
                                        <option key={p.value} value={p.value}>{p.label}</option>
                                    ))}
                                </select>
                            </label>
                            <label className="seeding-ws__field">
                                <span>Label (tuỳ chọn)</span>
                                <input
                                    className="seeding-ws__input"
                                    value={form.label}
                                    onChange={(e) => setForm((f) => ({ ...f, label: e.target.value }))}
                                    maxLength={255}
                                />
                            </label>
                            <label className="seeding-ws__field">
                                <span>Username / Login</span>
                                <input
                                    className="seeding-ws__input"
                                    value={form.username}
                                    onChange={(e) => setForm((f) => ({ ...f, username: e.target.value }))}
                                    autoComplete="off"
                                />
                            </label>
                            <label className="seeding-ws__field">
                                <span>
                                    {editingId
                                        ? 'New password (leave blank to keep current)'
                                        : 'Password'}
                                </span>
                                <input
                                    className="seeding-ws__input"
                                    type="password"
                                    value={form.password}
                                    onChange={(e) => setForm((f) => ({ ...f, password: e.target.value }))}
                                    autoComplete="new-password"
                                    placeholder={editingId ? '••••••••' : ''}
                                />
                            </label>
                            <label className="seeding-ws__field">
                                <span>Status</span>
                                <select
                                    className="seeding-ws__input"
                                    value={form.status}
                                    onChange={(e) => setForm((f) => ({ ...f, status: e.target.value }))}
                                >
                                    <option value="active">Active</option>
                                    <option value="locked">Locked</option>
                                </select>
                            </label>
                            <div className="seeding-ws__social-acct-form-actions">
                                <button
                                    type="button"
                                    className="seeding-ws__btn seeding-ws__btn--ghost"
                                    onClick={closeForm}
                                    disabled={saving}
                                >
                                    Hủy
                                </button>
                                <button
                                    type="submit"
                                    className="seeding-ws__btn seeding-ws__btn--primary"
                                    disabled={saving}
                                >
                                    {saving ? auditT('audit_b98712c006c4') : auditT('audit_a306970e8b81')}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
