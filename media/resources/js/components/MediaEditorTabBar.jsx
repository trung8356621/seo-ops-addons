import React from 'react';
import { Eraser, Grid3x3 } from 'lucide-react';
import { t } from '@content-addon/utils/i18n.js';

export const MEDIA_EDITOR_TAB_ERASER = 'eraser';
export const MEDIA_EDITOR_TAB_SPLITTER = 'splitter';

const TABS = [
    {
        id: MEDIA_EDITOR_TAB_ERASER,
        label: 'Magic Eraser',
        icon: Eraser,
    },
    {
        id: MEDIA_EDITOR_TAB_SPLITTER,
        label: 'Image Splitter',
        icon: Grid3x3,
    },
];

export default function MediaEditorTabBar({ activeTab, onTabChange }) {
    return (
        <div className="media-editor-tabbar" role="tablist" aria-label={t('edit_image')}>
            {TABS.map(({ id, label, icon: Icon }) => {
                const isActive = activeTab === id;

                return (
                    <button
                        key={id}
                        type="button"
                        role="tab"
                        id={`media-editor-tab-${id}`}
                        aria-selected={isActive}
                        aria-controls={`media-editor-panel-${id}`}
                        className={`media-editor-tab${isActive ? ' is-active' : ''}`}
                        onClick={() => onTabChange(id)}
                    >
                        <Icon size={16} strokeWidth={2} aria-hidden />
                        <span>{label}</span>
                    </button>
                );
            })}
        </div>
    );
}
