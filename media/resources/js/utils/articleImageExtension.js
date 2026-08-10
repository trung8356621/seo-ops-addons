import { Node, mergeAttributes } from '@tiptap/core';

const ALIGN_CLASSES = {
    none: '',
    left: 'alignleft',
    center: 'aligncenter',
    right: 'alignright',
    full: 'alignfull',
};

function alignFromElement(el) {
    const cls = el.className || '';
    if (cls.includes('alignfull')) return 'full';
    if (cls.includes('alignright')) return 'right';
    if (cls.includes('aligncenter')) return 'center';
    if (cls.includes('alignleft')) return 'left';
    return 'none';
}

function figureClassForAlign(align) {
    return ALIGN_CLASSES[align] || '';
}

export const ArticleImage = Node.create({
    name: 'articleImage',
    group: 'block',
    atom: true,
    selectable: true,
    draggable: true,

    addAttributes() {
        return {
            src: { default: null },
            alt: { default: '' },
            title: { default: '' },
            caption: { default: '' },
            align: { default: 'center' },
            size: { default: 'full' },
        };
    },

    parseHTML() {
        return [
            {
                tag: 'figure',
                getAttrs: (element) => {
                    const img = element.querySelector('img');
                    if (!img?.getAttribute('src')) return false;

                    const figcaption = element.querySelector('figcaption');

                    return {
                        src: img.getAttribute('src'),
                        alt: img.getAttribute('alt') ?? '',
                        title: img.getAttribute('title') ?? '',
                        caption: figcaption?.textContent?.trim() ?? '',
                        align: alignFromElement(element),
                    };
                },
            },
            {
                tag: 'img[src]',
                getAttrs: (element) => ({
                    src: element.getAttribute('src'),
                    alt: element.getAttribute('alt') ?? '',
                    title: element.getAttribute('title') ?? '',
                    caption: '',
                    align: alignFromElement(element),
                }),
            },
        ];
    },

    renderHTML({ node }) {
        const { src, alt, title, caption, align } = node.attrs;
        const figureClass = ['seo-article-figure', figureClassForAlign(align)].filter(Boolean).join(' ');

        const imgAttrs = {
            src,
            alt: alt || undefined,
            title: title || undefined,
            draggable: false,
        };

        const content = [['img', mergeAttributes(imgAttrs)]];

        if (caption) {
            content.push(['figcaption', { class: 'wp-element-caption' }, caption]);
        }

        return [
            'figure',
            mergeAttributes({
                class: figureClass || undefined,
                'data-node': 'article-image',
            }),
            ...content,
        ];
    },

    addCommands() {
        return {
            setArticleImage:
                (attrs) =>
                ({ commands }) =>
                    commands.insertContent({
                        type: this.name,
                        attrs,
                    }),
        };
    },
});

export function getArticleImageSelection(editor) {
    if (!editor) return null;

    const { selection } = editor.state;
    if (selection.node?.type.name === 'articleImage') {
        return { pos: selection.from, attrs: selection.node.attrs };
    }

    return null;
}

export function selectArticleImageAtDom(editor, domTarget) {
    if (!editor || !domTarget) return false;

    const figure = domTarget.closest?.('figure[data-node="article-image"], figure.seo-article-figure');
    const img = domTarget.tagName === 'IMG' ? domTarget : figure?.querySelector?.('img');
    const el = figure || img;

    if (!el || !editor.view.dom.contains(el)) return false;

    try {
        const pos = editor.view.posAtDOM(el, 0);
        const node = editor.state.doc.nodeAt(pos);
        if (node?.type.name === 'articleImage') {
            editor.chain().focus().setNodeSelection(pos).run();
            return true;
        }
    } catch {
        /* posAtDOM can fail for nested structures */
    }

    return false;
}
