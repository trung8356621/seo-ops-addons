import React, { useEffect, useMemo } from 'react';
import { useEditor, EditorContent } from '@tiptap/react';
import { getDefaultArticleEditorRuntime } from '../editor/runtime/defaultArticleEditorRuntime';
import { answerHtmlForEditor } from '../utils/faqAnswerHtml';
import BlockFormatToolbar from './BlockFormatToolbar';

export default function FaqAnswerEditor({ html, onChange, onFocus }) {
    const initialContent = useMemo(() => answerHtmlForEditor(html), []);

    const editor = useEditor({
        extensions: getDefaultArticleEditorRuntime().getDocumentExtensions(),
        content: initialContent,
        onUpdate: ({ editor: ed }) => {
            onChange(ed.getHTML());
        },
        onFocus: () => onFocus?.(),
        editorProps: {
            attributes: {
                class: 'prose prose-slate max-w-none dark:prose-invert min-h-[100px] focus:outline-none tiptap-editor-content seo-faq-answer-editor',
            },
        },
    });

    useEffect(() => {
        if (!editor) return;
        const next = answerHtmlForEditor(html);
        const current = editor.getHTML();
        if (current !== next) {
            editor.commands.setContent(next, false);
        }
    }, [html, editor]);

    useEffect(() => () => editor?.destroy(), [editor]);

    if (!editor) {
        return <div className="seo-faq-answer-loading text-sm text-gray-400 italic">Đang tải editor…</div>;
    }

    return (
        <div className="seo-faq-answer-wrap border border-gray-200 dark:border-gray-700 rounded-md bg-white dark:bg-gray-900 overflow-hidden">
            <BlockFormatToolbar editor={editor} canDelete={false} />
            <EditorContent editor={editor} />
        </div>
    );
}
