/**
 * Mirrors PHP AssignToContentProjectContract event/mode constants.
 * Keep in sync with content-projects/.../AssignToContentProjectContract.php
 */
export const AssignToContentProjectContract = Object.freeze({
    OPEN_EVENT: 'assign-content-project:open',
    SUCCESS_EVENT: 'assign-content-project:success',
    CLOSE_EVENT: 'assign-content-project:close',
    SHELL_OPEN_EVENT: 'assign-content-project:shell-open',
    SHELL_CLOSE_EVENT: 'assign-content-project:shell-close',
    MODE_ARTICLE: 'article',
    MODE_KEYWORD: 'keyword',
    MODE_PENDING_LINK: 'pending_link',
    MODE_VOCABULARY_ITEMS: 'vocabulary_items',
});
