import React, { useState, useRef, useEffect } from 'react';
import SeoSelect from '@content-addon/components/SeoSelect.jsx';
import {
  buildFlowTheme,
  getArticleFilterOutputPorts,
  getDefaultNodeHeight,
  getInputPortCenterX,
  getInputPortCenterY,
  getOutputPortCenterX,
  getOutputPortTop,
  getPromptOutputPorts,
  getUserInputOutputPorts,
  endNodeSurfaceClass,
  isFlowEndNode,
  isFlowStartNode,
  nodeBorderClass,
  startNodeSurfaceClass,
} from './flowTheme';

// Bộ Icon
const Icons = {
  Article: () => <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10l6 6v10a2 2 0 01-2 2z" /><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14 4v5h5M10 12h4m-4 4h4" /></svg>,
  Input: () => <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h10M4 18h7" /></svg>,
  Prompt: () => <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>,
  Filter: () => <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" /></svg>,
  Play: () => <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" /><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>,
  Lightning: () => <svg className="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.381z" clipRule="evenodd" /></svg>,
  Trash: () => <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>,
  ZoomIn: () => <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="m21 21-4.35-4.35m1.35-5.65a7 7 0 1 1-14 0 7 7 0 0 1 14 0ZM11 8v6m-3-3h6" /></svg>,
  ZoomOut: () => <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="m21 21-4.35-4.35m1.35-5.65a7 7 0 1 1-14 0 7 7 0 0 1 14 0Zm-13 0h6" /></svg>,
  ArrowLeft: () => <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>,
  End: () => <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 12h18M15 6l6 6-6 6" /></svg>,
};

const MIN_ZOOM = 0.5;
const MAX_ZOOM = 1.5;
const ZOOM_STEP = 0.1;

const defaultMockPrompts = [
  { id: 'p1', name: 'Outline & Entity JSON', tasks: [{id: 'task_1', name: 'Outline H1,H2'}, {id: 'task_2', name: 'JSON data'}] },
  { id: 'p2', name: 'Write detailed body', tasks: [{id: 'task_1', name: 'Write content'}] },
  { id: 'p3', name: 'Analyze & optimize old article', tasks: [{id: 'task_1', name: 'SEO scoring'}, {id: 'task_2', name: 'Rewrite Title/Meta'}, {id: 'task_3', name: 'Suggest internal links'}] }
];

const mockPrompts =
  typeof window !== 'undefined' && Array.isArray(window.__SEO_PROMPTS__) && window.__SEO_PROMPTS__.length > 0
    ? window.__SEO_PROMPTS__
    : defaultMockPrompts;

const mockPostTypes = ['post', 'page', 'product', 'news'];
const mockTaxonomies = ['category', 'post_tag', 'product_cat', 'brand'];
const mockActions = [
  { id: 'create', label: 'Create' },
  { id: 'update', label: 'Update' },
];

const actionLabels = Object.fromEntries(mockActions.map((a) => [a.id, a.label]));

function getPromptConfig(promptId) {
  if (promptId == null || promptId === '') {
    return null;
  }

  return mockPrompts.find((p) => String(p.id) === String(promptId)) ?? null;
}

function getPromptTags(promptId) {
  const config = getPromptConfig(promptId);
  return Array.isArray(config?.detected_tags) ? config.detected_tags : [];
}

function isWriteFromOutlinePrompt(promptId) {
  const config = getPromptConfig(promptId);
  if (!config) {
    return false;
  }

  if (config.supports_merge_outline_save === true) {
    return true;
  }

  const hook = String(config.hook_key ?? '').trim();
  return hook === 'article.content.generate';
}

function defaultWorkflowRoleOptions() {
  return [
    { value: '', label: 'Không gán vai trò' },
    { value: 'article.outline.generate', label: 'Tạo dàn ý' },
    { value: 'article.content.generate', label: 'Viết bài' },
    { value: 'article.content.improve', label: 'Cải thiện bài viết' },
    { value: 'article.image.generate', label: 'Tạo hình ảnh' },
  ];
}

function getWorkflowRoleOptions() {
  const fromWindow = typeof window !== 'undefined' ? window.__SEO_WORKFLOW_ROLES__ : null;
  if (Array.isArray(fromWindow) && fromWindow.length > 0) {
    return fromWindow.map((row) => ({
      value: String(row?.value ?? ''),
      label: String(row?.label ?? row?.value ?? ''),
    }));
  }

  return defaultWorkflowRoleOptions();
}

function suggestExecutionRoleFromPrompt(promptId) {
  const config = getPromptConfig(promptId);
  const hook = String(config?.hook_key ?? '').trim().split('@')[0];
  const allowed = new Set(
    getWorkflowRoleOptions()
      .map((row) => String(row.value ?? '').trim())
      .filter((value) => value !== ''),
  );

  if (hook !== '' && allowed.has(hook)) {
    return hook;
  }

  // Image hooks share article.image.generate role.
  if (
    hook === 'article.featured_image.generate'
    || hook === 'product.gallery.generate'
  ) {
    return allowed.has('article.image.generate') ? 'article.image.generate' : '';
  }

  if (hook === 'article.content.rewrite') {
    return allowed.has('article.content.generate') ? 'article.content.generate' : '';
  }

  return '';
}

function defaultPromptNodeData(promptId) {
  const config = getPromptConfig(promptId) ?? mockPrompts[0];

  return {
    promptId: config?.id ?? 'p1',
    outline_prompt_id: '',
    vocabulary_prompt_id: '',
    mergeOutlineToSave: false,
    execution_role: suggestExecutionRoleFromPrompt(config?.id ?? promptId),
  };
}

function normalizeArticleNodeData(data = {}) {
  return {};
}

function normalizeArticleFilterNodeData(data = {}) {
  const actions = Array.isArray(data.actions) ? data.actions : (data.action ? [data.action] : []);

  return {
    postTypes: Array.isArray(data.postTypes) ? data.postTypes : [],
    taxonomies: Array.isArray(data.taxonomies) ? data.taxonomies : [],
    actions: actions.filter((action) => action !== 'add_comment_review'),
  };
}

function migrateArticleActionsToFilters(nodes, edges) {
  const nodeById = Object.fromEntries(nodes.map((node) => [node.id, node]));

  nodes.forEach((node) => {
    if (node.type !== 'article') {
      return;
    }

    const legacyActions = Array.isArray(node.data?.actions)
      ? node.data.actions
      : (node.data?.action ? [node.data.action] : []);
    const actions = legacyActions.filter((action) => action !== 'add_comment_review');
    if (actions.length === 0) {
      node.data = normalizeArticleNodeData(node.data);
      return;
    }

    const targetFilterIds = new Set();
    const autoFilterId = `${node.id}_article_filter`;
    if (nodeById[autoFilterId]?.type === 'article_filter') {
      targetFilterIds.add(autoFilterId);
    }

    edges.forEach((edge) => {
      if (edge.sourceNode !== node.id) {
        return;
      }

      const target = nodeById[edge.targetNode];
      if (target?.type === 'article_filter') {
        targetFilterIds.add(target.id);
      }
    });

    targetFilterIds.forEach((filterId) => {
      const filterNode = nodeById[filterId];
      if (!filterNode || filterNode.type !== 'article_filter') {
        return;
      }

      const currentActions = Array.isArray(filterNode.data?.actions) ? filterNode.data.actions : [];
      if (currentActions.length === 0) {
        filterNode.data = {
          ...filterNode.data,
          actions: [...actions],
        };
      }
    });

    node.data = normalizeArticleNodeData(node.data);
  });
}

