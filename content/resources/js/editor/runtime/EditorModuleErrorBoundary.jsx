import React from 'react';

/**
 * Isolate module slot render failures from TipTap core.
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
    }

    handleRetry = () => {
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
                });
            }
            return (
                <div className="rounded border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100">
                    <div className="font-medium">
                        Module error
                        {this.props.moduleId ? `: ${this.props.moduleId}` : ''}
                    </div>
                    <button
                        type="button"
                        className="mt-1 underline"
                        onClick={this.handleRetry}
                    >
                        Retry
                    </button>
                </div>
            );
        }
        return this.props.children;
    }
}
