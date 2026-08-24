import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  buildFlowTheme,
  endNodeSurfaceClass,
  FLOW_NODE_OUTER_WIDTH,
  getArticleFilterOutputPorts,
  getDefaultNodeHeight,
  getFlowNodeOuterWidth,
  getInputPortCenterX,
  getInputPortCenterY,
  getOutputPortCenterX,
  getOutputPortTop,
  getPromptOutputPorts,
  getUserInputOutputPorts,
  isFlowEndNode,
  isFlowStartNode,
  isPresentationContextNode,
  nodeBorderClass,
  startNodeSurfaceClass,
} from './flowTheme';
import { FlowIcons } from './flowIcons.jsx';
import {
  actionTypeCanvasLabel,
  articleFilterSummary,
  EXECUTION_STATUS_TONE_CLASS,
  executionStatusPresentation,
  filterTypeLabel,
  getPromptConfig,
  isWriteFromOutlinePrompt,
} from './flowNodeHelpers';

const MIN_ZOOM = 0.5;
const MAX_ZOOM = 1.5;
const ZOOM_STEP = 0.1;
const NODE_WIDTH = FLOW_NODE_OUTER_WIDTH;

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

function ExecutionStatusBadge({ execution }) {
  if (!execution) {
    return null;
  }

  const { prefix, label, tone } = executionStatusPresentation(execution.status, execution.status_label);
  const toneClass = EXECUTION_STATUS_TONE_CLASS[tone] ?? EXECUTION_STATUS_TONE_CLASS.amber;

  return (
    <div className={`absolute -top-2.5 right-2 z-30 max-w-[calc(100%-1rem)] truncate rounded-full border px-2 py-0.5 text-[10px] font-semibold shadow-sm ${toneClass}`}>
      {prefix} {label}
    </div>
  );
}

function nodeOuterWidth(node) {
  return getFlowNodeOuterWidth(node?.type);
}

function computeFitZoom(nodes, containerWidth, containerHeight) {
  if (!nodes.length || containerWidth <= 0 || containerHeight <= 0) {
    return 1;
  }

  const padding = 48;
  const xs = nodes.map((n) => Number(n.x ?? 0));
  const ys = nodes.map((n) => Number(n.y ?? 0));
  const maxX = Math.max(...nodes.map((n) => Number(n.x ?? 0) + nodeOuterWidth(n))) + padding;
  const maxY = Math.max(...ys.map((y, i) => y + getDefaultNodeHeight(nodes[i].type, 3))) + padding;
  const minX = Math.min(...xs) - padding;
  const minY = Math.min(...ys) - padding;
  const graphWidth = maxX - minX;
  const graphHeight = maxY - minY;

  const scaleX = containerWidth / graphWidth;
  const scaleY = containerHeight / graphHeight;
  const zoom = Math.min(scaleX, scaleY, 1);

  return Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, zoom));
}

