export function responseToPlainText(response) {
    if (!response) return '';
    const parts = [];
    const message = String(response.message || '').trim();
    if (message) parts.push(message);

    for (const block of Array.isArray(response.blocks) ? response.blocks : []) {
        if ((block.type === 'markdown' || block.type === 'warning') && block.text) {
            const text = String(block.text).trim();
            if (text && text !== message) parts.push(text);
        } else if (block.type === 'table') {
            if (block.title) parts.push(String(block.title));
            const columns = Array.isArray(block.columns) ? block.columns : [];
            if (columns.length) {
                parts.push(columns.map((column) => column.label || column.key).join(' | '));
                for (const row of Array.isArray(block.rows) ? block.rows : []) {
                    parts.push(columns.map((column) => row[column.key] ?? '').join(' | '));
                }
            }
        } else if (block.type === 'chart' && block.title) {
            parts.push(String(block.title));
        }
    }

    return parts.join('\n\n');
}
