import React, { useCallback } from 'react';
import { Sparkles } from 'lucide-react';
import { openPanel } from '@content-addon/editor/runtime/editorRuntimeNavigation.js';
import { t } from '@content-addon/utils/i18n.js';

/** FAB: open AI images & videos panel (runtime navigation). */
export default function ArticleAiFloatingLauncher() {
    const openAiImagesVideos = useCallback(() => {
        openPanel('ai-chat', { source: 'ai_fab' });
    }, []);

    return (
        <div className="seo-ai-fab" aria-live="polite">
            <button
                type="button"
                className="seo-ai-fab__trigger"
                title={t('ai_images_videos')}
                aria-label={t('ai_images_videos')}
                onClick={openAiImagesVideos}
            >
                <Sparkles size={22} aria-hidden />
            </button>
        </div>
    );
}
