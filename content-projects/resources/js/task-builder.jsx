import { auditT } from './i18n-audit.js';
﻿import React, { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import ArticleFlowBuilder from './components/ArticleFlowBuilder';
import '../css/task-builder.css';
import '../../../content/resources/css/seo-select.css';

const rootElement = document.getElementById('seo-task-workflow-builder-root');

if (rootElement) {
    let initialFlowData = null;

    try {
        const flowJsonEl = document.getElementById('seo-task-initial-flow');
        const raw = flowJsonEl?.textContent?.trim();
        if (raw) {
            initialFlowData = JSON.parse(raw);
        }
    } catch (e) {
        console.warn('Invalid flow JSON', e);
    }

    const initialTaskName = rootElement.dataset.taskName || auditT('audit_57fef64c16e6');
    const backUrl = rootElement.dataset.backUrl || '';
    const backLabel = rootElement.dataset.backLabel || auditT('audit_8a09e03d2052');

    const AppBridge = () => {
        const [taskName, setTaskName] = useState(initialTaskName);
        const [saving, setSaving] = useState(false);
        const [toast, setToast] = useState(null);
        const saveTimeoutRef = useRef(null);
        const toastTimeoutRef = useRef(null);

        useEffect(() => {
            const finishSave = (type, event) => {
                window.clearTimeout(saveTimeoutRef.current);
                window.clearTimeout(toastTimeoutRef.current);
                setSaving(false);
                setToast({
                    type,
                    message: event.detail?.message || (
                        type === 'success'
                            ? auditT('audit_e2592ea8a6bd')
                            : auditT('audit_375c8977f76f')
                    ),
                });
                toastTimeoutRef.current = window.setTimeout(() => setToast(null), 3500);
            };

            const handleSaved = (event) => finishSave('success', event);
            const handleFailed = (event) => finishSave('error', event);

            window.addEventListener('task-flow-saved', handleSaved);
            window.addEventListener('task-flow-save-failed', handleFailed);

            return () => {
                window.removeEventListener('task-flow-saved', handleSaved);
                window.removeEventListener('task-flow-save-failed', handleFailed);
                window.clearTimeout(saveTimeoutRef.current);
                window.clearTimeout(toastTimeoutRef.current);
            };
        }, []);

        const handleSave = (name, flowJson) => {
            if (saving) {
                return;
            }

            setSaving(true);
            setToast(null);
            window.dispatchEvent(
                new CustomEvent('save-task-flow', {
                    detail: { name, flow_data: flowJson },
                }),
            );

            saveTimeoutRef.current = window.setTimeout(() => {
                setSaving(false);
                setToast({
                    type: 'error',
                    message: auditT('audit_a1087ea6cb7d'),
                });
            }, 15000);
        };

        return (
            <>
                <ArticleFlowBuilder
                    initialData={initialFlowData}
                    onSave={handleSave}
                    saving={saving}
                    taskName={taskName}
                    setTaskName={setTaskName}
                    backUrl={backUrl}
                    backLabel={backLabel}
                />

                {toast ? (
                    <div
                        role="status"
                        className={`fixed right-5 top-5 z-[200] flex min-w-72 items-center gap-3 rounded-lg border px-4 py-3 text-sm font-semibold shadow-xl ${
                            toast.type === 'success'
                                ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                                : 'border-rose-200 bg-rose-50 text-rose-800'
                        }`}
                    >
                        <span
                            className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-white ${
                                toast.type === 'success' ? 'bg-emerald-600' : 'bg-rose-600'
                            }`}
                        >
                            {toast.type === 'success' ? '✓' : '!'}
                        </span>
                        {toast.message}
                    </div>
                ) : null}
            </>
        );
    };

    const root = createRoot(rootElement);
    root.render(<AppBridge />);
}
