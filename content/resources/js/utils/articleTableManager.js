/**
 * Article Table Manager — inventory / primary / replace against editor blocks.
 * Primary marker: data-seo-primary-table="1" (document-level, no DB table).
 * Preferred storage: type === 'table' blocks. Legacy inline <table> in text still supported.
 */

export const PRIMARY_TABLE_ATTR = 'data-seo-primary-table';
export const PRIMARY_TABLE_VALUE = '1';

export const DEFAULT_TABLE_HTML = [
    '<table>',
    '<thead><tr><th>Header 1</th><th>Header 2</th><th>Header 3</th></tr></thead>',
    '<tbody>',
    '<tr><td></td><td></td><td></td></tr>',
    '<tr><td></td><td></td><td></td></tr>',
    '</tbody>',
    '</table>',
].join('');

function parseHtml(html) {
    return new DOMParser().parseFromString(String(html ?? ''), 'text/html');
}

function isTopLevelTable(table) {
    let parent = table.parentElement;
    while (parent) {
        if (parent.tagName?.toLowerCase() === 'table') {
            return false;
        }
        parent = parent.parentElement;
    }
    return true;
}

function tableMetrics(tableEl) {
    const rows = Array.from(
        tableEl.querySelectorAll(':scope > tbody > tr, :scope > thead > tr, :scope > tr'),
    );
    let headerRows = 0;
    let maxCols = 0;
    let filledRows = 0;
    rows.forEach((row) => {
        const cells = Array.from(row.children).filter((c) => {
            const tag = c.tagName.toLowerCase();
            return tag === 'td' || tag === 'th';
        });
        if (cells.length === 0) {
            return;
        }
        filledRows += 1;
        maxCols = Math.max(maxCols, cells.length);
        if (cells.some((c) => c.tagName.toLowerCase() === 'th')) {
            headerRows += 1;
        }
    });

    return {
        data_rows: Math.max(0, filledRows - (headerRows > 0 ? 1 : 0)),
        columns: maxCols,
        row_count: rows.length,
    };
}

function tablePreviewText(tableEl) {
    const text = String(tableEl.textContent ?? '').replace(/\s+/g, ' ').trim();
    return text.length > 80 ? `${text.slice(0, 80)}…` : text;
}

export function isPrimaryTableHtml(html) {
    const doc = parseHtml(html);
    const table = Array.from(doc.body.querySelectorAll('table')).find(isTopLevelTable);
    return table?.getAttribute(PRIMARY_TABLE_ATTR) === PRIMARY_TABLE_VALUE;
}

export function withPrimaryTableAttr(tableHtml, isPrimary) {
    const doc = parseHtml(tableHtml);
    const table = Array.from(doc.body.querySelectorAll('table')).find(isTopLevelTable);
    if (!table) {
        return String(tableHtml ?? '');
    }
    if (isPrimary) {
        table.setAttribute(PRIMARY_TABLE_ATTR, PRIMARY_TABLE_VALUE);
    } else {
        table.removeAttribute(PRIMARY_TABLE_ATTR);
    }
    return table.outerHTML;
}

export function extractSingleTableHtml(html) {
    const doc = parseHtml(html);
    const table = Array.from(doc.body.querySelectorAll('table')).find(isTopLevelTable);
    return table ? table.outerHTML : '';
}

export function tableBlockMetrics(html) {
    const doc = parseHtml(html);
    const table = Array.from(doc.body.querySelectorAll('table')).find(isTopLevelTable);
    if (!table) {
        return { data_rows: 0, columns: 0, row_count: 0, preview: '', isPrimary: false };
    }
    return {
        ...tableMetrics(table),
        preview: tablePreviewText(table),
        isPrimary: table.getAttribute(PRIMARY_TABLE_ATTR) === PRIMARY_TABLE_VALUE,
    };
}

/**
 * @param {Array<{id?: string, type?: string, content?: string}>} blocks
 * @returns {Array<{
 *   index: number,
 *   blockId: string,
 *   localIndex: number,
 *   html: string,
 *   isPrimary: boolean,
 *   data_rows: number,
 *   columns: number,
 *   preview: string,
 *   isTableBlock: boolean,
 * }>}
 */
export function listArticleTablesFromBlocks(blocks) {
    const list = [];
    let globalIndex = 0;

    (Array.isArray(blocks) ? blocks : []).forEach((block) => {
        if (!block || typeof block.content !== 'string') {
            return;
        }
        if (block.type === 'image') {
            return;
        }
        if (!/<table\b/i.test(block.content)) {
            return;
        }

        const doc = parseHtml(block.content);
        const tables = Array.from(doc.body.querySelectorAll('table')).filter(isTopLevelTable);
        const isTableBlock = block.type === 'table';

        tables.forEach((table, localIndex) => {
            // Table blocks store one table; ignore nested leftovers.
            if (isTableBlock && localIndex > 0) {
                return;
            }
            const metrics = tableMetrics(table);
            list.push({
                index: globalIndex,
                blockId: String(block.id ?? ''),
                localIndex,
                html: table.outerHTML,
                isPrimary: table.getAttribute(PRIMARY_TABLE_ATTR) === PRIMARY_TABLE_VALUE,
                data_rows: metrics.data_rows,
                columns: metrics.columns,
                preview: tablePreviewText(table) || `Table ${globalIndex + 1}`,
                isTableBlock,
            });
            globalIndex += 1;
        });
    });

    return list;
}

