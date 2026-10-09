import React from 'react';
import { Megaphone } from 'lucide-react';
import ArticleAssistantWidget from '@ai-prompt-addon/components/ArticleAssistantWidget.jsx';
import { CtaAutomationPanel } from '../../../components/CtaAutomationPanel';
import { t } from '../../../utils/i18n';

/**
 * @param {{ articleId?: number|null }} props
 */
export function CtaAutomationSidebarPanel({ articleId = null }) {
    return (
        <ArticleAssistantWidget
            widgetId="cta"
            title={t('cta_widget_title')}
            icon={Megaphone}
            defaultCollapsed={false}
            className="seo-assistant-widget--cta"
            helpContextKey="article_editor.panel.cta"
        >
            <CtaAutomationPanel articleId={Number(articleId || 0)} />
        </ArticleAssistantWidget>
    );
}
