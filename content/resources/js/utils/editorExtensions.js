import StarterKit from '@tiptap/starter-kit';
import { Extension } from '@tiptap/core';
import Image from '@tiptap/extension-image';
import Heading from '@tiptap/extension-heading';
import Link from '@tiptap/extension-link';
import Underline from '@tiptap/extension-underline';
import TextAlign from '@tiptap/extension-text-align';
import Highlight from '@tiptap/extension-highlight';
import { TextStyle } from '@tiptap/extension-text-style';
import { Color } from '@tiptap/extension-color';
import Subscript from '@tiptap/extension-subscript';
import Superscript from '@tiptap/extension-superscript';
import { Table } from '@tiptap/extension-table';
import { TableRow } from '@tiptap/extension-table-row';
import { TableCell } from '@tiptap/extension-table-cell';
import { TableHeader } from '@tiptap/extension-table-header';
import Paragraph from '@tiptap/extension-paragraph';
import { SEO_EDITOR_LINK_CLASS } from './articleEditorTransientMarkup';
import { SEO_LINK_DEFAULT_ATTRS } from './inlineLinkNormalizer';
import { handleLinkBoundaryKeydown } from './editorLinkCommands';
import { executeEditorCommand } from './editorCommands';

/** Giữ class / data attribute trên paragraph (vd. placeholder FAQ). */
const PreservedParagraph = Paragraph.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            class: {
                default: null,
                parseHTML: (element) => element.getAttribute('class'),
                renderHTML: (attributes) => {
                    if (!attributes.class) {
                        return {};
                    }

                    return { class: attributes.class };
                },
            },
            'data-omi-faq': {
                default: null,
                parseHTML: (element) => element.getAttribute('data-omi-faq'),
                renderHTML: (attributes) => {
                    if (!attributes['data-omi-faq']) {
                        return {};
                    }

                    return { 'data-omi-faq': attributes['data-omi-faq'] };
                },
            },
            'data-cta-type': {
                default: null,
                parseHTML: (element) => element.getAttribute('data-cta-type'),
                renderHTML: (attributes) => {
                    if (!attributes['data-cta-type']) {
                        return {};
                    }

                    return { 'data-cta-type': attributes['data-cta-type'] };
                },
            },
        };
    },
});

/** Giữ class Tailwind / WP trên h1–h6 khi round-trip HTML. */
const PreservedHeading = Heading.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            class: {
                default: null,
                parseHTML: (element) => element.getAttribute('class'),
                renderHTML: (attributes) => {
                    if (!attributes.class) {
                        return {};
                    }

                    return { class: attributes.class };
                },
            },
            outlineVisible: {
                default: true,
                parseHTML: (element) => element.getAttribute('data-outline-visible') !== 'false',
                renderHTML: (attributes) => {
                    if (attributes.outlineVisible === false) {
                        return { 'data-outline-visible': 'false' };
                    }

                    return {};
                },
            },
            headingId: {
                default: null,
                parseHTML: (element) => element.getAttribute('data-omi-heading-id'),
                renderHTML: (attributes) => {
                    if (!attributes.headingId) {
                        return {};
                    }

                    return { 'data-omi-heading-id': attributes.headingId };
                },
            },
        };
    },
});

const SeoEditorImage = Image.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            'data-seo-media-id': {
                default: null,
                parseHTML: (element) => element.getAttribute('data-seo-media-id'),
                renderHTML: (attributes) => {
                    if (!attributes['data-seo-media-id']) {
                        return {};
                    }

                    return { 'data-seo-media-id': attributes['data-seo-media-id'] };
                },
            },
        };
    },
});

/**
 * Link mark: priority cao (outermost), spanning, attrs parse về default thống nhất
 * để text node liền kề (thường + bold) không bị serializer tách thành nhiều <a>.
 */
const SeoEditorLink = Link.extend({
    priority: 1000,

    spanning: true,

    // Caret at end of link must not keep expanding the mark (sticky link).
    inclusive: false,

    addKeyboardShortcuts() {
        return {
            'Mod-Shift-k': () => {
                const result = executeEditorCommand('remove_link_keep_text', { editor: this.editor }, { notifyOnFailure: false });
                return Boolean(result?.ok && result.transaction_applied);
            },
            ArrowRight: () => handleLinkBoundaryKeydown(this.editor, { key: 'ArrowRight', preventDefault() {} }),
            ' ': () => {
                executeEditorCommand('exit_link_at_boundary', { editor: this.editor }, { notifyOnFailure: false });
                return false;
            },
        };
    },

    addAttributes() {
        const defaults = {
            target: SEO_LINK_DEFAULT_ATTRS.target,
            rel: SEO_LINK_DEFAULT_ATTRS.rel,
            class: SEO_LINK_DEFAULT_ATTRS.class,
            ...this.options.HTMLAttributes,
        };

        return {
            href: {
                default: null,
                parseHTML: (element) => element.getAttribute('href'),
            },
            target: {
                default: defaults.target ?? null,
                parseHTML: (element) => element.getAttribute('target') || defaults.target || null,
            },
            rel: {
                default: defaults.rel ?? null,
                parseHTML: (element) => element.getAttribute('rel') || defaults.rel || null,
            },
            class: {
                default: defaults.class ?? null,
                parseHTML: (element) => element.getAttribute('class') || defaults.class || null,
            },
            title: {
                default: null,
                parseHTML: (element) => element.getAttribute('title'),
            },
        };
    },
}).configure({
    openOnClick: false,
    enableClickSelection: true,
    autolink: true,
    HTMLAttributes: {
        target: SEO_LINK_DEFAULT_ATTRS.target,
        rel: SEO_LINK_DEFAULT_ATTRS.rel,
        class: SEO_EDITOR_LINK_CLASS,
    },
});

const ArticleEditorStructureShortcuts = Extension.create({
    name: 'articleEditorStructureShortcuts',
    addKeyboardShortcuts() {
        return {
            'Alt-3': () => {
                const result = executeEditorCommand(
                    'split_selection_to_heading',
                    { editor: this.editor, level: 3 },
                    { notifyOnFailure: false },
                );
                return Boolean(result?.ok && result.transaction_applied);
            },
            'Alt-4': () => {
                const result = executeEditorCommand(
                    'split_selection_to_heading',
                    { editor: this.editor, level: 4 },
                    { notifyOnFailure: false },
                );
                return Boolean(result?.ok && result.transaction_applied);
            },
        };
    },
});

export const articleEditorExtensions = [
    StarterKit.configure({
        heading: false,
        paragraph: false,
        horizontalRule: true,
        link: false,
        underline: false,
    }),
    PreservedParagraph,
    PreservedHeading.configure({ levels: [1, 2, 3, 4, 5, 6] }),
    ArticleEditorStructureShortcuts,
    Underline,
    Subscript,
    Superscript,
    Highlight.configure({ multicolor: false }),
    TextStyle,
    Color,
    SeoEditorLink,
    TextAlign.configure({ types: ['heading', 'paragraph'] }),
    Table.configure({ resizable: true }),
    TableRow,
    TableHeader,
    TableCell,
    SeoEditorImage.configure({
        inline: false,
        allowBase64: false,
        HTMLAttributes: {
            class: 'seo-editor-inline-image max-w-full h-auto my-4 rounded-lg border border-gray-200 dark:border-slate-800 shadow-sm',
        },
    }),
];
