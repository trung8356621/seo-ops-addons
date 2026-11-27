import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import TextAlign from '@tiptap/extension-text-align';
import { Table } from '@tiptap/extension-table';
import { TableRow } from '@tiptap/extension-table-row';
import { TableCell } from '@tiptap/extension-table-cell';
import { TableHeader } from '@tiptap/extension-table-header';
import Paragraph from '@tiptap/extension-paragraph';

/**
 * Minimal TipTap stack for the dedicated Table Editor modal.
 * Intentionally excludes full Article Editor toolbar extensions.
 * resizable:false — avoids TableView DOM/CSS coupling that can break modal init.
 */
export const tableEditorExtensions = [
    StarterKit.configure({
        heading: false,
        paragraph: false,
        horizontalRule: false,
        blockquote: false,
        codeBlock: false,
        listItem: false,
        bulletList: false,
        orderedList: false,
        link: false,
        code: false,
    }),
    Paragraph,
    Link.configure({
        openOnClick: false,
        autolink: true,
        HTMLAttributes: {
            rel: 'noopener noreferrer',
        },
    }),
    TextAlign.configure({ types: ['paragraph'] }),
    Table.extend({
        addAttributes() {
            return {
                ...this.parent?.(),
                seoPrimary: {
                    default: null,
                    parseHTML: (element) => (
                        element.getAttribute('data-seo-primary-table') === '1' ? '1' : null
                    ),
                    renderHTML: (attributes) => {
                        if (attributes.seoPrimary !== '1') {
                            return {};
                        }

                        return { 'data-seo-primary-table': '1' };
                    },
                },
            };
        },
    }).configure({
        resizable: false,
        HTMLAttributes: {
            class: 'seo-editor-table',
        },
    }),
    TableRow,
    TableHeader,
    TableCell,
];
