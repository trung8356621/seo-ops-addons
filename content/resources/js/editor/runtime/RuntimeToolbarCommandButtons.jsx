import React, { useMemo } from 'react';
import {
    Bold,
    Italic,
    Underline,
    Strikethrough,
    List,
    ListOrdered,
    ListTree,
    Quote,
    AlignLeft,
    AlignCenter,
    AlignRight,
    AlignJustify,
    Minus,
    Undo2,
    Redo2,
    Table,
} from 'lucide-react';
import { executeEditorCommand } from '../../utils/editorCommands';
import { canMutateEditor } from '../../utils/editorSessionState';
import { getDefaultArticleEditorRuntime } from './defaultArticleEditorRuntime';
import { isRegistryMutationEnabled } from './editorRuntimeSelectors';
import { t } from '../../utils/i18n';

const ICON_SIZE = 18;

const ICONS = {
    undo: Undo2,
    redo: Redo2,
    bold: Bold,
    italic: Italic,
    underline: Underline,
    strike: Strikethrough,
    bullet: List,
    ordered: ListOrdered,
    quote: Quote,
    hr: Minus,
    table: Table,
    listTree: ListTree,
    alignLeft: AlignLeft,
    alignCenter: AlignCenter,
    alignRight: AlignRight,
    alignJustify: AlignJustify,
};

function ToolbarButton({ onClick, isActive = false, disabled = false, children, title }) {
    return (
        <button
            type="button"
            onClick={onClick}
            onMouseDown={(e) => e.preventDefault()}
            disabled={disabled}
            title={title}
            aria-label={title}
            className={`seo-toolbar-btn${isActive ? ' is-active' : ''}${disabled ? ' is-disabled' : ''}`}
        >
            {children}
        </button>
    );
}

/**
 * Render toolbar groups from runtime registry.
 * All groups use the same icon-only seo-toolbar-btn visual contract (no insert pills).
 */
export function RuntimeToolbarCommandButtons({
    editor,
    groups = ['history', 'inline', 'lists', 'align'],
    variant: _variant = 'format',
    runtime: runtimeProp = null,
}) {
    const items = useMemo(() => {
        const runtime = runtimeProp || getDefaultArticleEditorRuntime();
        const all = runtime.getToolbarItems();
        const wanted = new Set(groups);
        return all.filter((item) => {
            if (item?.hostOwned === true) {
                return false;
            }
            return wanted.has(String(item.group || ''));
        });
    }, [groups, runtimeProp]);

    if (!editor || !items.length) return null;

    const runtime = runtimeProp || getDefaultArticleEditorRuntime();
    const runtimeContext = runtime?.getContext?.() ?? null;
    const mutationLocked = !editor.isEditable
        || !canMutateEditor()
        || (runtimeContext ? !runtime.isMutationUiEnabled?.() : false);
    const lockTitle = t('editor_locked_mutation_tooltip');

    const run = (item) => {
        if (mutationLocked || !isRegistryMutationEnabled(item, runtimeContext)) {
            return;
        }
        const payload = typeof item.payloadFactory === 'function'
            ? item.payloadFactory({ editor })
            : (item.payload || {});
        executeEditorCommand(item.command, { editor, ...payload }, { notifyOnFailure: true });
    };

    const byGroup = new Map();
    for (const item of items) {
        const g = String(item.group || 'misc');
        if (!byGroup.has(g)) byGroup.set(g, []);
        byGroup.get(g).push(item);
    }

    return (
        <>
            {groups.map((group) => {
                const groupItems = byGroup.get(group) || [];
                if (!groupItems.length) return null;
                return (
                    <div key={group} className="seo-toolbar-group">
                        {groupItems.map((item) => {
                            const Icon = ICONS[item.iconKey] || null;
                            if (!Icon) {
                                return null;
                            }
                            const baseTitle = t(item.labelKey || item.command);
                            let disabled = mutationLocked || !isRegistryMutationEnabled(item, runtimeContext);
                            if (!disabled && item.canKey === 'undo') disabled = !editor.can().undo();
                            if (!disabled && item.canKey === 'redo') disabled = !editor.can().redo();
                            const title = disabled && mutationLocked ? lockTitle : baseTitle;
                            let isActive = false;
                            if (item.activeMark) {
                                isActive = editor.isActive(item.activeMark);
                            } else if (item.activeAttrs) {
                                isActive = editor.isActive(item.activeAttrs);
                            }

                            return (
                                <ToolbarButton
                                    key={item.id}
                                    onClick={() => run(item)}
                                    isActive={isActive}
                                    disabled={disabled}
                                    title={title}
                                >
                                    <Icon size={ICON_SIZE} aria-hidden />
                                </ToolbarButton>
                            );
                        })}
                    </div>
                );
            })}
        </>
    );
}