/**
 * @param {string} html
 * @returns {string|null} outerHTML of primary table or null
 */
export function findPrimaryTableHtml(html) {
    const doc = parseHtml(html);
    const primary = Array.from(doc.body.querySelectorAll(`table[${PRIMARY_TABLE_ATTR}="${PRIMARY_TABLE_VALUE}"]`))
        .find(isTopLevelTable);
    return primary ? primary.outerHTML : null;
}

/**
 * Set exactly one primary table by global index across blocks.
 * @returns {typeof blocks}
 */
export function setPrimaryTableInBlocks(blocks, primaryIndex) {
    const target = Number(primaryIndex);
    let globalIndex = 0;

    return (Array.isArray(blocks) ? blocks : []).map((block) => {
        if (!block || block.type === 'image' || typeof block.content !== 'string') {
            return block;
        }
        if (!/<table\b/i.test(block.content)) {
            return block;
        }

        const doc = parseHtml(block.content);
        const tables = Array.from(doc.body.querySelectorAll('table')).filter(isTopLevelTable);
        if (tables.length === 0) {
            return block;
        }

        let changed = false;
        const isTableBlock = block.type === 'table';
        tables.forEach((table, localIndex) => {
            if (isTableBlock && localIndex > 0) {
                return;
            }
            const shouldPrimary = globalIndex === target;
            const isPrimary = table.getAttribute(PRIMARY_TABLE_ATTR) === PRIMARY_TABLE_VALUE;
            if (shouldPrimary && !isPrimary) {
                table.setAttribute(PRIMARY_TABLE_ATTR, PRIMARY_TABLE_VALUE);
                changed = true;
            } else if (!shouldPrimary && isPrimary) {
                table.removeAttribute(PRIMARY_TABLE_ATTR);
                changed = true;
            }
            globalIndex += 1;
        });

        if (!changed) {
            return block;
        }

        return {
            ...block,
            content: isTableBlock
                ? (tables[0]?.outerHTML || doc.body.innerHTML)
                : doc.body.innerHTML,
        };
    });
}

/**
 * Toggle primary for a specific table block id. Clears primary on all others.
 * @returns {typeof blocks}
 */
export function setPrimaryTableByBlockId(blocks, blockId, makePrimary = true) {
    const targetId = String(blockId ?? '');
    return (Array.isArray(blocks) ? blocks : []).map((block) => {
        if (!block || block.type === 'image' || typeof block.content !== 'string') {
            return block;
        }
        if (!/<table\b/i.test(block.content)) {
            return block;
        }

        const doc = parseHtml(block.content);
        const tables = Array.from(doc.body.querySelectorAll('table')).filter(isTopLevelTable);
        if (tables.length === 0) {
            return block;
        }

        const isTarget = String(block.id ?? '') === targetId;
        let changed = false;
        const isTableBlock = block.type === 'table';

        tables.forEach((table, localIndex) => {
            if (isTableBlock && localIndex > 0) {
                return;
            }
            const shouldPrimary = makePrimary && isTarget && localIndex === 0;
            const isPrimary = table.getAttribute(PRIMARY_TABLE_ATTR) === PRIMARY_TABLE_VALUE;
            if (shouldPrimary && !isPrimary) {
                table.setAttribute(PRIMARY_TABLE_ATTR, PRIMARY_TABLE_VALUE);
                changed = true;
            } else if (!shouldPrimary && isPrimary) {
                table.removeAttribute(PRIMARY_TABLE_ATTR);
                changed = true;
            }
        });

        if (!changed) {
            return block;
        }

        return {
            ...block,
            content: isTableBlock
                ? (tables[0]?.outerHTML || doc.body.innerHTML)
                : doc.body.innerHTML,
        };
    });
}

/**
 * Replace one table (by global index) with new table HTML. Preserves primary if present on old/new.
 * @returns {typeof blocks}
 */
