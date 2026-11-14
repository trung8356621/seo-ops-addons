import React from 'react';
import {
    isEditorChunkLoadError,
    requestManualEditorReload,
} from './staleEditorAssets';

/**
 * Isolate module slot render failures from TipTap core.
 * Never auto-reloads the page — stale chunks / network errors show a manual action.
 */
export class EditorModuleErrorBoundary extends React.Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false, error: null };
    }

    static getDerivedStateFromError(error) {
        return { hasError: true, error };
    }

    componentDidCatch(error, info) {
        // eslint-disable-next-line no-console
        console.warn(
            '[article-editor-runtime] module slot error',
            this.props.moduleId || this.props.slotName || 'unknown',
            error,
            info?.componentStack,
        );
        // Do not call location.reload() here — clear-on-boot + auto-reload caused infinite loops.
    }

    handleRetry = () => {
        if (isEditorChunkLoadError(this.state.error)) {
            requestManualEditorReload();
            return;
        }

        this.setState({ hasError: false, error: null });
        if (typeof this.props.onRetry === 'function') {
            this.props.onRetry();
        }
    };

    render() {
        if (this.state.hasError) {
            if (typeof this.props.fallback === 'function') {
                return this.props.fallback({
                    error: this.state.error,
                    retry: this.handleRetry,
                    moduleId: this.props.moduleId,
                    reloading: false,
                    isChunkLoadError: isEditorChunkLoadError(this.state.error),
                });
            }

            const chunkMiss = isEditorChunkLoadError(this.state.error);

            return (
                <div className="rounded border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100">
                    <div className="font-medium">
                        {chunkMiss
                            ? 'Editor assets outdated'
                            : 'Module error'}
                        {!chunkMiss && this.props.moduleId ? `: ${this.props.moduleId}` : ''}
                    </div>
                    <p className="mt-1 opacity-90">
                        {chunkMiss
                            ? 'Reload when ready. Unsaved changes will ask for confirmation first.'
                            : 'Retry this panel without reloading the whole page.'}
                    </p>
                    <button
                        type="button"
                        className="mt-1 underline"
                        onClick={this.handleRetry}
                    >
                        {chunkMiss ? 'Reload page' : 'Retry'}
                    </button>
                </div>
            );
        }
        return this.props.children;
    }
}
