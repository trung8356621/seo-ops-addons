import React, { useEffect, useMemo, useRef } from 'react';
import { useEditor, EditorContent } from '@tiptap/react';
import { getDefaultArticleEditorRuntime } from '../editor/runtime/defaultArticleEditorRuntime';
import { answerHtmlForEditor } from '../utils/faqAnswerHtml';
import BlockFormatToolbar from './BlockFormatToolbar';

const FAQ_ANSWER_EDITOR_PROPS = Object.freeze({
    attributes: Object.freeze({
        class: 'prose prose-slate max-w-none dark:prose-invert min-h-[100px] focus:outline-none tiptap-editor-content seo-faq-answer-editor',
    }),
});

/**
 * TipTap getHTML() throws `Cannot read properties of null (reading 'cached')`
 * when the editor view/schema is already torn down (unmount / remount race).
 *
 * @param {import('@tiptap/core').Editor|null|undefined} editor
 * @returns {string|null}
 */
export function safeFaqEditorHtml(editor) {
    if (!editor || editor.isDestroyed || !editor.view || !editor.state?.schema) {
        return null;
    }
    try {
        return String(editor.getHTML() ?? '');
    } catch {
        return null;
    }
}

export default function FaqAnswerEditor({ html, onChange, onFocus }) {
    const initialContent = useMemo(() => answerHtmlForEditor(html), []);
    const onChangeRef = useRef(onChange);
    const onFocusRef = useRef(onFocus);
    onChangeRef.current = onChange;
    onFocusRef.current = onFocus;

    const documentExtensions = useMemo(
        () => getDefaultArticleEditorRuntime().getDocumentExtensions(),
        [],
    );

    // TipTap v3: unstable editorProps identity → setOptions every render → #185.
    const editor = useEditor({
        extensions: documentExtensions,
        content: initialContent,
        editorProps: FAQ_ANSWER_EDITOR_PROPS,
        onUpdate: ({ editor: ed }) => {
            const next = safeFaqEditorHtml(ed);
            if (next == null) {
                return;
            }
            onChangeRef.current?.(next);
        },
        onFocus: () => onFocusRef.current?.(),
    }, []);

    useEffect(() => {
        if (!editor || editor.isDestroyed || !editor.view) {
            return;
        }
        const next = answerHtmlForEditor(html);
        const current = safeFaqEditorHtml(editor);
        if (current == null || current === next) {
            return;
        }
        try {
            editor.commands.setContent(next, false);
        } catch {
            // Ignore setContent races during unmount/remount.
        }
    }, [html, editor]);

    useEffect(() => () => {
        if (editor && !editor.isDestroyed) {
            try {
                editor.destroy();
            } catch {
                // ignore
            }
        }
    }, [editor]);

    if (!editor || editor.isDestroyed) {
        return <div className="seo-faq-answer-loading text-sm text-gray-400 italic">Đang tải editor…</div>;
    }

    return (
        <div className="seo-faq-answer-wrap border border-gray-200 dark:border-gray-700 rounded-md bg-white dark:bg-gray-900 overflow-hidden">
            <BlockFormatToolbar editor={editor} canDelete={false} />
            <EditorContent editor={editor} />
        </div>
    );
}
