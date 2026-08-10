import { AiChatSidebarPanel } from './AiChatSidebarPanel';

/**
 * Editor-owned AI chat (generate image/video into document).
 * Prompt config / AI history / billing remain Laravel shell.
 */
export const aiModule = {
    id: 'article-editor.ai',
    version: 1,
    order: 200,
    dependsOn: ['article-editor.core'],
    optionalDependsOn: ['article-editor.media'],
    isEnabled: () => true,
    sidebar: [
        {
            id: 'sidebar.ai-chat',
            panelId: 'ai-chat',
            labelKey: 'ai_chat',
            label: 'AI',
            fullLabel: 'AI Images & Videos',
            order: 200,
            host: 'editor',
            portalRootKey: 'aiChat',
            slot: 'sidebar.main',
            component: AiChatSidebarPanel,
            // Opened via FAB / insert menu — not a dock chip (parity).
            navChip: false,
            keywords: ['ai', 'chat', 'image', 'video', 'generate'],
        },
    ],
    // insert_image owned by article-editor.media (optionalDependsOn) — do not re-register.
};
