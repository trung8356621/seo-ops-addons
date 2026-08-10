import React from 'react';
import { Loader2 } from 'lucide-react';

export default function EditorBusyOverlay({ visible, title, message }) {
    if (!visible) {
        return null;
    }

    return (
        <div className="seo-editor-busy-overlay" role="alertdialog" aria-modal="true" aria-busy="true">
            <div className="seo-editor-busy-card">
                <Loader2 className="seo-editor-busy-spinner" size={36} />
                <p className="seo-editor-busy-title">{title}</p>
                {message ? <p className="seo-editor-busy-message">{message}</p> : null}
            </div>
        </div>
    );
}
