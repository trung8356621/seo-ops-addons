/**
 * @deprecated Article-editor-only registry moved to helpRegistry.js (group `article-editor`).
 * Kept for import compatibility / legacy topic lookup helpers.
 */
export {
    ARTICLE_EDITOR_HELP_OPEN_EVENT,
    ARTICLE_EDITOR_SAVE_STATUS_EVENT,
} from './helpEvents';

import { findHelpGroup, findHelpTopic } from './helpRegistry';

/**
 * Flat legacy topic list derived from central registry.
 * @returns {Array<{ key: string, title: string, summary: string, steps: string[], video: null, target: object|null }>}
 */
export function getArticleEditorHelpTopics() {
    const group = findHelpGroup('article-editor');
    if (!group) {
        return [];
    }

    return (group.topics || []).map((topic) => {
        const legacyId = topic.id === 'wordpress-sync' ? 'sync-wp' : topic.id;

        return {
            key: `article-editor.${legacyId}`,
            title: topic.title,
            summary: topic.summary || '',
            steps: Array.isArray(topic.steps) ? topic.steps : [],
            video: topic.video ?? null,
            target: topic.target ?? null,
        };
    });
}

/**
 * @param {string|null|undefined} key  e.g. article-editor.overview
 * @returns {object|null}
 */
export function findArticleEditorHelpTopic(key) {
    const normalized = String(key ?? '').trim();
    if (normalized === '') {
        return null;
    }

    return getArticleEditorHelpTopics().find((topic) => topic.key === normalized) ?? null;
}

/** @deprecated Use getArticleEditorHelpTopics() / helpRegistry */
export const ARTICLE_EDITOR_HELP_TOPICS = getArticleEditorHelpTopics();

export { findHelpTopic, findHelpGroup };
