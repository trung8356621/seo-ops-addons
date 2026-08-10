import { useCallback, useEffect, useRef, useState } from 'react';
import { useDebouncedCallback } from './useDebouncedCallback';
import { loadHistory, saveHistory, sanitizeBlocksForEditor } from '../utils/articleEditorStorage';

const cloneBlocks = (blocks) => JSON.parse(JSON.stringify(blocks));

const snapshotFromBlocks = (blocks, html) => ({
    blocks: cloneBlocks(blocks),
    html,
    ts: Date.now(),
});

function exportBlocksToHtmlFromBlocks(blocks) {
    return blocks
        .map((b) => {
            if (b.prefix || b.suffix) {
                return [b.prefix, b.content, b.suffix].filter(Boolean).join('\n');
            }
            return b.content;
        })
        .filter(Boolean)
        .join('\n\n');
}

export function useArticleEditorHistory({
    articleId,
    historyStep = 20,
    blocks,
    setBlocks,
    setActiveBlockId,
    getExportHtml,
}) {
    const maxSteps = Math.max(1, Math.min(100, Number(historyStep) || 20));
    const [past, setPast] = useState([]);
    const [future, setFuture] = useState([]);
    const lastRecordedRef = useRef(null);
    const isRestoringRef = useRef(false);
    const suppressHistoryRef = useRef(false);
    const blocksRef = useRef(blocks);
    blocksRef.current = blocks;

    useEffect(() => {
        lastRecordedRef.current = null;
        if (!articleId) {
            setPast([]);
            setFuture([]);
            return;
        }
        const stored = loadHistory(articleId);
        setPast(stored.past);
        setFuture(stored.future);
    }, [articleId]);

    const { debounced: persistHistory } = useDebouncedCallback((pastStack, futureStack) => {
        if (!articleId) return;
        saveHistory(articleId, pastStack, futureStack);
    }, 400);

    const { debounced: debouncedRecord } = useDebouncedCallback((currentBlocks) => {
        if (isRestoringRef.current || !currentBlocks?.length) return;

        const serialized = JSON.stringify(cloneBlocks(currentBlocks));

        if (lastRecordedRef.current === serialized) return;

        if (lastRecordedRef.current !== null) {
            const previousBlocks = JSON.parse(lastRecordedRef.current);
            const entry = snapshotFromBlocks(previousBlocks, exportBlocksToHtmlFromBlocks(previousBlocks));

            setPast((prev) => {
                const next = [...prev, entry];
                const trimmed = next.length > maxSteps ? next.slice(-maxSteps) : next;
                persistHistory(trimmed, []);
                return trimmed;
            });
            setFuture([]);
        }

        lastRecordedRef.current = serialized;
    }, 900);

    useEffect(() => {
        if (isRestoringRef.current) {
            isRestoringRef.current = false;
            return;
        }

        if (suppressHistoryRef.current) {
            suppressHistoryRef.current = false;
            if (blocks?.length) {
                lastRecordedRef.current = JSON.stringify(cloneBlocks(blocks));
            }
            return;
        }

        debouncedRecord(blocks);
    }, [blocks, debouncedRecord]);

    const updateBlocksWithoutHistory = useCallback(
        (updater) => {
            suppressHistoryRef.current = true;
            setBlocks(updater);
        },
        [setBlocks],
    );

    const applySnapshot = useCallback(
        (snapshot) => {
            if (!snapshot?.blocks) return;
            isRestoringRef.current = true;
            setActiveBlockId(null);
            setBlocks(sanitizeBlocksForEditor(cloneBlocks(snapshot.blocks)));
            lastRecordedRef.current = JSON.stringify(snapshot.blocks);
        },
        [setBlocks, setActiveBlockId],
    );

    const undo = useCallback(() => {
        if (past.length === 0) return;

        const previous = past[past.length - 1];
        const current = snapshotFromBlocks(blocksRef.current, getExportHtml());

        const newPast = past.slice(0, -1);
        const newFuture = [current, ...future].slice(0, maxSteps);

        setPast(newPast);
        setFuture(newFuture);
        applySnapshot(previous);
        persistHistory(newPast, newFuture);
    }, [past, future, maxSteps, getExportHtml, applySnapshot, persistHistory]);

    const redo = useCallback(() => {
        if (future.length === 0) return;

        const next = future[0];
        const current = snapshotFromBlocks(blocksRef.current, getExportHtml());

        const newFuture = future.slice(1);
        const newPast = [...past, current].slice(-maxSteps);

        setPast(newPast);
        setFuture(newFuture);
        applySnapshot(next);
        persistHistory(newPast, newFuture);
    }, [past, future, maxSteps, getExportHtml, applySnapshot, persistHistory]);

    return {
        undo,
        redo,
        canUndo: past.length > 0,
        canRedo: future.length > 0,
        historySteps: { undo: past.length, redo: future.length, max: maxSteps },
        updateBlocksWithoutHistory,
    };
}
