/**
 * Immutable blocks[] replacement for canonical article-block splits.
 * No ProseMirror, no React. Safe for Node unit tests.
 */

/**
 * @param {Array<object>} blocks
 * @param {{
 *   sourceBlockId?: string,
 *   blockId?: string,
 *   replacements?: Array<string|{content?: string}>,
 *   contents?: string[],
 *   preserveSourceId?: boolean,
 *   createBlock?: () => object,
 * }} payload
 * @returns {{
 *   ok: boolean,
 *   reason: string|null,
 *   blocks: Array<object>,
 *   sourceIndex: number,
 *   createdIds: string[],
 *   sourceBlockId: string,
 *   beforeCount: number,
 *   afterCount: number,
 *   unchanged: boolean,
 * }}
 */
export function applyReplaceBlocksAt(blocks, payload = {}) {
    const list = Array.isArray(blocks) ? blocks : [];
    const sourceBlockId = String(payload.sourceBlockId ?? payload.blockId ?? '').trim();
    const raw = Array.isArray(payload.replacements) ? payload.replacements : payload.contents;
    const htmls = (Array.isArray(raw) ? raw : [])
        .map((item) => (typeof item === 'string' ? item : String(item?.content ?? '')))
        .map((html) => html.trim())
        .filter((html) => html !== '');

    if (!sourceBlockId) {
        return failResult(list, 'invalid_payload', sourceBlockId);
    }
    if (htmls.length === 0) {
        return failResult(list, 'empty_replacements', sourceBlockId);
    }

    const sourceIndex = list.findIndex((block) => String(block?.id ?? '') === sourceBlockId);
    if (sourceIndex < 0) {
        return failResult(list, 'source_missing', sourceBlockId);
    }

    const source = list[sourceIndex];
    const preserveSourceId = payload.preserveSourceId !== false;
    const createBlock = typeof payload.createBlock === 'function' ? payload.createBlock : null;
    const epoch = Date.now();
    const created = htmls.map((html, index) => {
        if (preserveSourceId && index === 0) {
            return {
                ...source,
                content: html,
                editorDocument: undefined,
                editor_document: undefined,
                editorEpoch: epoch,
            };
        }
        const fresh = createBlock ? createBlock() : {
            id: `classic_${epoch}_${index}_${Math.random().toString(36).slice(2, 9)}`,
            type: 'text',
            isWp: false,
            prefix: '',
            content: '',
            suffix: '',
        };
        return {
            ...fresh,
            content: html,
            editorDocument: undefined,
            editor_document: undefined,
            editorEpoch: epoch,
        };
    });

    const createdIds = created.map((block) => String(block.id ?? ''));
    if (createdIds.some((id) => id === '') || new Set(createdIds).size !== createdIds.length) {
        return failResult(list, 'duplicate_ids', sourceBlockId);
    }

    const next = [
        ...list.slice(0, sourceIndex),
        ...created,
        ...list.slice(sourceIndex + 1),
    ];
    const unchanged = next.length === list.length
        && next.every((block, index) => (
            block === list[index]
            || (block?.id === list[index]?.id && block?.content === list[index]?.content)
        ));

    if (unchanged) {
        return {
            ok: false,
            reason: 'no_change',
            blocks: list,
            sourceIndex,
            createdIds,
            sourceBlockId,
            beforeCount: list.length,
            afterCount: list.length,
            unchanged: true,
        };
    }

    return {
        ok: true,
        reason: null,
        blocks: next,
        sourceIndex,
        createdIds,
        sourceBlockId,
        beforeCount: list.length,
        afterCount: next.length,
        unchanged: false,
    };
}

function failResult(list, reason, sourceBlockId) {
    return {
        ok: false,
        reason,
        blocks: list,
        sourceIndex: -1,
        createdIds: [],
        sourceBlockId,
        beforeCount: list.length,
        afterCount: list.length,
        unchanged: true,
    };
}

export default { applyReplaceBlocksAt };
