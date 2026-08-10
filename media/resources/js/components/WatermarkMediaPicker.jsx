import React from 'react';

/**
 * @param {{ open: boolean, samples: Array<{id: number|string, url: string, slug?: string, source?: string}>, onSelect: (item: object) => void, onClose: () => void }} props
 */
export default function WatermarkMediaPicker({ open, samples = [], onSelect, onClose }) {
    if (!open) {
        return null;
    }

    return (
        <div className="wm-picker-backdrop" role="dialog" aria-modal="true">
            <div className="wm-picker-modal">
                <div className="wm-picker-head">
                    <h3>Chọn ảnh mẫu</h3>
                    <button type="button" className="wm-btn wm-btn--ghost" onClick={onClose}>
                        Đóng
                    </button>
                </div>
                <p className="wm-picker-hint">Ảnh từ thư viện nội bộ (Laravel). Chọn một ảnh để nạp vào canvas.</p>
                <div className="wm-picker-grid">
                    {samples.length === 0 ? (
                        <p className="wm-picker-empty">Chưa có ảnh. Upload ảnh vào tab Nội bộ (Laravel) trước.</p>
                    ) : (
                        samples.map((item) => (
                            <button
                                key={`${item.source}-${item.id}-${item.url}`}
                                type="button"
                                className="wm-picker-item"
                                onClick={() => {
                                    onSelect(item);
                                    onClose();
                                }}
                            >
                                <img src={item.url} alt={item.slug || ''} loading="lazy" />
                                <span>{item.slug || `#${item.id}`}</span>
                            </button>
                        ))
                    )}
                </div>
            </div>
        </div>
    );
}
