import React, { useState } from 'react';
import MediaEditorTabBar, {
    MEDIA_EDITOR_TAB_ERASER,
    MEDIA_EDITOR_TAB_SPLITTER,
} from './MediaEditorTabBar';
import MagicEraserPanel from './MagicEraserPanel';
import ImageSplitterPanel from './ImageSplitterPanel';

export default function MagicEraserApp({
    imageUrl,
    imageId,
    onSave,
    onClose,
    standalone = false,
    siteId = null,
    articleId = null,
    seoMediaId = null,
    wpAttachmentId = null,
    slug = '',
    initialTab = MEDIA_EDITOR_TAB_ERASER,
    canDeleteOriginal = true,
}) {
    const [activeTab, setActiveTab] = useState(initialTab);
    const [splitPayload, setSplitPayload] = useState(null);

    const rootClass = standalone
        ? 'magic-eraser-root magic-eraser-root--standalone'
        : 'magic-eraser-backdrop';

    const shellClass = standalone ? 'magic-eraser-shell magic-eraser-shell--standalone' : 'magic-eraser-shell';

    const resolvedSeoMediaId = seoMediaId ?? imageId ?? null;
    const resolvedWpAttachmentId = wpAttachmentId ?? 0;

    const handleRequestSplit = ({ pieces }) => {
        if (!pieces?.length) {
            return;
        }

        setSplitPayload({ id: Date.now(), pieces });
        setActiveTab(MEDIA_EDITOR_TAB_SPLITTER);
    };

    return (
        <div className={rootClass}>
            <div className={`${shellClass} media-editor-shell`}>
                <MediaEditorTabBar activeTab={activeTab} onTabChange={setActiveTab} />

                <div className="media-editor-tab-body">
                    {activeTab === MEDIA_EDITOR_TAB_ERASER && (
                        <div
                            className="magic-eraser-tab-panel"
                            role="tabpanel"
                            id="media-editor-panel-eraser"
                            aria-labelledby="media-editor-tab-eraser"
                        >
                            <MagicEraserPanel
                                imageUrl={imageUrl}
                                imageId={imageId}
                                onSave={onSave}
                                onClose={onClose}
                                onRequestSplit={handleRequestSplit}
                            />
                        </div>
                    )}

                    {activeTab === MEDIA_EDITOR_TAB_SPLITTER && (
                        <ImageSplitterPanel
                            siteId={siteId}
                            articleId={articleId}
                            seoMediaId={resolvedSeoMediaId}
                            wpAttachmentId={resolvedWpAttachmentId}
                            slug={slug}
                            imageUrl={imageUrl}
                            splitPayload={splitPayload}
                            canDeleteOriginal={canDeleteOriginal}
                        />
                    )}
                </div>
            </div>
        </div>
    );
}
