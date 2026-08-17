import React from 'react';
import {
    isEditorChunkLoadError,
    reloadForStaleEditorAssetsOnce,
} from './staleEditorAssets';

/**
 * Isolate module slot render failures from TipTap core.
 */
export class EditorModuleErrorBoundary extends React.Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false, error: null, reloading: false };
    }

    static getDerivedStateFromError(error) {
        return { hasError: true, error, reloading: false };
    }

    componentDidCatch(error, info) {
        // eslint-disable-next-line no-console
        console.warn(
            '[article-editor-runtime] module slot error',
            this.props.moduleId || this.props.slotName || 'unknown',
            error,
            info?.componentStack,
        );

        // Stale Vite chunk after rebuild — React.lazy keeps the failed promise; Retry alone cannot recover.
        if (isEditorChunkLoadError(error) && reloadForStaleEditorAssetsOnce()) {
            this.setState({ reloading: true });
        }
    }

    handleRetry = () => {
        if (isEditorChunkLoadError(this.state.error)) {
            if (typeof window !== 'undefined') {
                window.location.reload();
            }
            return;
        }

        this.setState({ hasError: false, error: null, reloading: false });
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
                    reloading: this.state.reloading,
                    isChunkLoadError: isEditorChunkLoadError(this.state.error),
                });
            }

            const chunkMiss = isEditorChunkLoadError(this.state.error);

            return (
                <div className="rounded border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100">
                    <div className="font-medium">
                        {this.state.reloading || chunkMiss
                            ? 'Editor assets outdated — reloading…'
                            : 'Module error'}
                        {!chunkMiss && this.props.moduleId ? `: ${this.props.moduleId}` : ''}
                    </div>
                    {!this.state.reloading ? (
                        <button
                            type="button"
                            className="mt-1 underline"
                            onClick={this.handleRetry}
                        >
                            {chunkMiss ? 'Reload page' : 'Retry'}
                        </button>
                    ) : null}
                </div>
            );
        }
        return this.props.children;
    }
}
