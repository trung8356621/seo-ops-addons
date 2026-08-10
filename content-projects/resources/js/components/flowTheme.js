/** Theme classes synced with isDark html class. */
export function buildFlowTheme(isDark) {
  if (isDark) {
    return {
      root: 'bg-slate-900 text-slate-100 border-slate-700',
      header: 'bg-slate-950 border-slate-800',
      title: 'text-white',
      input:
        'bg-slate-900 border-slate-600 text-slate-100 placeholder:text-slate-500 focus:border-emerald-500',
      sidebar: 'bg-slate-950 border-slate-800',
      sidebarTitle: 'text-slate-400',
      widgetBtn:
        'bg-slate-800 hover:bg-slate-700 border-slate-600 text-slate-100 shadow-sm',
      widgetIcon: {
        article: 'text-sky-300 bg-sky-500/25',
        user_input: 'text-orange-300 bg-orange-500/25',
        article_filter: 'text-cyan-300 bg-cyan-500/25',
        prompt: 'text-violet-300 bg-violet-500/25',
        filter: 'text-amber-300 bg-amber-500/25',
        action: 'text-rose-300 bg-rose-500/25',
        end: 'text-rose-300 bg-rose-500/25',
      },
      canvas: 'bg-slate-900',
      panel: 'bg-slate-950 border-slate-800',
      label: 'text-slate-400',
      emptyHint: 'text-slate-500',
      headingAccent: 'text-emerald-400 border-slate-800',
      nodeBg: 'bg-slate-800',
      nodeHeaderBorder: 'border-slate-700',
      nodeTitle: 'text-slate-100',
      nodeBody: 'text-slate-400',
      chipOff: 'seo-flow-chip-off',
      chipOnEmerald: 'seo-flow-chip-active-emerald',
      chipOnSky: 'seo-flow-chip-active-sky',
      btnPrimary: 'seo-flow-btn-primary',
      field: 'bg-slate-900 border-slate-600 text-slate-100 focus:border-emerald-500',
      portBorder: 'border-slate-900',
      portInput: 'bg-slate-800 border-slate-500',
      portDot: 'bg-slate-300',
      portLabel: 'text-slate-300 bg-slate-800 border-slate-700',
      trash: 'text-slate-500 hover:text-rose-400',
      accentEmerald: 'text-emerald-400',
      accentViolet: 'text-violet-300',
      edgeColor: '#64748b',
      gridImage: 'radial-gradient(#334155 1.5px, transparent 1.5px)',
    };
  }

  return {
    root: 'bg-gray-50 text-gray-900 border-gray-300',
    header: 'bg-white border-gray-200',
    title: 'text-gray-900',
    input:
      'bg-white border-gray-300 text-gray-900 placeholder:text-gray-500 focus:border-emerald-600 shadow-sm',
    sidebar: 'bg-gray-100 border-gray-200',
    sidebarTitle: 'text-gray-600',
    widgetBtn:
      'bg-white hover:bg-gray-50 border-gray-300 text-gray-800 shadow-sm',
    widgetIcon: {
      article: 'text-sky-700 bg-sky-100',
      user_input: 'text-orange-700 bg-orange-100',
      article_filter: 'text-cyan-700 bg-cyan-100',
      prompt: 'text-violet-700 bg-violet-100',
      filter: 'text-amber-800 bg-amber-100',
      action: 'text-rose-700 bg-rose-100',
      end: 'text-rose-700 bg-rose-100',
    },
    canvas: 'bg-slate-100',
    panel: 'bg-white border-gray-200',
    label: 'text-gray-600',
    emptyHint: 'text-gray-500',
    headingAccent: 'text-emerald-700 border-gray-200',
    nodeBg: 'bg-white',
    nodeHeaderBorder: 'border-gray-200',
    nodeTitle: 'text-gray-900',
    nodeBody: 'text-gray-600',
    chipOff: 'seo-flow-chip-off',
    chipOnEmerald: 'seo-flow-chip-active-emerald',
    chipOnSky: 'seo-flow-chip-active-sky',
    btnPrimary: 'seo-flow-btn-primary',
    field: 'bg-white border-gray-300 text-gray-900 focus:border-emerald-600 shadow-sm',
    portBorder: 'border-white',
    portInput: 'bg-white border-gray-400',
    portDot: 'bg-gray-500',
    portLabel: 'text-gray-700 bg-gray-100 border-gray-300',
    trash: 'text-gray-500 hover:text-rose-600',
    accentEmerald: 'text-emerald-700',
    accentViolet: 'text-violet-700',
    edgeColor: '#94a3b8',
    gridImage: 'radial-gradient(#cbd5e1 1.5px, transparent 1.5px)',
  };
}

export function nodeBorderClass(type, isSelected, isDark) {
  if (isSelected) {
    if (type === 'article') return 'border-sky-500';
    if (type === 'user_input') return 'border-orange-500';
    if (type === 'article_filter') return 'border-cyan-500';
    if (type === 'prompt') return 'border-violet-500';
    if (type === 'filter') return 'border-amber-500';
    if (type === 'end') return 'border-slate-500';
    if (type === 'action') return 'border-rose-500';
  }
  return isDark ? 'border-slate-600' : 'border-gray-300';
}

export const FLOW_START_NODE_TYPES = ['article', 'user_input'];

export function isFlowStartNode(nodeType) {
  return FLOW_START_NODE_TYPES.includes(nodeType);
}

export function isFlowEndNode(nodeType) {
  return nodeType === 'end';
}