const ARTICLE_SAVE_ACTION = 'save_article';

function normalizeActionType(actionType) {
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

function isArticleSaveAction(actionType) {
  return normalizeActionType(actionType) === ARTICLE_SAVE_ACTION;
}

function actionTypeCanvasLabel(actionType) {
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

function migrateLegacyFlowNode(node) {
  if (node.type !== 'end') {
    return node;
  }

  const hasLegacyAction = Boolean(node.data?.actionType) || node.title === 'Save / End';
  if (!hasLegacyAction) {
    return {
      ...node,
      title: node.title === 'Save / End' ? 'End' : (node.title || 'End'),
      data: { symbolic: true, ...(node.data ?? {}) },
    };
  }

  return {
    ...node,
    type: 'action',
    title: node.title === 'Save / End' ? 'Action' : node.title,
    data: {
      actionType: normalizeActionType(node.data?.actionType),
      isTrigger: Boolean(node.data?.isTrigger),
    },
  };
}

function normalizeNodes(nodes) {
  return (nodes ?? []).map((node) => {
    const migrated = migrateLegacyFlowNode(node);
    let next = migrated.type === 'article'
      ? { ...migrated, data: normalizeArticleNodeData(migrated.data) }
      : migrated;

    if (next.type === 'article_filter') {
      next = { ...next, data: normalizeArticleFilterNodeData(next.data) };
    }

    if (next.type === 'prompt') {
      const roleRaw = next.data?.execution_role;
      const promptData = {
        ...next.data,
        mergeOutlineToSave: Boolean(next.data?.mergeOutlineToSave),
        execution_role: roleRaw == null ? '' : String(roleRaw),
      };
      // Model routing thống nhất AI Advanced / PromptRunner — không lưu aiModel trên node.
      delete promptData.aiModel;

      if (!isWriteFromOutlinePrompt(promptData.promptId)) {
        promptData.mergeOutlineToSave = false;
      }

      next = {
        ...next,
        data: promptData,
      };
    }

    if (next.type === 'action') {
      next = {
        ...next,
        data: {
          ...next.data,
          actionType: normalizeActionType(next.data?.actionType),
        },
      };
    }

    if (next.type === 'end') {
      next = {
        ...next,
        title: next.title || 'End',
        data: { symbolic: true, ...(next.data ?? {}) },
      };
    }

    return next;
  });
}

export function normalizeFlowData(initialData = {}) {
  const providedNodes = Array.isArray(initialData.nodes) ? initialData.nodes : [];
  const sourceNodes = providedNodes.length > 0
    ? providedNodes
    : [{
      id: 'n1',
      type: 'article',
      title: 'Article (Input)',
      x: 50,
      y: 150,
      data: {},
    }];
  let edges = Array.isArray(initialData.edges) ? [...initialData.edges] : [];
  const nodes = [];

  edges = edges.map((edge) => {
    const sourceNode = sourceNodes.find((candidate) => candidate.id === edge.sourceNode);
    const targetNode = sourceNodes.find((candidate) => candidate.id === edge.targetNode);

    if (sourceNode?.type === 'article_filter') {
      const sourcePort = edge.sourcePort ?? 'out_main';
      if (sourcePort === 'out_main') {
        return { ...edge, sourcePort: 'out_keyword' };
      }

      if (sourcePort === 'out_description') {
        return { ...edge, sourcePort: 'out_gallery_description' };
      }
    }

    if (sourceNode?.type !== 'article') {
      return edge;
    }

    const sourcePort = edge.sourcePort ?? 'out_main';

    if (targetNode?.type === 'article_filter') {
      if (sourcePort === 'out_description') {
        return { ...edge, sourcePort: 'out_gallery_description' };
      }

      return { ...edge, sourcePort: 'out_main' };
    }

    const filterId = `${sourceNode.id}_article_filter`;
    const hasFilterNode = sourceNodes.some((candidate) => candidate.id === filterId && candidate.type === 'article_filter');

    if (hasFilterNode && edge.targetNode !== filterId) {
      const mappedPort = sourcePort === 'out_description'
        ? 'out_gallery_description'
        : 'out_keyword';

      return { ...edge, sourceNode: filterId, sourcePort: mappedPort };
    }

    if (sourcePort === 'out_main' || sourcePort === 'out_keyword') {
      return { ...edge, sourcePort: 'out_main' };
    }

    if (sourcePort === 'out_description') {
      return { ...edge, sourcePort: 'out_gallery_description' };
    }

    return edge;
  });

  sourceNodes.forEach((node) => {
    const legacyPostTypes = Array.isArray(node.data?.postTypes) ? node.data.postTypes : [];
    const legacyTaxonomies = Array.isArray(node.data?.taxonomies) ? node.data.taxonomies : [];
    const legacyActions = Array.isArray(node.data?.actions)
      ? node.data.actions
      : (node.data?.action ? [node.data.action] : []);

    nodes.push(node);

    if (node.type !== 'article') {
      return;
    }

    const filterId = `${node.id}_article_filter`;
    const hasConnectedFilter = edges.some((edge) => {
      if (edge.sourceNode !== node.id) {
        return false;
      }

      return sourceNodes.some(
        (candidate) => candidate.id === edge.targetNode && candidate.type === 'article_filter',
      );
    });
    if (hasConnectedFilter || sourceNodes.some((candidate) => candidate.id === filterId)) {
      return;
    }

    const outgoing = edges.filter((edge) => edge.sourceNode === node.id);
    edges = edges
      .filter((edge) => edge.sourceNode !== node.id)
      .concat(outgoing.map((edge) => {
        const sourcePort = edge.sourcePort === 'out_main' ? 'out_keyword' : edge.sourcePort;

        return { ...edge, sourceNode: filterId, sourcePort };
      }))
      .concat([{
        id: `edge_${node.id}_article_filter`,
        sourceNode: node.id,
        sourcePort: 'out_main',
        targetNode: filterId,
        targetPort: 'in_main',
      }]);

    nodes.push({
      id: filterId,
      type: 'article_filter',
      title: 'Lọc bài viết',
      x: Number(node.x ?? 50) + 260,
      y: Number(node.y ?? 150),
      data: normalizeArticleFilterNodeData({
        postTypes: legacyPostTypes,
        taxonomies: legacyTaxonomies,
        actions: legacyActions,
      }),
    });
  });

  migrateArticleActionsToFilters(nodes, edges);

  return {
    nodes: normalizeNodes(nodes),
    edges,
  };
}

function formatSelection(values, labels = {}) {
  if (!values?.length) return 'All';
  return values.map((v) => labels[v] ?? v).join(', ');
}

function useDarkMode() {
  const [isDark, setIsDark] = useState(
    typeof document !== 'undefined' ? document.documentElement.classList.contains('dark') : true,
  );

  useEffect(() => {
    const observer = new MutationObserver(() => {
      setIsDark(document.documentElement.classList.contains('dark'));
    });
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    return () => observer.disconnect();
  }, []);

  return isDark;
}

export default function ArticleFlowBuilder({
  initialData,
  onSave,
  saving = false,
  taskName,
  setTaskName,
  backUrl = '',
  backLabel = 'Back',
}) {
  const isDark = useDarkMode();
  const t = buildFlowTheme(isDark);
  const initialFlowRef = useRef(null);

  if (initialFlowRef.current === null) {
    initialFlowRef.current = normalizeFlowData(initialData);
  }

  const [nodes, setNodes] = useState(() =>
    initialFlowRef.current.nodes,
  );
  const [edges, setEdges] = useState(initialFlowRef.current.edges);
  const [selectedNodeId, setSelectedNodeId] = useState('n1');
  
  const [isDragging, setIsDragging] = useState(false);
  const [draggedNodeId, setDraggedNodeId] = useState(null);
  const [dragOffset, setDragOffset] = useState({ x: 0, y: 0 });
  const [connecting, setConnecting] = useState(null);
  const [zoom, setZoom] = useState(1);

  const canvasRef = useRef(null);

  const addNode = (type) => {
    const id = `node_${Date.now()}`;
    let title = '', data = {};
    if (type === 'article') {
      title = 'Article';
      data = {};
    }
    else if (type === 'user_input') {
      title = 'Input ({{input}})';
      data = {};
    }
    else if (type === 'article_filter') {
      title = 'Lọc bài viết';
      data = { postTypes: [], taxonomies: [], actions: [] };
    }
    else if (type === 'prompt') {
      title = 'Prompt block';
      data = defaultPromptNodeData(mockPrompts[0]?.id ?? 'p1');
    }
    else if (type === 'filter') {
      title = 'Filter / Process';
      data = {
        filterType: 'extract_segment',
        rule: '',
        inputSource: 'auto',
        filterTag: '',
        customTag: '',
      };
    }
    else if (type === 'action') {
      title = 'Action';
      data = { actionType: ARTICLE_SAVE_ACTION, isTrigger: false };
    }
    else if (type === 'end') {
      title = 'End';
      data = { symbolic: true };
    }
    setNodes([...nodes, { id, type, title, x: 100, y: 100, data }]);
    setSelectedNodeId(id);
  };

  const deleteNode = (id) => {
    setNodes(nodes.filter(n => n.id !== id));
    setEdges(edges.filter(e => e.sourceNode !== id && e.targetNode !== id));
    if (selectedNodeId === id) setSelectedNodeId(null);
  };

  const updateNodeData = (nodeId, key, value) => {
    setNodes(nodes.map(node => node.id === nodeId ? { ...node, data: { ...node.data, [key]: value } } : node));
  };

  const updateNodeDataFields = (nodeId, patch) => {
    setNodes(nodes.map((node) => (node.id === nodeId ? { ...node, data: { ...node.data, ...patch } } : node)));
  };

  const handleMouseDown = (nodeId, e) => {
    e.stopPropagation(); setDraggedNodeId(nodeId); setIsDragging(true); setSelectedNodeId(nodeId);
    const canvasRect = canvasRef.current?.getBoundingClientRect();
    const node = nodes.find((candidate) => candidate.id === nodeId);
    if (!canvasRect || !node) return;

    setDragOffset({
      x: (e.clientX - canvasRect.left) / zoom - node.x,
      y: (e.clientY - canvasRect.top) / zoom - node.y,
    });
  };

  const handleMouseMove = (e) => {
    if (!isDragging || !draggedNodeId || !canvasRef.current) return;
    const canvasRect = canvasRef.current.getBoundingClientRect();
    const nx = Math.max(10, (e.clientX - canvasRect.left) / zoom - dragOffset.x);
    const ny = Math.max(10, (e.clientY - canvasRect.top) / zoom - dragOffset.y);
    setNodes((prev) =>
      prev.map((n) => (n.id === draggedNodeId ? { ...n, x: nx, y: ny } : n)),
    );
  };

  const handleMouseUp = () => { setIsDragging(false); setDraggedNodeId(null); };

  const changeZoom = (amount) => {
    setZoom((current) => Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, Number((current + amount).toFixed(2)))));
  };

  const handlePortClick = (nodeId, portId, type, e) => {
    e.stopPropagation();
    if (type === 'output') setConnecting({ nodeId, portId, type });
    else if (type === 'input' && connecting && connecting.type === 'output') {
      if (connecting.nodeId !== nodeId && !edges.some(e => e.sourceNode === connecting.nodeId && e.sourcePort === connecting.portId && e.targetNode === nodeId)) {
        setEdges([...edges, { id: `edge_${Date.now()}`, sourceNode: connecting.nodeId, sourcePort: connecting.portId, targetNode: nodeId, targetPort: portId }]);
      }
      setConnecting(null);
    }
  };

  const selectedNode = nodes.find(n => n.id === selectedNodeId);
  const incomingEdges = selectedNode
    ? edges.filter((edge) => edge.targetNode === selectedNode.id)
    : [];
  const sourcePromptTags = selectedNode?.type === 'filter'
    ? incomingEdges
      .map((edge) => nodes.find((node) => node.id === edge.sourceNode))
      .filter((node) => node?.type === 'prompt')
      .flatMap((node) => getPromptTags(node.data?.promptId))
    : [];
  const uniqueSourcePromptTags = sourcePromptTags.filter((tag, index, arr) => {
    const key = String(tag?.key || '').trim();
    if (!key) return false;
    return arr.findIndex((candidate) => String(candidate?.key || '').trim() === key) === index;
  });
  const chipClass = (active) => (active ? t.chipOnSky : t.chipOff);
  const actionChipClass = (active) => (active ? t.chipOnEmerald : t.chipOff);

  return (
    <div
      className={`seo-flow-builder flex h-full w-full flex-col overflow-hidden font-sans transition-colors duration-200 ${t.root}`}
      data-theme={isDark ? 'dark' : 'light'}
      style={{ colorScheme: isDark ? 'dark' : 'light' }}
    >
      {/* HEADER */}
      <div className={`px-6 py-4 border-b flex items-center justify-between transition-colors duration-200 ${t.header}`}>
        <div className="flex items-center gap-4 min-w-0">
            {backUrl ? (
              <a
                href={backUrl}
                className={`inline-flex shrink-0 items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-semibold transition-colors ${t.widgetBtn}`}
              >
                <Icons.ArrowLeft />
                <span>{backLabel}</span>
              </a>
            ) : null}
            <h1 className={`text-lg font-bold flex items-center gap-2 ${t.title}`}><Icons.Filter /> SEO Flow</h1>
            <input 
              type="text" 
              value={taskName} 
              onChange={(e) => setTaskName(e.target.value)} 
              className={`rounded px-3 py-1.5 text-sm w-64 transition-colors duration-200 focus:outline-none border ${t.input}`}
              placeholder="Workflow name..." 
            />
        </div>
        <button
          type="button"
          disabled={saving}
          onClick={() => onSave(taskName, JSON.stringify({ nodes, edges }))}
          className={`${t.btnPrimary} px-4 py-2 rounded-lg text-sm font-bold transition-colors shadow-sm disabled:cursor-wait disabled:opacity-70`}
        >
          {saving ? 'Đang lưu...' : 'Lưu Sơ Đồ Quy Trình'}
        </button>
      </div>

      <div className="flex flex-1 overflow-hidden">
        
        {/* SIDEBAR TOOLS */}
        <div className={`w-60 border-r p-4 flex flex-col gap-3 transition-colors duration-200 ${t.sidebar}`}>
          <h3 className={`text-xs font-bold uppercase tracking-wider mb-2 ${t.sidebarTitle}`}>Thêm Widget</h3>
          {[
            { type: 'article', label: 'Article (Input)', icon: <Icons.Article /> },
            { type: 'user_input', label: 'Input ({{input}})', icon: <Icons.Input /> },
            { type: 'article_filter', label: 'Lọc bài viết', icon: <Icons.Filter /> },
            { type: 'prompt', label: 'AI Prompt block', icon: <Icons.Prompt /> },
            { type: 'filter', label: 'Filter block', icon: <Icons.Filter /> },
            {
              type: 'action',
              label: 'Action',
              icon: <Icons.Play />,
              iconClass: 'text-rose-600 dark:text-rose-400 bg-rose-100 dark:bg-rose-500/10',
            },
            {
              type: 'end',
              label: 'End',
              icon: <Icons.End />,
              iconClass: 'text-slate-600 dark:text-slate-300 bg-slate-200 dark:bg-slate-500/20',
            },
          ].map(tool => (
            <button key={tool.type} onClick={() => addNode(tool.type)} className={`flex items-center gap-3 p-3 rounded-lg border text-left transition-all ${t.widgetBtn}`}>
              <div className={`p-1.5 rounded-md ${tool.iconClass ?? t.widgetIcon[tool.type]}`}>{tool.icon}</div>
              <span className="text-sm font-semibold">{tool.label}</span>
            </button>
          ))}
        </div>

        {/* CANVAS */}
        <div 
          ref={canvasRef} 
          onMouseMove={handleMouseMove} 
          onMouseUp={handleMouseUp} 
          onClick={() => setConnecting(null)} 
          onWheel={(e) => {
            if (!e.ctrlKey && !e.metaKey) return;
            e.preventDefault();
            changeZoom(e.deltaY < 0 ? ZOOM_STEP : -ZOOM_STEP);
          }}
          className={`flex-1 relative overflow-hidden select-none transition-colors duration-200 ${t.canvas}`}
          style={{
            backgroundImage: t.gridImage,
            backgroundSize: `${24 * zoom}px ${24 * zoom}px`,
          }}
        >
          <div
            className="absolute left-0 top-0 origin-top-left"
            style={{
              width: `${100 / zoom}%`,
              height: `${100 / zoom}%`,
              transform: `scale(${zoom})`,
            }}
          >
            {/* Edges */}
            <svg className="absolute inset-0 pointer-events-none w-full h-full z-0">
            {edges.map(edge => {
              const srcNode = nodes.find(n => n.id === edge.sourceNode);
              const tgtNode = nodes.find(n => n.id === edge.targetNode);
              if (!srcNode || !tgtNode) return null;
              const srcPorts = srcNode.type === 'prompt'
                ? getPromptOutputPorts(srcNode.data.promptId, mockPrompts, isDark)
                : srcNode.type === 'article_filter'
                  ? getArticleFilterOutputPorts(isDark)
                  : srcNode.type === 'user_input'
                    ? getUserInputOutputPorts(isDark)
                    : [{ id: 'out_main' }];
              const tgtOutPorts = tgtNode.type === 'prompt' ? getPromptOutputPorts(tgtNode.data.promptId, mockPrompts, isDark) : [{ id: 'out_main' }];
              const srcPortIndex = srcPorts.findIndex(p => p.id === edge.sourcePort);
              const srcNodeHeight = getDefaultNodeHeight(srcNode.type, srcPorts.length);
              const tgtNodeHeight = getDefaultNodeHeight(tgtNode.type, tgtOutPorts.length);
              const startX = getOutputPortCenterX(srcNode.x);
              const startY = srcNode.y + getOutputPortTop(srcNode.type, srcNodeHeight, srcPorts.length, Math.max(0, srcPortIndex));
              const endX = getInputPortCenterX(tgtNode.x);
              const endY = getInputPortCenterY(tgtNode.y, tgtNodeHeight);
              const cpOffset = Math.abs(endX - startX) * 0.5;
              const d = `M ${startX} ${startY} C ${startX + cpOffset} ${startY}, ${endX - cpOffset} ${endY}, ${endX} ${endY}`;
              
              const edgeColor = t.edgeColor;
              
              return (
                <g key={edge.id} className="pointer-events-auto cursor-pointer group">
                  <path d={d} fill="none" stroke={edgeColor} strokeWidth="3" className="hover:stroke-rose-500 transition-colors" onClick={(e) => { e.stopPropagation(); setEdges(edges.filter(x => x.id !== edge.id)); }} />
                  <circle cx={(startX + endX)/2} cy={(startY + endY)/2} r="4" fill={edgeColor} />
                </g>
              );
            })}
            
            {connecting && (() => {
              const srcNode = nodes.find(n => n.id === connecting.nodeId);
              if (!srcNode) return null;
              const connectPorts = srcNode.type === 'prompt'
                ? getPromptOutputPorts(srcNode.data.promptId, mockPrompts, isDark)
                : srcNode.type === 'article_filter'
                  ? getArticleFilterOutputPorts(isDark)
                  : srcNode.type === 'user_input'
                    ? getUserInputOutputPorts(isDark)
                    : [{ id: 'out_main' }];
              const connectHeight = getDefaultNodeHeight(srcNode.type, connectPorts.length);
              const connectIndex = connectPorts.findIndex((p) => p.id === connecting.portId);
              const ix = Math.max(0, connectIndex);
              const startY = srcNode.y + getOutputPortTop(srcNode.type, connectHeight, connectPorts.length, ix);
              const startX = getOutputPortCenterX(srcNode.x);
              return <line x1={startX} y1={startY} x2={startX + 48} y2={startY} stroke="#f59e0b" strokeWidth="2" strokeDasharray="4" />;
            })()}
            </svg>

            {/* Nodes */}
            {nodes.map(node => {
            const isSelected = node.id === selectedNodeId;
            const nodeClass = [
              'absolute w-[220px] rounded-xl border shadow-lg cursor-grab z-10 flex flex-col transition-colors duration-200',
              t.nodeBg,
              nodeBorderClass(node.type, isSelected, isDark),
              startNodeSurfaceClass(node.type, isDark),
              isFlowEndNode(node.type) ? endNodeSurfaceClass(isDark) : '',
            ].filter(Boolean).join(' ');
            const outputPorts = isFlowEndNode(node.type)
              ? []
              : node.type === 'prompt'
              ? getPromptOutputPorts(node.data.promptId, mockPrompts, isDark)
              : node.type === 'article_filter'
                ? getArticleFilterOutputPorts(isDark)
                : node.type === 'user_input'
                  ? getUserInputOutputPorts(isDark)
                  : [{ id: 'out_main', label: 'Connect', color: isDark ? 'bg-slate-500' : 'bg-gray-500' }];
            const nodeHeight = getDefaultNodeHeight(node.type, outputPorts.length);

            return (
              <div key={node.id} onMouseDown={(e) => handleMouseDown(node.id, e)} className={nodeClass} style={{ left: node.x, top: node.y, height: nodeHeight }}>
                
                {!isFlowStartNode(node.type) && (
                  <div onClick={(e) => handlePortClick(node.id, 'in_main', 'input', e)} className={`absolute -left-3 top-1/2 -translate-y-1/2 w-5 h-5 border-2 rounded-full cursor-pointer hover:bg-emerald-500 hover:border-emerald-500 flex items-center justify-center z-20 ${t.portInput}`}><div className={`w-1.5 h-1.5 rounded-full ${t.portDot}`}></div></div>
                )}
                
                <div className={`p-3 flex items-center justify-between border-b ${t.nodeHeaderBorder}`}>
                  <div className={`flex items-center gap-2 font-bold text-sm min-w-0 ${t.nodeTitle}`}>
                    {node.type === 'article' && <Icons.Article />}
                    {node.type === 'user_input' && <Icons.Input />}
                    {node.type === 'article_filter' && <Icons.Filter />}
                    {node.type === 'prompt' && <Icons.Prompt />}
                    {node.type === 'filter' && <Icons.Filter />}
                    {node.type === 'action' && <Icons.Play />}
                    {node.type === 'end' && <Icons.End />}
                    <span className="truncate">{node.title}</span>
                    {isFlowStartNode(node.type) ? (
                      <span className="seo-flow-node__badge seo-flow-node__badge--start shrink-0">Start</span>
                    ) : null}
                    {isFlowEndNode(node.type) ? (
                      <span className="seo-flow-node__badge seo-flow-node__badge--end shrink-0">End</span>
                    ) : null}
                  </div>
                  <button onClick={(e) => { e.stopPropagation(); deleteNode(node.id); }} className={t.trash}><Icons.Trash /></button>
                </div>
                
                <div className={`p-3 text-xs flex flex-col justify-center ${t.nodeBody}`}>
                  {node.type === 'article' && (
                    <div className="space-y-1">
                      <span className={t.emptyHint}>Input bài viết</span>
                    </div>
                  )}
                  {node.type === 'user_input' && (
                    <div className="space-y-1">
                      <span className={`font-semibold ${isDark ? 'text-orange-300' : 'text-orange-700'}`}>{'{{input}}'}</span>
                      <span className={t.emptyHint}>Từ panel AI ảnh &amp; video trên editor</span>
                    </div>
                  )}
                  {node.type === 'article_filter' && (
                    <div className="space-y-1">
                      <span>Hành động: <span className={`font-semibold ${t.accentEmerald}`}>{formatSelection(node.data.actions, actionLabels)}</span></span><br/>
                      <span>Loại: {formatSelection(node.data.postTypes)}</span><br/>
                      <span>Tax: {formatSelection(node.data.taxonomies)}</span>
                    </div>
                  )}
                  {node.type === 'prompt' && (
                    <>
                      <div className={`font-medium truncate ${t.accentViolet}`}>{mockPrompts.find(p => p.id === node.data.promptId)?.name}</div>
                      {node.data.mergeOutlineToSave && isWriteFromOutlinePrompt(node.data.promptId) ? (
                        <span className="text-[10px] text-emerald-600 dark:text-emerald-400 font-semibold mt-1 block">
                          Gộp dàn ý → lưu bài
                        </span>
                      ) : null}
                    </>
                  )}
                  {node.type === 'filter' && (
                    <div className="flex flex-col gap-1">
                      <span className="font-semibold text-amber-600 dark:text-amber-400">
                        {node.data.filterType === 'extract_segment' ? 'Extract by tag' :
                         node.data.filterType === 'parse_outline' ? 'Extract outline' :
                         node.data.filterType === 'parse_keywords' ? 'Extract keywords' :
                         node.data.filterType === 'parse_faq' ? 'Extract FAQ' :
                         node.data.filterType === 'score_seo' ? 'SEO scoring' :
                         'Custom filter'}
                      </span>
                      {node.data.filterType === 'extract_segment' ? (
                        <span className="truncate text-[10px]">
                          Tag: {(node.data.filterTag === '__custom__'
                            ? node.data.customTag
                            : node.data.filterTag) || 'Not selected'}
                        </span>
                      ) : null}
                      {(!node.data.filterType || node.data.filterType === 'custom') && (
                        <span className="truncate text-[10px]">Logic: {node.data.rule || 'Not configured'}</span>
                      )}
                    </div>
                  )}
                  {node.type === 'action' && (
                    <div className="flex flex-col">
                      <span className={`font-medium ${isDark ? 'text-slate-200' : 'text-gray-800'}`}>
                        {actionTypeCanvasLabel(node.data.actionType)}
                      </span>
                      {node.data.isTrigger ? (
                        <span className="flex items-center gap-1 text-[10px] text-amber-500 font-bold mt-1.5 bg-amber-100 dark:bg-amber-500/10 px-2 py-0.5 rounded w-max border border-amber-200 dark:border-amber-500/20">
                          <Icons.Lightning /> Observer (Trigger)
                        </span>
                      ) : null}
                    </div>
                  )}
                  {node.type === 'end' && (
                    <span className={t.emptyHint}>Điểm kết thúc quy trình (tượng trưng)</span>
                  )}
                </div>

                {outputPorts.length > 0 && outputPorts.map((port, index) => (
                  <div
                    key={port.id}
                    className="absolute -right-3 flex items-center flex-row-reverse"
                    style={{
                      top: getOutputPortTop(node.type, nodeHeight, outputPorts.length, index),
                      transform: 'translateY(-50%)',
                    }}
                  >
                    <div onClick={(e) => handlePortClick(node.id, port.id, 'output', e)} className={`w-5 h-5 rounded-full border-2 cursor-pointer flex items-center justify-center z-20 ${t.portBorder} ${connecting?.nodeId === node.id && connecting?.portId === port.id ? 'bg-amber-500 animate-pulse' : port.color}`}><div className="w-1.5 h-1.5 bg-white rounded-full"></div></div>
                    {(node.type === 'prompt' || node.type === 'article_filter' || node.type === 'user_input') && (
                      <div
                        className={`mr-3 max-w-38 truncate text-[10px] font-semibold px-1.5 py-0.5 rounded border ${t.portLabel}`}
                        title={port.label}
                      >
                        {port.label}
                      </div>
                    )}
                  </div>
                ))}
              </div>
            );
            })}
          </div>

          <div
            className={`absolute bottom-4 left-4 z-30 flex items-center overflow-hidden rounded-lg border shadow-lg ${isDark ? 'border-slate-600 bg-slate-800 text-slate-100' : 'border-gray-200 bg-white text-gray-700'}`}
            onClick={(e) => e.stopPropagation()}
          >
            <button
              type="button"
              title="Thu nhỏ"
              aria-label="Thu nhỏ sơ đồ"
              disabled={zoom <= MIN_ZOOM}
              onClick={() => changeZoom(-ZOOM_STEP)}
              className="flex h-9 w-9 items-center justify-center border-r border-inherit transition-colors hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:hover:bg-slate-700"
            >
              <Icons.ZoomOut />
            </button>
            <button
              type="button"
              title="Đặt lại 100%"
              onClick={() => setZoom(1)}
              className="h-9 min-w-14 px-2 text-xs font-semibold transition-colors hover:bg-gray-100 dark:hover:bg-slate-700"
            >
              {Math.round(zoom * 100)}%
            </button>
            <button
              type="button"
              title="Phóng to"
              aria-label="Phóng to sơ đồ"
              disabled={zoom >= MAX_ZOOM}
              onClick={() => changeZoom(ZOOM_STEP)}
              className="flex h-9 w-9 items-center justify-center border-l border-inherit transition-colors hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:hover:bg-slate-700"
            >
              <Icons.ZoomIn />
            </button>
          </div>
        </div>

        {/* RIGHT SETTINGS */}
        <div className={`w-80 border-l p-5 overflow-y-auto transition-colors duration-200 shadow-sm ${t.panel}`}>
          {selectedNode ? (
            <div className="space-y-5">
              <h3 className={`font-bold border-b pb-2 ${t.headingAccent}`}>Cấu hình: {selectedNode.title}</h3>
              
              {selectedNode.type === 'article' && (
                <div className="space-y-4">
                  <p className={`text-xs leading-relaxed ${t.emptyHint}`}>
                    Node đầu vào từ dự án / bài viết. Cấu hình <b>Hành động</b>, loại bài và taxonomy trên widget <b>Lọc bài viết</b>.
                  </p>
                </div>
              )}

              {selectedNode.type === 'user_input' && (
                <div className="space-y-4">
                  <p className={`text-xs leading-relaxed ${t.emptyHint}`}>
                    Nhận biến <code className="text-orange-600 dark:text-orange-300">{'{{input}}'}</code> từ panel <b>AI ảnh &amp; video</b> khi biên tập bấm Generate image/video trên block ảnh.
                    Nối cổng <b>{'{{input}}'}</b> vào Prompt Hình ảnh (hoặc Filter) trong quy trình tạo ảnh editor.
                  </p>
                </div>
              )}

              {selectedNode.type === 'article_filter' && (
                <div className="space-y-4">
                  <div>
                    <label className={`text-xs block mb-1 ${t.label}`}>Hành động (Để trống = Lấy tất cả)</label>
                    <div className="flex flex-wrap gap-2">
                      {mockActions.map((act) => (
                        <button
                          type="button"
                          key={act.id}
                          onClick={() => {
                            const cur = selectedNode.data.actions || [];
                            updateNodeData(
                              selectedNode.id,
                              'actions',
                              cur.includes(act.id) ? cur.filter((x) => x !== act.id) : [...cur, act.id],
                            );
                          }}
                          className={`text-xs px-3 py-1.5 rounded-md border transition-colors shadow-sm ${actionChipClass(selectedNode.data.actions?.includes(act.id))}`}
                        >
                          {act.label}
                        </button>
                      ))}
                    </div>
                  </div>
                  <div>
                    <label className={`text-xs block mb-1 ${t.label}`}>Post Type (Để trống = Lấy tất cả)</label>
                    <div className="flex flex-wrap gap-2">
                      {mockPostTypes.map(pt => (
                        <button
                          type="button"
                          key={pt}
                          onClick={() => { const cur = selectedNode.data.postTypes || []; updateNodeData(selectedNode.id, 'postTypes', cur.includes(pt) ? cur.filter(x => x !== pt) : [...cur, pt]); }}
                          className={`text-xs px-3 py-1.5 rounded-md border transition-colors shadow-sm ${chipClass(selectedNode.data.postTypes?.includes(pt))}`}
                        >
                          {pt}
                        </button>
                      ))}
                    </div>
                  </div>
                  <div>
                    <label className={`text-xs block mb-1 ${t.label}`}>Taxonomy (Để trống = Lấy tất cả)</label>
                    <div className="flex flex-wrap gap-2">
                      {mockTaxonomies.map(tax => (
                        <button
                          type="button"
                          key={tax}
                          onClick={() => { const cur = selectedNode.data.taxonomies || []; updateNodeData(selectedNode.id, 'taxonomies', cur.includes(tax) ? cur.filter(x => x !== tax) : [...cur, tax]); }}
                          className={`text-xs px-3 py-1.5 rounded-md border transition-colors shadow-sm ${chipClass(selectedNode.data.taxonomies?.includes(tax))}`}
                        >
                          {tax}
                        </button>
                      ))}
                    </div>
                  </div>
                </div>
              )}
              
              {selectedNode.type === 'prompt' && (
                <div className="space-y-4">
                  <div>
                    <label className={`text-xs block mb-1 ${t.label}`}>Chọn Prompt thực thi</label>
                    <SeoSelect
                      value={selectedNode.data.promptId}
                      onChange={(e) => {
                        const promptId = e.target.value;
                        const currentRole = String(selectedNode.data.execution_role ?? '').trim();
                        const suggestedRole = suggestExecutionRoleFromPrompt(promptId);
                        updateNodeDataFields(selectedNode.id, {
                          promptId,
                          mergeOutlineToSave: isWriteFromOutlinePrompt(promptId)
                            ? Boolean(selectedNode.data.mergeOutlineToSave)
                            : false,
                          // Auto-fill role from prompt hook when empty or still matching prior suggestion.
                          ...(currentRole === '' || currentRole === suggestExecutionRoleFromPrompt(selectedNode.data.promptId)
                            ? { execution_role: suggestedRole }
                            : {}),
                        });
                      }}
                      className="w-full"
                      options={mockPrompts.map((p) => ({ value: p.id, label: p.name }))}
                    />
                  </div>
                  <div>
                    <label className={`text-xs block mb-1 ${t.label}`}>Vai trò thực thi</label>
                    <SeoSelect
                      value={selectedNode.data.execution_role ?? ''}
                      onChange={(e) => updateNodeData(selectedNode.id, 'execution_role', e.target.value)}
                      className="w-full"
                      options={getWorkflowRoleOptions()}
                    />
                    {(() => {
                      const role = String(selectedNode.data.execution_role ?? '').trim();
                      if (role === '') {
                        return null;
                      }
                      const dup = nodes.some(
                        (n) => n.id !== selectedNode.id
                          && n.type === 'prompt'
                          && String(n.data?.execution_role ?? '').trim() === role,
                      );
                      if (!dup) {
                        return null;
                      }
                      return (
                        <p className="mt-1 text-[11px] text-amber-700 dark:text-amber-300">
                          Vai trò này đã gán cho Prompt Block khác — mỗi workflow chỉ nên một node / role.
                        </p>
                      );
                    })()}
                  </div>
                  {String(selectedNode.data.execution_role ?? '').includes('outline') ? (
                    <>
                      <div>
                        <label className={`text-xs block mb-1 ${t.label}`}>Outline Prompt (tuỳ chọn)</label>
                        <SeoSelect
                          value={selectedNode.data.outline_prompt_id ?? ''}
                          onChange={(e) => updateNodeData(selectedNode.id, 'outline_prompt_id', e.target.value)}
                          className="w-full"
                          options={[{ value: '', label: '— Dùng Prompt Block chính —' }, ...mockPrompts.map((p) => ({ value: p.id, label: p.name }))]}
                        />
                      </div>
                      <div>
                        <label className={`text-xs block mb-1 ${t.label}`}>Vocabulary Prompt</label>
                        <SeoSelect
                          value={selectedNode.data.vocabulary_prompt_id ?? ''}
                          onChange={(e) => updateNodeData(selectedNode.id, 'vocabulary_prompt_id', e.target.value)}
                          className="w-full"
                          options={[{ value: '', label: '— Chọn prompt từ vựng —' }, ...mockPrompts.map((p) => ({ value: p.id, label: p.name }))]}
                        />
                        <p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                          Bắt buộc cho flow split: Outline và Vocabulary chạy 2 provider call riêng.
                        </p>
                      </div>
                    </>
                  ) : null}
                  {isWriteFromOutlinePrompt(selectedNode.data.promptId) ? (
                    <div className="bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 p-3 rounded-lg">
                      <label className="flex items-start gap-2 cursor-pointer">
                        <input
                          type="checkbox"
                          className="mt-0.5 rounded border-emerald-400 text-emerald-600 focus:ring-emerald-500"
                          checked={Boolean(selectedNode.data.mergeOutlineToSave)}
                          onChange={(e) => updateNodeData(selectedNode.id, 'mergeOutlineToSave', e.target.checked)}
                        />
                        <span className="text-xs text-emerald-800 dark:text-emerald-300 leading-relaxed">
                          <b>Gộp dàn ý → lưu bài</b> — khi bật, kết quả bài viết hoàn chỉnh (tiêu đề + nội dung + meta)
                          được đẩy thẳng vào hành động <b>Tạo / cập nhật bài viết</b>: convert Markdown → HTML body, lưu tiêu đề,
                          meta description và FAQ. Không cần khối lọc dàn ý trung gian.
                        </span>
                      </label>
                    </div>
                  ) : null}
                  <p className={`text-[11px] leading-relaxed ${t.emptyHint}`}>
                    Model lấy từ Settings → AI Advanced / routing runtime. Kết nối AI lấy từ Prompt đã chọn.
                    Dùng biến <code>{'{{input}}'}</code> trong prompt để nhận kết quả từ edge nối vào.
                  </p>
                </div>
              )}

              {selectedNode.type === 'filter' && (
                <div className="space-y-4">
                  <div>
                    <label className="text-xs text-gray-500 dark:text-slate-400 block mb-1 font-semibold">Chức năng Xử lý / Lọc</label>
                    <SeoSelect
                      value={selectedNode.data.filterType || 'custom'}
                      onChange={(e) => updateNodeData(selectedNode.id, 'filterType', e.target.value)}
                      className="w-full"
                      options={[
                        { value: 'extract_segment', label: '0. Bóc tách theo Tag [START...END]' },
                        { value: 'custom', label: 'Lọc điều kiện tùy chỉnh' },
                        { value: 'parse_outline', label: '1. Bóc tách Dàn ý (Markdown -> JSON)' },
                        { value: 'parse_keywords', label: '2. Bóc tách Từ khóa (Markdown -> JSON)' },
                        { value: 'parse_faq', label: '3. Bóc tách FAQ' },
                        { value: 'score_seo', label: '4. Chấm điểm SEO (FAQ + Bảng)' },
                      ]}
                    />
                  </div>

                  {selectedNode.data.filterType === 'extract_segment' && (
                    <>
                      <div>
                        <label className="text-xs text-gray-500 dark:text-slate-400 block mb-1 font-semibold">
                          Chọn dữ liệu đầu vào
                        </label>
                        <SeoSelect
                          value={selectedNode.data.inputSource || 'auto'}
                          onChange={(e) => updateNodeData(selectedNode.id, 'inputSource', e.target.value)}
                          className="w-full"
                        >
                          <option value="auto">Auto (Output từ node nối vào gần nhất)</option>
                          {incomingEdges.map((edge) => {
                            const sourceNode = nodes.find((node) => node.id === edge.sourceNode);
                            const sourceTitle = sourceNode?.title || edge.sourceNode;
                            return (
                              <option key={`${edge.id}-source`} value={edge.sourcePort || 'out_main'}>
                                {`${sourceTitle} -> ${(edge.sourcePort || 'out_main')}`}
                              </option>
                            );
                          })}
                        </SeoSelect>
                      </div>

                      <div>
                        <label className="text-xs text-gray-500 dark:text-slate-400 block mb-1 font-semibold">
                          Tên bộ lọc cần bóc tách
                        </label>
                        <SeoSelect
                          value={selectedNode.data.filterTag || ''}
                          onChange={(e) => updateNodeData(selectedNode.id, 'filterTag', e.target.value)}
                          className="w-full"
                          placeholder="Chọn tag..."
                        >
                          {uniqueSourcePromptTags.map((tag) => (
                            <option key={tag.key} value={tag.key}>
                              {tag.label || tag.key}
                            </option>
                          ))}
                          <option value="__custom__">Tùy chỉnh (Gõ tay)</option>
                        </SeoSelect>
                      </div>

                      {selectedNode.data.filterTag === '__custom__' && (
                        <div>
                          <label className="text-xs text-gray-500 dark:text-slate-400 block mb-1">
                            Tag tùy chỉnh
                          </label>
                          <input
                            type="text"
                            value={selectedNode.data.customTag || ''}
                            onChange={(e) => updateNodeData(selectedNode.id, 'customTag', e.target.value)}
                            placeholder="TASK_1_OUTLINE"
                            className="w-full bg-white dark:bg-slate-900 border border-gray-300 dark:border-slate-700 rounded-md p-2 text-sm text-gray-800 dark:text-white focus:outline-none focus:border-amber-500 transition-colors shadow-sm"
                          />
                        </div>
                      )}

                      <div className="bg-sky-50 dark:bg-sky-500/10 border border-sky-200 dark:border-sky-500/20 p-3 rounded-lg text-xs text-sky-700 dark:text-sky-300 leading-relaxed shadow-sm">
                        <div className="font-semibold mb-1">Xem trước định dạng</div>
                        <code className="block">
                          [START {(selectedNode.data.filterTag === '__custom__'
                            ? selectedNode.data.customTag
                            : selectedNode.data.filterTag) || 'TAG_NAME'}]
                        </code>
                        <code className="block">...</code>
                        <code className="block">
                          [END {(selectedNode.data.filterTag === '__custom__'
                            ? selectedNode.data.customTag
                            : selectedNode.data.filterTag) || 'TAG_NAME'}]
                        </code>
                      </div>
                    </>
                  )}

                  {(!selectedNode.data.filterType || selectedNode.data.filterType === 'custom') && (
                    <div>
                      <label className="text-xs text-gray-500 dark:text-slate-400 block mb-1">Điều kiện lọc</label>
                      <input
                        type="text"
                        value={selectedNode.data.rule || ''}
                        onChange={(e) => updateNodeData(selectedNode.id, 'rule', e.target.value)}
                        placeholder="Enter filter logic (e.g. score > 80)..."
                        className="w-full bg-white dark:bg-slate-900 border border-gray-300 dark:border-slate-700 rounded-md p-2 text-sm text-gray-800 dark:text-white focus:outline-none focus:border-amber-500 transition-colors shadow-sm"
                      />
                    </div>
                  )}

                  {selectedNode.data.filterType === 'parse_outline' && (
                    <div className="bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 p-3 rounded-lg text-xs text-amber-700 dark:text-amber-400 leading-relaxed shadow-sm">
                      💡 <b>Parse Dàn ý:</b> Hệ thống sẽ sử dụng Parser để bóc tách các thẻ Heading (H2, H3) từ kết quả Markdown của AI thành cấu trúc JSON Outline chuẩn và đưa vào Meta Data.
                    </div>
                  )}

                  {selectedNode.data.filterType === 'parse_keywords' && (
                    <div className="bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 p-3 rounded-lg text-xs text-amber-700 dark:text-amber-400 leading-relaxed shadow-sm">
                      💡 <b>Parse Từ khóa:</b> Hệ thống sẽ đọc Markdown dạng list (### Category) và bóc tách thành các mảng từ khóa ngữ nghĩa (Synonyms, LSI...) lưu vào Meta Data.
                    </div>
                  )}

                  {selectedNode.data.filterType === 'parse_faq' && (
                    <div className="bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 p-3 rounded-lg text-xs text-amber-700 dark:text-amber-400 leading-relaxed shadow-sm">
                      💡 <b>Parse FAQ:</b> Bóc tách câu hỏi/trả lời (H3) và tự chấm +10 điểm nếu có FAQ hợp lệ.
                    </div>
                  )}

                  {selectedNode.data.filterType === 'score_seo' && (
                    <div className="bg-violet-50 dark:bg-violet-500/10 border border-violet-200 dark:border-violet-500/20 p-3 rounded-lg text-xs text-violet-700 dark:text-violet-300 leading-relaxed shadow-sm">
                      💡 <b>Chấm SEO:</b> FAQ và bảng Featured Snippet là rule riêng trong hệ thống deduction-based (trừ điểm khi thiếu). Chạy sau khi AI sinh nội dung.
                    </div>
                  )}
                </div>
              )}

              {selectedNode.type === 'action' && (
                <div className="space-y-5">
                  <div className="bg-gray-50 dark:bg-slate-900/50 p-3 rounded-lg border border-gray-200 dark:border-slate-800">
                    <label className="flex items-center gap-3 cursor-pointer">
                      <div className="relative flex items-center">
                        <input
                          type="checkbox"
                          checked={selectedNode.data.isTrigger || false}
                          onChange={(e) => updateNodeData(selectedNode.id, 'isTrigger', e.target.checked)}
                          className="peer sr-only"
                        />
                        <div className="w-9 h-5 bg-gray-300 dark:bg-slate-700 peer-focus:outline-none rounded-full peer-checked:bg-amber-500 transition-colors" />
                        <div className="absolute left-[2px] top-[2px] bg-white w-4 h-4 rounded-full transition-transform peer-checked:translate-x-full shadow-sm" />
                      </div>
                      <div>
                        <span className="text-sm font-bold text-gray-800 dark:text-amber-400 block">Trigger (Observer)</span>
                        <span className="text-[10px] text-gray-500 dark:text-slate-500">Biến Widget thành bộ giám sát tự động</span>
                      </div>
                    </label>
                  </div>

                  <div>
                    <label className={`text-xs block mb-2 font-semibold ${t.label}`}>Thực thi Hành động</label>
                    <SeoSelect
                      value={normalizeActionType(selectedNode.data.actionType)}
                      onChange={(e) => updateNodeData(selectedNode.id, 'actionType', e.target.value)}
                      className="w-full"
                      options={[
                        { value: ARTICLE_SAVE_ACTION, label: 'Tạo / cập nhật bài viết' },
                        { value: 'save_vocabulary_research', label: 'Lưu nghiên cứu từ vựng (Topic Cluster)' },
                        { value: 'post_comment_review', label: 'Đăng bình luận / review (WordPress)' },
                      ]}
                    />
                  </div>

                  {selectedNode.data.actionType === 'post_comment_review' ? (
                    <div className="bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 p-3 rounded-lg">
                      <p className="text-xs text-amber-800 dark:text-amber-300 leading-relaxed">
                        Đăng JSON comment/review lên WordPress qua plugin. Product: tự gán sao 5-5-4 nếu thiếu <code>star_ranking</code>.
                      </p>
                    </div>
                  ) : selectedNode.data.actionType === 'save_vocabulary_research' ? (
                    <div className="bg-violet-50 dark:bg-violet-500/10 border border-violet-200 dark:border-violet-500/20 p-3 rounded-lg">
                      <p className="text-xs text-violet-800 dark:text-violet-300 leading-relaxed">
                        Lưu từ khóa đã bóc tách (Khối Lọc → Bóc tách Từ khóa) vào bảng <code>keywords</code> theo cấu trúc Topic Cluster:
                        từ khóa chính (parent) + từ khóa con theo nhóm ngữ nghĩa, đồng thời gắn pivot <code>article_keyword</code>.
                      </p>
                    </div>
                  ) : isArticleSaveAction(selectedNode.data.actionType) ? (
                    <div className="bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 p-3 rounded-lg">
                      <p className="text-xs text-emerald-700 dark:text-emerald-400 leading-relaxed">
                        Chưa có bài trong luồng → tạo bài mới từ context (domain, tiêu đề, meta). Đã có bài → cập nhật meta vào bài hiện có.
                        Có thể nối tiếp các hành động khác qua cổng Output.
                      </p>
                    </div>
                  ) : null}
                </div>
              )}

              {selectedNode.type === 'end' && (
                <div className="space-y-4">
                  <p className={`text-xs leading-relaxed ${t.emptyHint}`}>
                    Node <b>End</b> chỉ để đánh dấu điểm kết thúc sơ đồ — không thực thi logic.
                    Nối cổng output của bước cuối (Prompt, Action, …) vào <b>End</b>.
                  </p>
                </div>
              )}
            </div>
          ) : (<div className={`text-center mt-10 text-sm ${t.emptyHint}`}>Chọn một Node để cài đặt</div>)}
        </div>

      </div>
    </div>
  );
}
