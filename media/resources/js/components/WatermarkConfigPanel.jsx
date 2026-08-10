import React, { useEffect, useState } from 'react';
import SeoSelect from '@content-addon/components/SeoSelect.jsx';
import {
    WATERMARK_POSITIONS,
    applyWatermarkBatch,
    fetchWatermarkSettings,
    saveWatermarkSettings,
} from '../utils/watermarkApi';
import { t } from '@content-addon/utils/i18n.js';

export default function WatermarkConfigPanel({ sites = [], defaultSiteId = null, onBatchDone }) {
    const [selectedSiteIds, setSelectedSiteIds] = useState(
        defaultSiteId ? [Number(defaultSiteId)] : [],
    );
    const [configSiteId, setConfigSiteId] = useState(defaultSiteId ? Number(defaultSiteId) : null);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [batching, setBatching] = useState(false);
    const [message, setMessage] = useState(null);

    const [type, setType] = useState('none');
    const [textContent, setTextContent] = useState(t('watermark_copyright_text'));
    const [textColor, setTextColor] = useState('#ffffff');
    const [textSize, setTextSize] = useState(20);
    const [logoWidthPct, setLogoWidthPct] = useState(20);
    const [position, setPosition] = useState('bottom-right');
    const [opacity, setOpacity] = useState(0.7);
    const [logoFile, setLogoFile] = useState(null);
    const [logoPreview, setLogoPreview] = useState(null);

    useEffect(() => {
        if (!configSiteId) return;

        setLoading(true);
        setMessage(null);
        fetchWatermarkSettings(configSiteId)
            .then((settings) => {
                setType(settings.type ?? 'none');
                setTextContent(settings.text_content ?? t('watermark_copyright_text'));
                setTextColor(settings.text_color ?? '#ffffff');
                setTextSize(settings.text_size ?? 20);
                setLogoWidthPct(settings.logo_width_pct ?? 20);
                setPosition(settings.position ?? 'bottom-right');
                setOpacity(settings.opacity ?? 0.7);
                setLogoPreview(settings.logo_url ?? null);
            })
            .catch((err) => setMessage({ type: 'error', text: err.message }))
            .finally(() => setLoading(false));
    }, [configSiteId]);

    const toggleSite = (id) => {
        setSelectedSiteIds((prev) =>
            prev.includes(id) ? prev.filter((s) => s !== id) : [...prev, id],
        );
    };

    const handleSaveSettings = async () => {
        if (!configSiteId) return;

        setSaving(true);
        setMessage(null);
        try {
            const payload = {
                type,
                text_content: textContent,
                text_color: textColor,
                text_size: textSize,
                logo_width_pct: logoWidthPct,
                position,
                opacity,
            };
            const result = await saveWatermarkSettings(configSiteId, payload, logoFile);
            setMessage({ type: 'success', text: result.message ?? t('watermark_config_saved') });
            if (result.settings?.logo_url) {
                setLogoPreview(result.settings.logo_url);
            }
            setLogoFile(null);
        } catch (err) {
            setMessage({ type: 'error', text: err.message });
        } finally {
            setSaving(false);
        }
    };

    const handleBatch = async () => {
        if (!selectedSiteIds.length) return;

        if (
            !window.confirm(
                t('watermark_batch_confirm_overwrite'),
            )
        ) {
            return;
        }

        setBatching(true);
        setMessage(null);
        try {
            const result = await applyWatermarkBatch(selectedSiteIds);
            setMessage({ type: 'success', text: result.message ?? t('watermark_done') });
            onBatchDone?.();
        } catch (err) {
            setMessage({ type: 'error', text: err.message });
        } finally {
            setBatching(false);
        }
    };

    return (
        <div className="seo-watermark-config">
            <header className="seo-watermark-config__header">
                <h2>{t('watermark_config_title')}</h2>
                <p>
                    {t('watermark_config_desc_1')}{' '}
                    <strong>{t('watermark_internal_laravel')}</strong> {t('watermark_config_desc_2')}
                </p>
            </header>

            {message ? (
                <div className={`seo-watermark-config__alert is-${message.type}`}>{message.text}</div>
            ) : null}

            <div className="seo-watermark-config__grid">
                <section className="seo-watermark-config__panel">
                    <h3>{t('watermark_batch_sites')}</h3>
                    <ul className="seo-watermark-config__site-list">
                        {sites.map((site) => (
                            <li key={site.id}>
                                <label>
                                    <input
                                        type="checkbox"
                                        checked={selectedSiteIds.includes(site.id)}
                                        onChange={() => toggleSite(site.id)}
                                    />
                                    {site.domain}
                                </label>
                            </li>
                        ))}
                    </ul>
                    <button
                        type="button"
                        className="seo-watermark-config__btn is-primary"
                        disabled={batching || !selectedSiteIds.length || type === 'none'}
                        onClick={handleBatch}
                    >
                        {batching ? t('watermark_batching') : t('watermark_apply_all_internal')}
                    </button>
                </section>

                <section className="seo-watermark-config__panel">
                    <h3>{t('watermark_default_config')}</h3>
                    <label className="seo-watermark-config__label">{t('watermark_config_site')}</label>
                    <SeoSelect
                        value={configSiteId ?? ''}
                        onChange={(e) => setConfigSiteId(e.target.value ? Number(e.target.value) : null)}
                        placeholder={t('watermark_choose_site')}
                        options={sites.map((site) => ({ value: site.id, label: site.domain }))}
                    />

                    {loading ? <p>{t('watermark_loading_config')}</p> : null}

                    {configSiteId ? (
                        <div className="seo-watermark-config__form">
                            <label className="seo-watermark-config__label">{t('watermark_type')}</label>
                            <SeoSelect
                                value={type}
                                onChange={(e) => setType(e.target.value)}
                                options={[
                                    { value: 'none', label: t('watermark_none') },
                                    { value: 'text', label: t('watermark_text_type') },
                                    { value: 'image', label: t('watermark_logo_type') },
                                ]}
                            />

                            {type === 'text' ? (
                                <>
                                    <label className="seo-watermark-config__label">{t('watermark_text_content')}</label>
                                    <input
                                        type="text"
                                        value={textContent}
                                        onChange={(e) => setTextContent(e.target.value)}
                                        className="seo-watermark-config__input"
                                    />
                                    <div className="seo-watermark-config__row">
                                        <input
                                            type="color"
                                            value={textColor}
                                            onChange={(e) => setTextColor(e.target.value)}
                                        />
                                        <input
                                            type="number"
                                            min={8}
                                            max={120}
                                            value={textSize}
                                            onChange={(e) => setTextSize(parseInt(e.target.value, 10) || 12)}
                                            className="seo-watermark-config__input"
                                        />
                                    </div>
                                </>
                            ) : null}

                            {type === 'image' ? (
                                <>
                                    <label className="seo-watermark-config__label">{t('watermark_logo_file')}</label>
                                    <input
                                        type="file"
                                        accept="image/*"
                                        onChange={(e) => setLogoFile(e.target.files?.[0] ?? null)}
                                    />
                                    {logoPreview ? (
                                        <img src={logoPreview} alt="" className="seo-watermark-config__logo" />
                                    ) : null}
                                    <label className="seo-watermark-config__label">
                                        {t('watermark_logo_width_pct')}: {logoWidthPct}%
                                    </label>
                                    <input
                                        type="range"
                                        min={5}
                                        max={80}
                                        value={logoWidthPct}
                                        onChange={(e) => setLogoWidthPct(parseInt(e.target.value, 10))}
                                    />
                                </>
                            ) : null}

                            {type !== 'none' ? (
                                <>
                                    <label className="seo-watermark-config__label">{t('watermark_position')}</label>
                                    <SeoSelect
                                        value={position}
                                        onChange={(e) => setPosition(e.target.value)}
                                        options={WATERMARK_POSITIONS.map((opt) => ({
                                            value: opt.value,
                                            label: opt.label,
                                        }))}
                                    />
                                    <label className="seo-watermark-config__label">{t('watermark_opacity')}: {opacity}</label>
                                    <input
                                        type="range"
                                        min={0.1}
                                        max={1}
                                        step={0.05}
                                        value={opacity}
                                        onChange={(e) => setOpacity(parseFloat(e.target.value))}
                                    />
                                </>
                            ) : null}

                            <button
                                type="button"
                                className="seo-watermark-config__btn is-primary"
                                disabled={saving}
                                onClick={handleSaveSettings}
                            >
                                {saving ? t('watermark_saving') : t('watermark_save_config')}
                            </button>
                        </div>
                    ) : null}
                </section>
            </div>
        </div>
    );
}