export function startNodeSurfaceClass(nodeType, isDark) {
  if (!isFlowStartNode(nodeType)) {
    return '';
  }

  if (nodeType === 'article') {
    return isDark
      ? 'seo-flow-node--start seo-flow-node--start-article-dark'
      : 'seo-flow-node--start seo-flow-node--start-article';
  }

  return isDark
    ? 'seo-flow-node--start seo-flow-node--start-input-dark'
    : 'seo-flow-node--start seo-flow-node--start-input';
}

export function endNodeSurfaceClass(isDark) {
  return isDark ? 'seo-flow-node--end seo-flow-node--end-dark' : 'seo-flow-node--end';
}

/**
 * Output port label: "Task 1: Name" (avoid duplication).
 */
export function formatTaskPortLabel(task, index) {
  const order = index + 1;
  const prefix = `Task ${order}`;
  const name = (task?.name ?? '').trim();
  const generic = !name || name === 'Task' || name === 'Main task';

  if (generic) {
    return prefix;
  }

  if (/^Task\s+\d+\s*:/i.test(name)) {
    return name;
  }

  if (/^Task\s+\d+$/i.test(name)) {
    return name;
  }

  return `${prefix}: ${name}`;
}

export function getPromptOutputPorts(promptId, prompts, isDark) {
  const prompt = prompts.find((p) => p.id === promptId);
  const violet = isDark ? 'bg-violet-500' : 'bg-violet-600';
  const emerald = isDark ? 'bg-emerald-500' : 'bg-emerald-600';
  if (!prompt) return [{ id: 'out_main', label: 'All', color: emerald }];
  return [
    ...prompt.tasks.map((task, index) => ({
      id: `out_${task.id}`,
      label: formatTaskPortLabel(task, index),
      color: violet,
    })),
    { id: 'out_main', label: 'Total (AI)', color: emerald },
  ];
}

/** Node header height in px. */
export const FLOW_NODE_HEADER_HEIGHT = 49;
export const FLOW_PORT_ROW_HEIGHT = 36;
export const FLOW_NODE_VERTICAL_PADDING = 12;

/** Matches `w-[220px]` in ArticleFlowBuilder. */
export const FLOW_NODE_OUTER_WIDTH = 220;

/** Port offset/size constants. */
export const FLOW_PORT_OFFSET_OUTER = 12;
export const FLOW_PORT_SIZE = 20;

/** Output port center X from node left. */
export function getOutputPortCenterX(nodeX) {
  return nodeX + FLOW_NODE_OUTER_WIDTH + FLOW_PORT_OFFSET_OUTER - FLOW_PORT_SIZE / 2;
}

/** Input port center X from node left. */
export function getInputPortCenterX(nodeX) {
  return nodeX - FLOW_PORT_OFFSET_OUTER + FLOW_PORT_SIZE / 2;
}

/** Input port center Y from node top. */
export function getInputPortCenterY(nodeY, nodeHeight) {
  return nodeY + nodeHeight / 2;
}


export function getPromptNodeHeight(outputPortsCount) {
  const bodyRows = Math.max(1, outputPortsCount);
  return FLOW_NODE_HEADER_HEIGHT + bodyRows * FLOW_PORT_ROW_HEIGHT + FLOW_NODE_VERTICAL_PADDING;
}

export function getArticleFilterOutputPorts(isDark) {
  const sky = isDark ? 'bg-sky-500' : 'bg-sky-600';
  const cyan = isDark ? 'bg-cyan-500' : 'bg-cyan-600';
  const emerald = isDark ? 'bg-emerald-500' : 'bg-emerald-600';

  return [
    { id: 'out_keyword', label: 'Keyword / Tiêu đề', color: sky },
    { id: 'out_gallery_description', label: 'Gallery description', color: cyan },
    { id: 'out_combined', label: 'Cả 2', color: emerald },
  ];
}

export function getArticleFilterNodeHeight(outputPortsCount = 3) {
  const rows = Math.max(3, outputPortsCount);
  return FLOW_NODE_HEADER_HEIGHT + rows * FLOW_PORT_ROW_HEIGHT + FLOW_NODE_VERTICAL_PADDING;
}

export function getArticleNodeHeight() {
  return 140;
}

export function getUserInputOutputPorts(isDark) {
  const orange = isDark ? 'bg-orange-500' : 'bg-orange-600';

  return [{ id: 'out_input', label: '{{input}}', color: orange }];
}

export function getUserInputNodeHeight() {
  return 128;
}

export function getEndNodeHeight() {
  return 108;
}

export function getDefaultNodeHeight(nodeType, outputPortsCount = 1) {
  if (nodeType === 'prompt') return getPromptNodeHeight(outputPortsCount);
  if (nodeType === 'article_filter') return getArticleFilterNodeHeight(outputPortsCount);
  if (nodeType === 'article') return getArticleNodeHeight();
  if (nodeType === 'user_input') return getUserInputNodeHeight();
  if (nodeType === 'end') return getEndNodeHeight();
  return 100;
}

/** Output port top position in px. */
export function getOutputPortTop(nodeType, nodeHeight, outputPortsCount, portIndex) {
  if (nodeType === 'prompt' || nodeType === 'article_filter') {
    const bodyHeight = nodeHeight - FLOW_NODE_HEADER_HEIGHT;
    const step = bodyHeight / (outputPortsCount + 1);
    return FLOW_NODE_HEADER_HEIGHT + step * (portIndex + 1);
  }

  return nodeHeight / 2;
}
