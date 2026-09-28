/** Shared Help / editor sticky-header events. */
export const GLOBAL_HELP_OPEN_EVENT = 'seo-global-help:open';
export const GLOBAL_HELP_CLOSE_EVENT = 'seo-global-help:close';

/** Generic drawer aliases. SEO-prefixed events stay the public editor contract. */
export const HELP_DRAWER_OPEN_EVENT = 'help-drawer:open';
export const HELP_DRAWER_CLOSE_EVENT = 'help-drawer:close';
export const HELP_DRAWER_TOGGLE_EVENT = 'help-drawer:toggle';

/** @deprecated Prefer GLOBAL_HELP_OPEN_EVENT — kept for sticky-header / editor bridges. */
export const ARTICLE_EDITOR_HELP_OPEN_EVENT = 'article-editor:help-open';

export const ARTICLE_EDITOR_SAVE_STATUS_EVENT = 'article-editor:save-status';
