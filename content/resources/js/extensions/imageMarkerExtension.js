import { Node, mergeAttributes } from '@tiptap/core';

/**
 * Giữ vị trí ảnh trong TipTap dưới dạng marker — ảnh chỉnh ở BlockImagesPanel.
 */
export const ImageMarker = Node.create({
    name: 'imageMarker',
    group: 'block',
    atom: true,
    selectable: false,
    draggable: false,

    addAttributes() {
        return {
            id: {
                default: null,
                parseHTML: (element) => element.getAttribute('data-seo-image-marker'),
                renderHTML: (attributes) =>
                    attributes.id ? { 'data-seo-image-marker': attributes.id } : {},
            },
        };
    },

    parseHTML() {
        return [{ tag: 'p[data-seo-image-marker]' }];
    },

    renderHTML({ HTMLAttributes }) {
        return [
            'p',
            mergeAttributes(HTMLAttributes, { class: 'seo-image-marker' }),
            '\u00a0',
        ];
    },
});
