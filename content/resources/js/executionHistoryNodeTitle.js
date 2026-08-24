/**
 * Execution History: append AI call count to prompt node titles, e.g. "Khối Prompt (2)".
 */
export function formatPromptNodeTitleWithAiCallCount(node, execution) {
  const base = String(node?.title ?? '').trim();
  if ((node?.type ?? '') !== 'prompt') {
    return base !== '' ? base : 'Node';
  }
  if (base === '') {
    return 'Prompt';
  }

  const count = Array.isArray(execution?.ai_calls) ? execution.ai_calls.length : 0;
  if (count <= 0) {
    return base;
  }

  if (/\(\d+\)\s*$/.test(base)) {
    return base;
  }

  return `${base} (${count})`;
}

/**
 * @param {Array<{ id?: string, type?: string, title?: string }>} nodes
 * @param {Record<string, { ai_calls?: unknown[] }>} executionByNodeId
 */
export function enrichPromptNodesWithAiCallCounts(nodes, executionByNodeId = {}) {
  if (!Array.isArray(nodes)) {
    return [];
  }

  return nodes.map((node) => {
    if ((node?.type ?? '') !== 'prompt') {
      return node;
    }

    const execution = executionByNodeId[node.id ?? ''] ?? null;
    const title = formatPromptNodeTitleWithAiCallCount(node, execution);
    if (title === node.title) {
      return node;
    }

    return { ...node, title };
  });
}