export function replaceTableInBlocks(blocks, tableIndex, nextTableHtml) {
    const target = Number(tableIndex);
    const nextDoc = parseHtml(nextTableHtml);
    const nextTable = Array.from(nextDoc.body.querySelectorAll('table')).find(isTopLevelTable);
    if (!nextTable) {
        return blocks;
    }

    let globalIndex = 0;

    return (Array.isArray(blocks) ? blocks : []).map((block) => {
        if (!block || block.type === 'image' || typeof block.content !== 'string') {
            return block;
        }
        if (!/<table\b/i.test(block.content)) {
            return block;
        }

        const doc = parseHtml(block.content);
        const tables = Array.from(doc.body.querySelectorAll('table')).filter(isTopLevelTable);
        let changed = false;
        const isTableBlock = block.type === 'table';

        tables.forEach((table, localIndex) => {
            if (isTableBlock && localIndex > 0) {
                return;
            }
            if (globalIndex === target) {
                const wasPrimary = table.getAttribute(PRIMARY_TABLE_ATTR) === PRIMARY_TABLE_VALUE;
                const replacement = nextTable.cloneNode(true);
                if (wasPrimary) {
                    replacement.setAttribute(PRIMARY_TABLE_ATTR, PRIMARY_TABLE_VALUE);
                } else {
                    replacement.removeAttribute(PRIMARY_TABLE_ATTR);
                }
                table.replaceWith(replacement);
                changed = true;
            }
            globalIndex += 1;
        });

        if (!changed) {
            return block;
        }

        const updatedTables = Array.from(doc.body.querySelectorAll('table')).filter(isTopLevelTable);
        return {
            ...block,
            content: isTableBlock
                ? (updatedTables[0]?.outerHTML || doc.body.innerHTML)
                : doc.body.innerHTML,
        };
    });
}

/**
 * Replace table HTML for a type=table block by id.
 * @returns {typeof blocks}
 */
export function replaceTableBlockContent(blocks, blockId, nextTableHtml) {
    const targetId = String(blockId ?? '');
    const next = extractSingleTableHtml(nextTableHtml);
    if (!next || !targetId) {
        return blocks;
    }

    return (Array.isArray(blocks) ? blocks : []).map((block) => {
        if (!block || String(block.id ?? '') !== targetId) {
            return block;
        }
        const wasPrimary = isPrimaryTableHtml(block.content);
        return {
            ...block,
            type: 'table',
            content: withPrimaryTableAttr(next, wasPrimary),
        };
    });
}

/**
 * Build a simple editable grid model from table HTML.
 * @returns {{ headers: string[], rows: string[][], hasHeader: boolean }}
 */
export function tableHtmlToGrid(tableHtml) {
    const doc = parseHtml(tableHtml);
    const table = Array.from(doc.body.querySelectorAll('table')).find(isTopLevelTable);
    if (!table) {
        return { headers: [], rows: [], hasHeader: false };
    }

    const trs = Array.from(
        table.querySelectorAll(':scope > thead > tr, :scope > tbody > tr, :scope > tr'),
    );
    const matrix = trs.map((row) =>
        Array.from(row.children)
            .filter((c) => {
                const tag = c.tagName.toLowerCase();
                return tag === 'td' || tag === 'th';
            })
            .map((c) => String(c.textContent ?? '').replace(/\s+/g, ' ').trim()),
    );

    if (matrix.length === 0) {
        return { headers: [], rows: [], hasHeader: false };
    }

    const firstHasTh = Array.from(trs[0]?.children ?? []).some(
        (c) => c.tagName?.toLowerCase() === 'th',
    );
    if (firstHasTh) {
        return {
            headers: matrix[0],
            rows: matrix.slice(1),
            hasHeader: true,
        };
    }

    return {
        headers: matrix[0].map((_, i) => `Col ${i + 1}`),
        rows: matrix,
        hasHeader: false,
    };
}

/**
 * @param {{ headers: string[], rows: string[][], hasHeader?: boolean }} grid
 * @param {{ isPrimary?: boolean }} [options]
 */
export function gridToTableHtml(grid, options = {}) {
    const headers = Array.isArray(grid?.headers) ? grid.headers : [];
    const rows = Array.isArray(grid?.rows) ? grid.rows : [];
    const hasHeader = grid?.hasHeader !== false && headers.length > 0;
    const cols = Math.max(
        headers.length,
        ...rows.map((r) => (Array.isArray(r) ? r.length : 0)),
        1,
    );

    const escape = (value) =>
        String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');

    const pad = (cells) => {
        const next = Array.isArray(cells) ? [...cells] : [];
        while (next.length < cols) {
            next.push('');
        }
        return next.slice(0, cols);
    };

    let html = `<table${options.isPrimary ? ` ${PRIMARY_TABLE_ATTR}="${PRIMARY_TABLE_VALUE}"` : ''}>`;
    if (hasHeader) {
        html += '<thead><tr>';
        pad(headers).forEach((cell) => {
            html += `<th>${escape(cell)}</th>`;
        });
        html += '</tr></thead>';
    }
    html += '<tbody>';
    rows.forEach((row) => {
        html += '<tr>';
        pad(row).forEach((cell) => {
            html += `<td>${escape(cell)}</td>`;
        });
        html += '</tr>';
    });
    html += '</tbody></table>';

    return html;
}
