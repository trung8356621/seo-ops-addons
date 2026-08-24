/** Canvas display helpers shared by Task Builder and Execution History. */

export const ARTICLE_SAVE_ACTION = 'save_article';

export function normalizeActionType(actionType) {
  if (
    actionType === 'create_article'
    || actionType === 'edit_article'
    || actionType == null
    || actionType === ''
  ) {
    return ARTICLE_SAVE_ACTION;
  }

  return actionType;
}

export function isArticleSaveAction(actionType) {
  return normalizeActionType(actionType) === ARTICLE_SAVE_ACTION;
}

export function actionTypeCanvasLabel(actionType) {
  if (isArticleSaveAction(actionType)) {
    return 'Tạo / cập nhật bài viết';
  }
  if (actionType === 'post_comment_review') {
    return 'Post comment / review';
  }
  if (actionType === 'save_vocabulary_research') {
    return 'Save vocabulary research';
  }

  return actionType || 'Action';
}

export function getPromptConfig(promptId, prompts) {
  if (promptId == null || promptId === '') {
    return null;
  }

  return prompts.find((p) => String(p.id) === String(promptId)) ?? null;
}

export function isWriteFromOutlinePrompt(promptId, prompts) {
  const config = getPromptConfig(promptId, prompts);
  if (!config) {
    return false;
  }

  if (config.supports_merge_outline_save === true) {
    return true;
  }

  const hook = String(config.hook_key ?? '').trim();
  return hook === 'article.content.generate';
}

export function formatSelection(values, labels = {}) {
  if (!values?.length) return 'All';
  return values.map((v) => labels[v] ?? v).join(', ');
}

const actionLabels = { create: 'Create', update: 'Update' };

export function filterTypeLabel(filterType) {
  if (filterType === 'extract_segment') return 'Extract by tag';
  if (filterType === 'parse_outline') return 'Extract outline';
  if (filterType === 'parse_keywords') return 'Extract keywords';
  if (filterType === 'parse_faq') return 'Extract FAQ';
  if (filterType === 'score_seo') return 'SEO scoring';
  if (filterType === 'custom') return 'Custom filter';
  return 'Filter / Processor';
}

export function articleFilterSummary(nodeData) {
  return {
    actions: formatSelection(nodeData?.actions, actionLabels),
    postTypes: formatSelection(nodeData?.postTypes),
    taxonomies: formatSelection(nodeData?.taxonomies),
  };
}

/** Execution status badge copy for read-only overlay. */
export function executionStatusPresentation(status, statusLabel) {
  const normalized = String(status ?? '').toLowerCase();
  if (normalized === 'completed') return { prefix: '✓', label: statusLabel || 'Completed', tone: 'emerald' };
  if (normalized === 'running') return { prefix: '●', label: statusLabel || 'Running', tone: 'blue' };
  if (normalized === 'failed') return { prefix: '✕', label: statusLabel || 'Failed', tone: 'red' };
  if (normalized === 'skipped') return { prefix: '○', label: statusLabel || 'Skipped', tone: 'slate' };
  if (normalized === 'not_reached') return { prefix: '—', label: statusLabel || 'Not reached', tone: 'slate' };
  return { prefix: '—', label: statusLabel || 'Unknown / Legacy', tone: 'amber' };
}

export const EXECUTION_STATUS_TONE_CLASS = {
  emerald: 'bg-emerald-100 text-emerald-800 border-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-300 dark:border-emerald-500/30',
  blue: 'bg-blue-100 text-blue-800 border-blue-200 dark:bg-blue-500/15 dark:text-blue-300 dark:border-blue-500/30',
  red: 'bg-red-100 text-red-800 border-red-200 dark:bg-red-500/15 dark:text-red-300 dark:border-red-500/30',
  slate: 'bg-slate-100 text-slate-600 border-slate-200 dark:bg-slate-500/15 dark:text-slate-300 dark:border-slate-600',
  amber: 'bg-amber-50 text-amber-800 border-amber-200 dark:bg-amber-500/15 dark:text-amber-300 dark:border-amber-500/30',
};
