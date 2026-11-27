/**
 * Pure reorder of one section-level block within its section,
 * plus adjacent H2 section swap for Outline Move Up/Down.
 * Preserves block object identity (no clone).
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

export const SECTION_SWAP_CODES = Object.freeze({
    MOVED: 'moved',
    SECTION_MISSING: 'section_missing',
    TARGET_MISSING: 'target_section_missing',
    INTRO_PROTECTED: 'intro_protected',
    INVALID_DIRECTION: 'invalid_direction',
    BLOCKS_MISMATCH: 'blocks_mismatch',
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

/**
 * Swap one H2 editor section with the adjacent non-intro section.
 * Moves the whole contiguous block slice (H2 + children) without cloning blocks.
 *
 * @param {Array<{ id: string }>} blocks
 * @param {{
 *   sections: Array<{ id: string, isIntro?: boolean, blockIds?: string[] }>,
 *   sectionId: string,
 *   direction: 'prev'|'next',
 * }} opts
 * @returns {{
 *   ok: boolean,
 *   code: string,
 *   blocks: Array<{ id: string }>,
 *   sectionId: string|null,
 *   targetSectionId: string|null,
 *   fromIndex: number,
 *   toIndex: number,
 * }}
 */
export function swapAdjacentEditorSections(blocks, opts = {}) {
    const list = Array.isArray(blocks) ? blocks : [];
    const sections = Array.isArray(opts.sections) ? opts.sections : [];
    const sectionId = String(opts.sectionId ?? '').trim();
    const direction = String(opts.direction ?? '').trim().toLowerCase();

    if (direction !== 'prev' && direction !== 'next') {
        return sectionSwapFail(SECTION_SWAP_CODES.INVALID_DIRECTION, list, sectionId, null);
    }
    if (!sectionId) {
        return sectionSwapFail(SECTION_SWAP_CODES.SECTION_MISSING, list, null, null);
    }

    const currentIndex = sections.findIndex((section) => String(section?.id ?? '') === sectionId);
    if (currentIndex < 0) {
        return sectionSwapFail(SECTION_SWAP_CODES.SECTION_MISSING, list, sectionId, null);
    }

    const section = sections[currentIndex];
    if (section?.isIntro) {
        return sectionSwapFail(SECTION_SWAP_CODES.INTRO_PROTECTED, list, sectionId, null, currentIndex, currentIndex);
    }

    const targetIndex = direction === 'prev' ? currentIndex - 1 : currentIndex + 1;
    if (targetIndex < 0 || targetIndex >= sections.length) {
        return sectionSwapFail(SECTION_SWAP_CODES.TARGET_MISSING, list, sectionId, null, currentIndex, currentIndex);
    }

    const targetSection = sections[targetIndex];
    if (targetSection?.isIntro) {
        return sectionSwapFail(SECTION_SWAP_CODES.INTRO_PROTECTED, list, sectionId, targetSection.id, currentIndex, targetIndex);
    }

    const movedIds = (section.blockIds ?? []).map((id) => String(id ?? '').trim()).filter(Boolean);
    const targetIds = (targetSection.blockIds ?? []).map((id) => String(id ?? '').trim()).filter(Boolean);
    if (movedIds.length === 0 || targetIds.length === 0) {
        return sectionSwapFail(SECTION_SWAP_CODES.BLOCKS_MISMATCH, list, sectionId, targetSection.id, currentIndex, targetIndex);
    }

    const movedIdSet = new Set(movedIds);
    const fromBlocks = movedIds
        .map((blockId) => list.find((block) => String(block?.id ?? '') === blockId))
        .filter(Boolean);
    if (fromBlocks.length !== movedIds.length) {
        return sectionSwapFail(SECTION_SWAP_CODES.BLOCKS_MISMATCH, list, sectionId, targetSection.id, currentIndex, targetIndex);
    }

    const withoutMoved = list.filter((block) => !movedIdSet.has(String(block?.id ?? '')));
    const targetStart = withoutMoved.findIndex((block) => String(block?.id ?? '') === targetIds[0]);
    if (targetStart < 0) {
        return sectionSwapFail(SECTION_SWAP_CODES.TARGET_MISSING, list, sectionId, targetSection.id, currentIndex, targetIndex);
    }

    const insertAt = direction === 'prev'
        ? targetStart
        : targetStart + targetIds.length;

    const nextBlocks = [
        ...withoutMoved.slice(0, insertAt),
        ...fromBlocks,
        ...withoutMoved.slice(insertAt),
    ];

    return {
        ok: true,
        code: SECTION_SWAP_CODES.MOVED,
        blocks: nextBlocks,
        sectionId,
        targetSectionId: String(targetSection.id),
        fromIndex: currentIndex,
        toIndex: targetIndex,
    };
}

/**
 * Outline H2 Move Up/Down availability among root H2 siblings (Intro excluded).
 *
 * @param {number} index
 * @param {number} count
 * @returns {{ canMoveUp: boolean, canMoveDown: boolean }}
 */
export function outlineH2MoveAvailability(index, count) {
    const i = Number(index);
    const n = Number(count);
    if (!Number.isFinite(i) || !Number.isFinite(n) || i < 0 || n <= 0) {
        return { canMoveUp: false, canMoveDown: false };
    }

    return {
        canMoveUp: i > 0,
        canMoveDown: i < n - 1,
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

function sectionSwapFail(code, list, sectionId, targetSectionId, fromIndex = -1, toIndex = -1) {
    return {
        ok: false,
        code,
        blocks: list,
        sectionId,
        targetSectionId,
        fromIndex,
        toIndex,
    };
}

export default {
    BLOCK_WITHIN_SECTION_CODES,
    SECTION_SWAP_CODES,
    reorderBlockWithinSection,
    withinSectionMoveAvailability,
    swapAdjacentEditorSections,
    outlineH2MoveAvailability,
};
