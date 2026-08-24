/** Presentation-only node id — never persisted to workflow. */
export const ARTICLE_CONTEXT_NODE_ID = '__execution_article_context__';

/** @deprecated Use ARTICLE_CONTEXT_NODE_ID */
export const CONTEXT_SELECTION_ID = ARTICLE_CONTEXT_NODE_ID;

const VIRTUAL_NODE_WIDTH = 168;
const VIRTUAL_NODE_GAP = 64;

/**
 * @param {object} params
 * @param {Array} params.nodes
 * @param {Array} params.edges
 * @param {Record<string, object>} params.nodeVisibility
 * @param {boolean} params.showFullWorkflow
 * @param {object} [params.contextSummary]
 * @param {object} [params.labels]
 */
export function projectExecutionHistoryGraph({
  nodes = [],
  edges = [],
  nodeVisibility = {},
  showFullWorkflow = false,
  contextSummary = {},
  labels = {},
}) {
  if (showFullWorkflow) {
    return {
      nodes,
      edges,
      collapsedNodeIds: [],
      virtualEdges: [],
      virtualContextNode: null,
    };
  }

  const collapsedNodeIds = nodes
    .filter((node) => {
      const meta = nodeVisibility[node.id];
      if (!meta?.collapsible) {
        return false;
      }
      return meta.semantic === 'context' || meta.semantic === 'routing';
    })
    .map((node) => node.id);

  const collapsedSet = new Set(collapsedNodeIds);
  if (collapsedSet.size === 0) {
    return {
      nodes,
      edges,
      collapsedNodeIds,
      virtualEdges: [],
      virtualContextNode: null,
    };
  }

  const visibleNodes = nodes.filter((node) => !collapsedSet.has(node.id));
  const visibleIdSet = new Set(visibleNodes.map((node) => node.id));
  const collapsedNodes = nodes.filter((node) => collapsedSet.has(node.id));

  const visibleEdges = edges.filter(
    (edge) => visibleIdSet.has(edge.sourceNode) && visibleIdSet.has(edge.targetNode),
  );

  const rootTargetIds = findRootVisibleTargets(edges, collapsedSet, visibleIdSet);
  const repositioned = repositionVisibleNodes(visibleNodes, nodes, collapsedSet);
  const virtualContextNode = buildVirtualArticleContextNode({
    contextSummary,
    labels,
    visibleNodes: repositioned,
    rootTargetIds,
    collapsedNodes,
  });

  const virtualEdges = rootTargetIds.map((targetId) => ({
    id: `virtual-context-${targetId}`,
    sourceNode: ARTICLE_CONTEXT_NODE_ID,
    sourcePort: 'out_main',
    targetNode: targetId,
    targetPort: 'in_main',
    virtual: true,
  }));

  return {
    nodes: virtualContextNode ? [virtualContextNode, ...repositioned] : repositioned,
    edges: visibleEdges,
    collapsedNodeIds,
    virtualEdges,
    virtualContextNode,
  };
}

function findRootVisibleTargets(edges, collapsedSet, visibleIdSet) {
  const roots = [];
  visibleIdSet.forEach((nodeId) => {
    const hasVisibleIncoming = edges.some(
      (edge) => edge.targetNode === nodeId && visibleIdSet.has(edge.sourceNode),
    );
    if (hasVisibleIncoming) {
      return;
    }
    const hasCollapsedIncoming = edges.some(
      (edge) => edge.targetNode === nodeId && collapsedSet.has(edge.sourceNode),
    );
    if (hasCollapsedIncoming || !edges.some((edge) => edge.targetNode === nodeId)) {
      roots.push(nodeId);
    }
  });

  return [...new Set(roots)];
}

function repositionVisibleNodes(visibleNodes, allNodes, collapsedSet) {
  if (visibleNodes.length === 0) {
    return visibleNodes;
  }

  const collapsedNodes = allNodes.filter((node) => collapsedSet.has(node.id));
  const minVisibleY = Math.min(...visibleNodes.map((node) => Number(node.y ?? 0)));

  const targetTop = 80;
  let shift = Math.max(0, minVisibleY - targetTop);

  if (collapsedNodes.length > 0) {
    const maxCollapsedBottom = Math.max(
      ...collapsedNodes.map((node) => Number(node.y ?? 0) + 120),
    );
    shift = Math.max(shift, minVisibleY - maxCollapsedBottom - 32);
  }

  if (shift <= 0) {
    return visibleNodes.map((node) => ({ ...node }));
  }

  return visibleNodes.map((node) => ({
    ...node,
    y: Number(node.y ?? 0) - shift,
  }));
}

function buildVirtualArticleContextNode({
  contextSummary,
  labels,
  visibleNodes,
  rootTargetIds,
  collapsedNodes,
}) {
  if (rootTargetIds.length === 0 || visibleNodes.length === 0) {
    return null;
  }

  const rootNodes = visibleNodes.filter((node) => rootTargetIds.includes(node.id));
  const anchorNodes = rootNodes.length > 0 ? rootNodes : visibleNodes;

  const minRootX = Math.min(...anchorNodes.map((node) => Number(node.x ?? 0)));
  const rootCenterYs = anchorNodes.map((node) => Number(node.y ?? 0) + 50);
  const avgRootCenterY = rootCenterYs.reduce((sum, y) => sum + y, 0) / rootCenterYs.length;

  let y = avgRootCenterY - 42;
  if (collapsedNodes.length > 0) {
    const collapsedCenterY = collapsedNodes.reduce(
      (sum, node) => sum + Number(node.y ?? 0) + 60,
      0,
    ) / collapsedNodes.length;
    y = (y + collapsedCenterY - 42) / 2;
  }

  const x = Math.max(20, minRootX - VIRTUAL_NODE_WIDTH - VIRTUAL_NODE_GAP);

  return {
    id: ARTICLE_CONTEXT_NODE_ID,
    type: 'execution_article_context',
    title: labels.articleContext ?? labels.currentArticle ?? 'Article context',
    x,
    y: Math.max(20, y),
    data: {
      synthetic: true,
      presentationOnly: true,
      contextSummary,
      subtitle: formatContextSubtitle(contextSummary),
    },
  };
}

export function formatContextSubtitle(contextSummary = {}) {
  return [
    contextSummary.post_type ?? null,
    contextSummary.generation_mode ?? contextSummary.execution_type ?? null,
  ].filter(Boolean).join(' · ');
}

export function formatContextSummaryLine(contextSummary = {}) {
  const parts = [];
  if (contextSummary.title) {
    parts.push(contextSummary.title);
  }
  const meta = [
    contextSummary.article_id ? `#${contextSummary.article_id}` : null,
    contextSummary.post_type ?? null,
    contextSummary.generation_mode ?? contextSummary.execution_type ?? null,
  ].filter(Boolean);
  if (meta.length > 0) {
    parts.push(meta.join(' · '));
  }
  if (contextSummary.domain) {
    parts.push(contextSummary.domain);
  }
  if (contextSummary.keyword) {
    parts.push(`Keyword: ${contextSummary.keyword}`);
  }
  return parts.join(' · ');
}