/** Shared Task Workflow canvas — Execution History uses readOnly=true. */
export default function TaskWorkflowCanvas({
  nodes = [],
  edges = [],
  virtualEdges = [],
  prompts = [],
  readOnly = false,
  selectedNodeId = null,
  onSelectNode,
  executionByNodeId = {},
  fitViewOnMount = true,
  className = '',
}) {
  const isDark = useDarkMode();
  const t = buildFlowTheme(isDark);
  const canvasRef = useRef(null);
  const [zoom, setZoom] = useState(1);
  const [pan, setPan] = useState({ x: 0, y: 0 });
  const [isPanning, setIsPanning] = useState(false);
  const panStartRef = useRef({ x: 0, y: 0, panX: 0, panY: 0 });
  const fitAppliedRef = useRef(false);

  const changeZoom = useCallback((delta) => {
    setZoom((current) => Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, Number((current + delta).toFixed(2)))));
  }, []);

  const fitView = useCallback(() => {
    const el = canvasRef.current;
    if (!el || nodes.length === 0) {
      return;
    }
    const rect = el.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) {
      return;
    }
    setZoom(computeFitZoom(nodes, rect.width, rect.height));
    setPan({ x: 0, y: 0 });
  }, [nodes]);

  useEffect(() => {
    fitAppliedRef.current = false;
  }, [nodes, edges, virtualEdges]);

  useEffect(() => {
    if (!fitViewOnMount || nodes.length === 0) {
      return undefined;
    }

    fitAppliedRef.current = false;
    const runFit = () => {
      fitView();
      fitAppliedRef.current = true;
    };

    const raf = requestAnimationFrame(runFit);
    const t1 = window.setTimeout(runFit, 120);
    const t2 = window.setTimeout(runFit, 400);

    return () => {
      cancelAnimationFrame(raf);
      window.clearTimeout(t1);
      window.clearTimeout(t2);
    };
  }, [fitView, fitViewOnMount, nodes, edges, virtualEdges]);

  const handleCanvasMouseDown = (event) => {
    if (!readOnly || event.button !== 0) {
      return;
    }
    if (event.target.closest('[data-flow-node]')) {
      return;
    }
    setIsPanning(true);
    panStartRef.current = {
      x: event.clientX,
      y: event.clientY,
      panX: pan.x,
      panY: pan.y,
    };
  };

  useEffect(() => {
    if (!isPanning) {
      return undefined;
    }

    const onMove = (event) => {
      const start = panStartRef.current;
      setPan({
        x: start.panX + (event.clientX - start.x),
        y: start.panY + (event.clientY - start.y),
      });
    };

    const onUp = () => setIsPanning(false);

    window.addEventListener('mousemove', onMove);
    window.addEventListener('mouseup', onUp);
    return () => {
      window.removeEventListener('mousemove', onMove);
      window.removeEventListener('mouseup', onUp);
    };
  }, [isPanning]);

  return (
    <div
      ref={canvasRef}
      onMouseDown={handleCanvasMouseDown}
      onWheel={(e) => {
        if (!e.ctrlKey && !e.metaKey) return;
        e.preventDefault();
        changeZoom(e.deltaY < 0 ? ZOOM_STEP : -ZOOM_STEP);
      }}
      className={[
        'seo-flow-builder relative h-full w-full flex-1 overflow-hidden select-none transition-colors duration-200',
        readOnly ? 'min-h-0 cursor-grab' : '',
        isPanning ? 'cursor-grabbing' : '',
        t.canvas,
        className,
      ].filter(Boolean).join(' ')}
      data-theme={isDark ? 'dark' : 'light'}
      style={{
        backgroundImage: t.gridImage,
        backgroundSize: `${24 * zoom}px ${24 * zoom}px`,
        backgroundPosition: `${pan.x}px ${pan.y}px`,
      }}
    >
      <div
        className="absolute left-0 top-0 origin-top-left"
        style={{
          transform: `translate(${pan.x}px, ${pan.y}px) scale(${zoom})`,
        }}
      >
        <svg className="pointer-events-none absolute inset-0 z-0 h-[4000px] w-[4000px]">
          {[...edges, ...virtualEdges].map((edge) => {
            const isVirtual = Boolean(edge.virtual);
            const tgtNode = nodes.find((n) => n.id === edge.targetNode);
            if (!tgtNode) return null;

            const srcNode = nodes.find((n) => n.id === edge.sourceNode);
            if (!srcNode) return null;

            const srcPorts = srcNode.type === 'prompt'
              ? getPromptOutputPorts(srcNode.data?.promptId, prompts, isDark)
              : srcNode.type === 'article_filter'
                ? getArticleFilterOutputPorts(isDark)
                : srcNode.type === 'user_input'
                  ? getUserInputOutputPorts(isDark)
                  : [{ id: 'out_main' }];
            const srcPortIndex = srcPorts.findIndex((p) => p.id === edge.sourcePort);
            const srcNodeHeight = getDefaultNodeHeight(srcNode.type, srcPorts.length);
            const startX = getOutputPortCenterX(Number(srcNode.x ?? 0), srcNode.type);
            const startY = Number(srcNode.y ?? 0) + getOutputPortTop(srcNode.type, srcNodeHeight, srcPorts.length, Math.max(0, srcPortIndex));

            const tgtOutPorts = tgtNode.type === 'prompt'
              ? getPromptOutputPorts(tgtNode.data?.promptId, prompts, isDark)
              : [{ id: 'out_main' }];
            const tgtNodeHeight = getDefaultNodeHeight(tgtNode.type, tgtOutPorts.length);
            const endX = getInputPortCenterX(Number(tgtNode.x ?? 0));
            const endY = getInputPortCenterY(Number(tgtNode.y ?? 0), tgtNodeHeight);
            const cpOffset = Math.abs(endX - startX) * 0.5;
            const d = `M ${startX} ${startY} C ${startX + cpOffset} ${startY}, ${endX - cpOffset} ${endY}, ${endX} ${endY}`;
            const edgeColor = isVirtual ? (isDark ? '#64748b' : '#94a3b8') : t.edgeColor;

            return (
              <g key={edge.id ?? `${edge.sourceNode}-${edge.targetNode}-${edge.sourcePort}`}>
                <path
                  d={d}
                  fill="none"
                  stroke={edgeColor}
                  strokeWidth={isVirtual ? '2' : '3'}
                  strokeDasharray={isVirtual ? '5 4' : undefined}
                />
                {!isVirtual && <circle cx={(startX + endX) / 2} cy={(startY + endY) / 2} r="4" fill={edgeColor} />}
              </g>
            );
          })}
        </svg>

        {nodes.map((node) => {
          const isSelected = node.id === selectedNodeId;
          const isContextPresentation = isPresentationContextNode(node.type);
          const execution = isContextPresentation ? null : (executionByNodeId[node.id] ?? null);
          const outerWidth = nodeOuterWidth(node);
          const nodeClass = [
            'absolute z-10 flex flex-col rounded-xl border shadow-lg transition-colors duration-200',
            isContextPresentation ? 'w-[168px]' : 'w-[220px]',
            readOnly ? 'cursor-pointer' : 'cursor-grab',
            t.nodeBg,
            nodeBorderClass(node.type, isSelected, isDark),
            isContextPresentation
              ? (isDark ? 'seo-flow-node--start seo-flow-node--start-article-dark' : 'seo-flow-node--start seo-flow-node--start-article')
              : '',
            !isContextPresentation ? startNodeSurfaceClass(node.type, isDark) : '',
            isFlowEndNode(node.type) ? endNodeSurfaceClass(isDark) : '',
          ].filter(Boolean).join(' ');
          const outputPorts = isFlowEndNode(node.type)
            ? []
            : node.type === 'prompt'
              ? getPromptOutputPorts(node.data?.promptId, prompts, isDark)
              : node.type === 'article_filter'
                ? getArticleFilterOutputPorts(isDark)
                : node.type === 'user_input'
                  ? getUserInputOutputPorts(isDark)
                  : [{ id: 'out_main', label: 'Connect', color: isDark ? 'bg-slate-500' : 'bg-gray-500' }];
          const nodeHeight = getDefaultNodeHeight(node.type, outputPorts.length);
          const filterSummary = node.type === 'article_filter' ? articleFilterSummary(node.data) : null;

          if (isContextPresentation) {
            const subtitle = node.data?.subtitle ?? '';
            return (
              <div
                key={node.id}
                data-flow-node
                role="button"
                tabIndex={0}
                onClick={(e) => {
                  e.stopPropagation();
                  onSelectNode?.(node.id);
                }}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    onSelectNode?.(node.id);
                  }
                }}
                className={nodeClass}
                style={{ left: Number(node.x ?? 0), top: Number(node.y ?? 0), height: nodeHeight }}
              >
                <div className={`flex items-center justify-between border-b px-2.5 py-2 ${t.nodeHeaderBorder}`}>
                  <div className={`flex min-w-0 items-center gap-1.5 text-xs font-bold ${t.nodeTitle}`}>
                    <FlowIcons.Article />
                    <span className="truncate">{node.title}</span>
                    <span className="seo-flow-node__badge seo-flow-node__badge--start shrink-0 text-[9px]">CONTEXT</span>
                  </div>
                </div>
                <div className={`flex flex-col justify-center px-2.5 py-2 text-[11px] ${t.nodeBody}`}>
                  {subtitle ? (
                    <span className={`truncate font-medium ${isDark ? 'text-sky-300' : 'text-sky-700'}`}>{subtitle}</span>
                  ) : (
                    <span className={t.emptyHint}>Article input context</span>
                  )}
                </div>
                <div
                  className="absolute -right-3 flex flex-row-reverse items-center"
                  style={{
                    top: nodeHeight / 2,
                    transform: 'translateY(-50%)',
                  }}
                >
                  <div className={`z-20 flex h-5 w-5 items-center justify-center rounded-full border-2 ${t.portBorder} ${isDark ? 'bg-sky-500' : 'bg-sky-600'}`}>
                    <div className="h-1.5 w-1.5 rounded-full bg-white" />
                  </div>
                </div>
              </div>
            );
          }

          return (
            <div
              key={node.id}
              data-flow-node
              role="button"
              tabIndex={0}
              onClick={(e) => {
                e.stopPropagation();
                onSelectNode?.(node.id);
              }}
              onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                  e.preventDefault();
                  onSelectNode?.(node.id);
                }
              }}
              className={nodeClass}
              style={{ left: Number(node.x ?? 0), top: Number(node.y ?? 0), height: nodeHeight, width: outerWidth }}
            >
              {!isContextPresentation && <ExecutionStatusBadge execution={execution} />}

              {!isFlowStartNode(node.type) && (
                <div className={`absolute -left-3 top-1/2 z-20 flex h-5 w-5 -translate-y-1/2 items-center justify-center rounded-full border-2 ${t.portInput}`}>
                  <div className={`h-1.5 w-1.5 rounded-full ${t.portDot}`} />
                </div>
              )}

              <div className={`flex items-center justify-between border-b p-3 ${t.nodeHeaderBorder}`}>
                <div className={`flex min-w-0 items-center gap-2 text-sm font-bold ${t.nodeTitle}`}>
                  {node.type === 'article' && <FlowIcons.Article />}
                  {node.type === 'user_input' && <FlowIcons.Input />}
                  {node.type === 'article_filter' && <FlowIcons.Filter />}
                  {node.type === 'prompt' && <FlowIcons.Prompt />}
                  {node.type === 'filter' && <FlowIcons.Filter />}
                  {node.type === 'action' && <FlowIcons.Play />}
                  {node.type === 'end' && <FlowIcons.End />}
                  <span className="truncate">{node.title}</span>
                  {isFlowStartNode(node.type) ? (
                    <span className="seo-flow-node__badge seo-flow-node__badge--start shrink-0">Start</span>
                  ) : null}
                  {isFlowEndNode(node.type) ? (
                    <span className="seo-flow-node__badge seo-flow-node__badge--end shrink-0">End</span>
                  ) : null}
                </div>
              </div>

              <div className={`flex flex-col justify-center p-3 text-xs ${t.nodeBody}`}>
                {node.type === 'article' && <span className={t.emptyHint}>Input bài viết</span>}
                {node.type === 'user_input' && (
                  <div className="space-y-1">
                    <span className={`font-semibold ${isDark ? 'text-orange-300' : 'text-orange-700'}`}>{'{{input}}'}</span>
                    <span className={t.emptyHint}>Từ panel AI ảnh &amp; video trên editor</span>
                  </div>
                )}
                {node.type === 'article_filter' && filterSummary && (
                  <div className="space-y-1">
                    <span>
                      Hành động: <span className={`font-semibold ${t.accentEmerald}`}>{filterSummary.actions}</span>
                    </span>
                    <br />
                    <span>Loại: {filterSummary.postTypes}</span>
                    <br />
                    <span>Tax: {filterSummary.taxonomies}</span>
                  </div>
                )}
                {node.type === 'prompt' && (
                  <>
                    <div className={`truncate font-medium ${t.accentViolet}`}>
                      {getPromptConfig(node.data?.promptId, prompts)?.name ?? 'Prompt'}
                    </div>
                    {node.data?.mergeOutlineToSave && isWriteFromOutlinePrompt(node.data?.promptId, prompts) ? (
                      <span className="mt-1 block text-[10px] font-semibold text-emerald-600 dark:text-emerald-400">
                        Gộp dàn ý → lưu bài
                      </span>
                    ) : null}
                  </>
                )}
                {node.type === 'filter' && (
                  <div className="flex flex-col gap-1">
                    <span className="font-semibold text-amber-600 dark:text-amber-400">
                      {filterTypeLabel(node.data?.filterType)}
                    </span>
                    {node.data?.filterType === 'extract_segment' ? (
                      <span className="truncate text-[10px]">
                        Tag: {(node.data?.filterTag === '__custom__' ? node.data?.customTag : node.data?.filterTag) || 'Not selected'}
                      </span>
                    ) : null}
                    {(!node.data?.filterType || node.data?.filterType === 'custom') && (
                      <span className="truncate text-[10px]">Logic: {node.data?.rule || 'Not configured'}</span>
                    )}
                  </div>
                )}
                {node.type === 'action' && (
                  <div className="flex flex-col">
                    <span className={`font-medium ${isDark ? 'text-slate-200' : 'text-gray-800'}`}>
                      {actionTypeCanvasLabel(node.data?.actionType)}
                    </span>
                    {node.data?.isTrigger ? (
                      <span className="mt-1.5 flex w-max items-center gap-1 rounded border border-amber-200 bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-500 dark:border-amber-500/20 dark:bg-amber-500/10">
                        <FlowIcons.Lightning /> Observer (Trigger)
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
                  className="absolute -right-3 flex flex-row-reverse items-center"
                  style={{
                    top: getOutputPortTop(node.type, nodeHeight, outputPorts.length, index),
                    transform: 'translateY(-50%)',
                  }}
                >
                  <div className={`z-20 flex h-5 w-5 items-center justify-center rounded-full border-2 ${t.portBorder} ${port.color}`}>
                    <div className="h-1.5 w-1.5 rounded-full bg-white" />
                  </div>
                  {(node.type === 'prompt' || node.type === 'article_filter' || node.type === 'user_input') && !readOnly && (
                    <div className={`mr-3 max-w-38 truncate rounded border px-1.5 py-0.5 text-[10px] font-semibold ${t.portLabel}`} title={port.label}>
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
          <FlowIcons.ZoomOut />
        </button>
        <button
          type="button"
          title="Fit view"
          aria-label="Fit view"
          onClick={fitView}
          className="flex h-9 w-9 items-center justify-center border-r border-inherit transition-colors hover:bg-gray-100 dark:hover:bg-slate-700"
        >
          <FlowIcons.FitView />
        </button>
        <button
          type="button"
          title="Đặt lại 100%"
          onClick={() => { setZoom(1); setPan({ x: 0, y: 0 }); }}
          className="h-9 min-w-14 border-r border-inherit px-2 text-xs font-semibold transition-colors hover:bg-gray-100 dark:hover:bg-slate-700"
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
          <FlowIcons.ZoomIn />
        </button>
      </div>
    </div>
  );
}
