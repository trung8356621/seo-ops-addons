/**
 * Phase 4 — cancelable utility scheduler (word count, outline, hash, draft).
 * No large state library — latest document version wins; stale tasks drop results.
 */

/**
 * @typedef {'high'|'normal'|'idle'} UtilityPriority
 * @typedef {{
 *   id: string,
 *   run: (ctx: { version: number, signal: AbortSignal }) => void|Promise<void>,
 *   debounceMs?: number,
 *   priority?: UtilityPriority,
 * }} UtilityTask
 */

export function createArticleEditorUtilityScheduler(options = {}) {
    const perfEnabled = Boolean(options.perfDebug);
    /** @type {Map<string, { timer: ReturnType<typeof setTimeout>|number|null, idleId: number|null, controller: AbortController|null, version: number }>} */
    const pending = new Map();
    let documentVersion = 0;
    let destroyed = false;

    const mark = (name, startMs) => {
        if (!perfEnabled || typeof performance === 'undefined') {
            return;
        }
        try {
            performance.measure(name, {
                start: startMs,
                end: performance.now(),
            });
        } catch {
            // ignore
        }
    };

    const bumpVersion = () => {
        documentVersion += 1;
        return documentVersion;
    };

    const cancel = (taskId) => {
        const entry = pending.get(taskId);
        if (!entry) {
            return;
        }
        if (entry.timer != null) {
            clearTimeout(entry.timer);
        }
        if (entry.idleId != null && typeof cancelIdleCallback === 'function') {
            cancelIdleCallback(entry.idleId);
        }
        entry.controller?.abort();
        pending.delete(taskId);
    };

    const cancelAll = () => {
        for (const id of [...pending.keys()]) {
            cancel(id);
        }
    };

    /**
     * @param {UtilityTask} task
     */
    const schedule = (task) => {
        if (destroyed || !task?.id || typeof task.run !== 'function') {
            return documentVersion;
        }

        cancel(task.id);
        const version = documentVersion;
        const controller = new AbortController();
        const debounceMs = Math.max(0, Number(task.debounceMs ?? 0));
        const priority = task.priority ?? 'normal';

        const execute = () => {
            const start = typeof performance !== 'undefined' ? performance.now() : 0;
            pending.delete(task.id);
            if (destroyed || controller.signal.aborted || version !== documentVersion) {
                if (perfEnabled) {
                    console.debug('[seo-editor-util] cancelled_stale_task', task.id, { version, documentVersion });
                }
                return;
            }

            Promise.resolve(task.run({ version, signal: controller.signal }))
                .then(() => {
                    mark(`seo_editor_util_${task.id}_ms`, start);
                })
                .catch((error) => {
                    if (error?.name === 'AbortError') {
                        return;
                    }
                    if (perfEnabled) {
                        console.debug('[seo-editor-util] task_error', task.id, error?.message ?? error);
                    }
                });
        };

        /** @type {{ timer: ReturnType<typeof setTimeout>|number|null, idleId: number|null, controller: AbortController, version: number }} */
        const entry = {
            timer: null,
            idleId: null,
            controller,
            version,
        };
        pending.set(task.id, entry);

        const startAfterDebounce = () => {
            if (destroyed || controller.signal.aborted || version !== documentVersion) {
                pending.delete(task.id);
                return;
            }
            if (priority === 'idle' && typeof requestIdleCallback === 'function') {
                entry.idleId = requestIdleCallback(() => execute(), { timeout: Math.max(debounceMs, 1000) });
                return;
            }
            execute();
        };

        if (debounceMs > 0) {
            entry.timer = setTimeout(startAfterDebounce, debounceMs);
        } else {
            startAfterDebounce();
        }

        return version;
    };

    const destroy = () => {
        destroyed = true;
        cancelAll();
    };

    return {
        bumpVersion,
        getVersion: () => documentVersion,
        schedule,
        cancel,
        cancelAll,
        destroy,
    };
}
