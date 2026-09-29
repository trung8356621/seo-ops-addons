function normalizeTextSegment(text) {
    return text
        .replace(/&#x20;/gi, ' ')
        .replace(/^(\s*)\\(#{1,6})(?=\s)/gm, '$1$2')
        .replace(/^(\s*)\\([*+-])(?=\s)/gm, '$1$2')
        .replace(/^(\s*\d+)\\\.(?=\s)/gm, '$1.')
        .replace(/\\\*\\\*([^\n]+?)\\\*\\\*/g, '**$1**')
        .replace(/https?\\:\/\//g, (m) => m.replace('\\', ''))
        .replace(/\\\[([^\n\]]+)\\\]\s*(?=[\\(])/g, '[$1]')
        .replace(/\]\s*\\\(/g, '](')
        .replace(/(https?:\/\/[^\s)]+)\\\)/g, '$1)');
}

/** Normalize only known model presentation escapes, preserving inline/fenced code verbatim. */
export function normalizeModelMarkdown(value) {
    const text = String(value ?? '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    return text.split(/(```[\s\S]*?```|`[^`\n]*`)/g)
        .map((part, index) => index % 2 === 1 ? part : normalizeTextSegment(part))
        .join('');
}

function escapeHtml(value) {
    return String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
}

export function modelMarkdownToHtml(text) {
    const normalized = normalizeModelMarkdown(text);
    const code = [];
    const escaped = escapeHtml(normalized).replace(/```(?:\w+)?\n?([\s\S]*?)```/g, (_, body) => {
        const token = `@@AGENT_CODE_${code.length}@@`;
        code.push(`<pre><code>${body}</code></pre>`);
        return token;
    });
    const inline = escaped
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
    const lines = inline.split('\n');
    const out = [];
    let list = null;
    const closeList = () => {
        if (list) out.push(`</${list}>`);
        list = null;
    };
    for (const line of lines) {
        const heading = line.match(/^(#{1,6})\s+(.+)$/);
        const unordered = line.match(/^\s*[-*]\s+(.+)$/);
        const ordered = line.match(/^\s*\d+\.\s+(.+)$/);
        if (heading) {
            closeList();
            out.push(`<h${heading[1].length}>${heading[2]}</h${heading[1].length}>`);
        } else if (unordered || ordered) {
            const nextList = unordered ? 'ul' : 'ol';
            if (list !== nextList) {
                closeList();
                out.push(`<${nextList}>`);
                list = nextList;
            }
            out.push(`<li>${(unordered || ordered)[1]}</li>`);
        } else {
            closeList();
            if (line !== '') out.push(`<p>${line}</p>`);
        }
    }
    closeList();
    return out.join('').replace(/@@AGENT_CODE_(\d+)@@/g, (_, index) => code[Number(index)] || '');
}

export function resolveWarningClass(block, sources = []) {
    const text = String(block?.text || '');
    const structuredReasons = new Set((sources || [])
        .filter((source) => source?.status === 'unavailable' && ['no_gsc_property', 'no_synced_data'].includes(source?.reason))
        .map((source) => source.reason));

    const isAvailability = Boolean(
        (block?.reason && ['no_gsc_property', 'no_synced_data'].includes(block.reason))
        || (structuredReasons.size > 0 && (
            [...structuredReasons].some((reason) => text.includes(reason))
            || /\bGSC\b|Google Search Console/i.test(text)
        ))
        || /no_gsc_property|no_synced_data/.test(text)
    );

    return isAvailability ? 'agent-availability-note' : 'agent-warning';
}

