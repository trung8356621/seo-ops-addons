/**
 * Pure reorder of one section-level block within its section.
 * Does not cross section boundaries. Preserves block object identity (no clone).
 */

export const BLOCK_WITHIN_SECTION_CODES = Object.freeze({
    MOVED: 'moved',
    ALREADY_FIRST: 'block_already_first',
    ALREADY_LAST: 'block_already_last',
    TARGET_MISSING: 'editor_target_missing',
    SECTION_MISMATCH: 'section_mismatch',
    SECTION_MISSING: 'section_missing',
    INVALID_DIRECTION: 'invalid_direction',
});

/**
 * @param {Array<{ id: string }>} blocks
 * @param {{
 *   blockId: string,
 *   direction: 'up'|'down',
 *   sectionBlockIds: string[],
 *   sectionId?: string|null,
 * }} opts
 * @returns {{
 *   ok: boolean,
 *   code: string,
 *   blocks: Array|{ id: string }[],
 *   blockId: string,
 *   sectionId: string|null,
 *   fromIndex: number,
 *   toIndex: number,
 * }}
 */
export function reorderBlockWithinSection(blocks, opts = {}) {
    const list = Array.isArray(blocks) ? blocks : [];
    const blockId = String(opts.blockId ?? '').trim();
    const direction = String(opts.direction ?? '').trim().toLowerCase();
    const sectionBlockIds = Array.isArray(opts.sectionBlockIds)
        ? opts.sectionBlockIds.map((id) => String(id ?? '').trim()).filter(Boolean)
        : [];
    const sectionId = opts.sectionId != null ? String(opts.sectionId).trim() || null : null;

    if (!blockId) {
        return emptyFail(BLOCK_WITHIN_SECTION_CODES.TARGET_MISSING, list, blockId, sectionId);
    }
    if (direction !== 'up' && direction !== 'down') {
        return emptyFail(BLOCK_WITHIN_SECTION_CODES.INVALID_DIRECTION, list, blockId, sectionId);
    }
    if (sectionBlockIds.length === 0) {
        return emptyFail(BLOCK_WITHIN_SECTION_CODES.SECTION_MISSING, list, blockId, sectionId);
    }

    const localIndex = sectionBlockIds.indexOf(blockId);
    if (localIndex < 0) {
        return emptyFail(BLOCK_WITHIN_SECTION_CODES.SECTION_MISMATCH, list, blockId, sectionId);
    }

    if (direction === 'up' && localIndex === 0) {
        return {
            ok: false,
            code: BLOCK_WITHIN_SECTION_CODES.ALREADY_FIRST,
            blocks: list,
            blockId,
            sectionId,
            fromIndex: localIndex,
            toIndex: localIndex,
        };
    }

    if (direction === 'down' && localIndex === sectionBlockIds.length - 1) {
        return {
            ok: false,
            code: BLOCK_WITHIN_SECTION_CODES.ALREADY_LAST,
            blocks: list,
            blockId,
            sectionId,
            fromIndex: localIndex,
            toIndex: localIndex,
        };
    }

    const swapIndex = direction === 'up' ? localIndex - 1 : localIndex + 1;
    const nextSectionIds = sectionBlockIds.slice();
    const tmp = nextSectionIds[localIndex];
    nextSectionIds[localIndex] = nextSectionIds[swapIndex];
    nextSectionIds[swapIndex] = tmp;

    const firstAbs = list.findIndex((block) => String(block?.id ?? '') === sectionBlockIds[0]);
    const lastAbs = list.findIndex(
        (block) => String(block?.id ?? '') === sectionBlockIds[sectionBlockIds.length - 1],
    );
    if (firstAbs < 0 || lastAbs < 0 || lastAbs < firstAbs) {
        return emptyFail(BLOCK_WITHIN_SECTION_CODES.SECTION_MISSING, list, blockId, sectionId);
    }

    // Section slice must match sectionBlockIds (contiguous ownership).
    const slice = list.slice(firstAbs, lastAbs + 1);
    if (slice.length !== sectionBlockIds.length) {
        return emptyFail(BLOCK_WITHIN_SECTION_CODES.SECTION_MISMATCH, list, blockId, sectionId);
    }
    for (let i = 0; i < sectionBlockIds.length; i += 1) {
        if (String(slice[i]?.id ?? '') !== sectionBlockIds[i]) {
            return emptyFail(BLOCK_WITHIN_SECTION_CODES.SECTION_MISMATCH, list, blockId, sectionId);
        }
    }

    const byId = new Map();
    for (const block of slice) {
        byId.set(String(block.id), block);
    }

    const reorderedSlice = [];
    for (const id of nextSectionIds) {
        const block = byId.get(id);
        if (!block) {
            return emptyFail(BLOCK_WITHIN_SECTION_CODES.TARGET_MISSING, list, blockId, sectionId);
        }
        reorderedSlice.push(block);
    }

    const nextBlocks = [
        ...list.slice(0, firstAbs),
        ...reorderedSlice,
        ...list.slice(lastAbs + 1),
    ];

    return {
        ok: true,
        code: BLOCK_WITHIN_SECTION_CODES.MOVED,
        blocks: nextBlocks,
        blockId,
        sectionId,
        fromIndex: localIndex,
        toIndex: swapIndex,
    };
}

/**
 * @param {string[]} sectionBlockIds
 * @param {string} blockId
 * @returns {{ canMoveUp: boolean, canMoveDown: boolean, index: number }}
 */
export function withinSectionMoveAvailability(sectionBlockIds, blockId) {
    const ids = Array.isArray(sectionBlockIds) ? sectionBlockIds : [];
    const id = String(blockId ?? '').trim();
    const index = ids.indexOf(id);
    if (index < 0) {
        return { canMoveUp: false, canMoveDown: false, index: -1 };
    }

    return {
        canMoveUp: index > 0,
        canMoveDown: index < ids.length - 1,
        index,
    };
}

function emptyFail(code, list, blockId, sectionId) {
    return {
        ok: false,
        code,
        blocks: list,
        blockId,
        sectionId,
        fromIndex: -1,
        toIndex: -1,
    };
}

export default {
    BLOCK_WITHIN_SECTION_CODES,
    reorderBlockWithinSection,
    withinSectionMoveAvailability,
};
